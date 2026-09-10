<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure;

use Broadcast\Application\Store;
use PDO;
use Throwable;

final class SqliteStore implements Store
{
    private PDO $db;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            if (!mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot create database directory.');
            }
        }
        $this->db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    }

    public function migrate(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY)');
            foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
                $version = basename($file);
                if (!$this->one('SELECT version FROM migrations WHERE version = ?', [$version])) {
                    $this->db->exec(file_get_contents($file));
                    $this->run('INSERT INTO migrations(version) VALUES (?)', [$version]);
                }
            }
        });
    }

    public function transaction(callable $operation): mixed
    {
        // Acquire the write lock before reading state; polling and sending use separate connections.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $exception) {
            $this->db->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function acceptUpdate(int $id): bool
    {
        return $this->run('INSERT OR IGNORE INTO processed_updates(id) VALUES (?)', [$id])->rowCount() === 1;
    }

    public function offset(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(id) + 1, 0) FROM processed_updates')->fetchColumn();
    }

    public function user(int $id): ?array { return $this->one('SELECT * FROM users WHERE id = ?', [$id]); }

    public function saveUser(array $user): void
    {
        $columns = ['id', 'language', 'step', 'name', 'company', 'country', 'phone', 'subscribed', 'completed', 'choosing_language'];
        $values = array_map(fn ($key) => $user[$key], $columns);
        $assignments = implode(', ', array_map(fn ($key) => "$key=excluded.$key", array_slice($columns, 1)));
        $this->run('INSERT INTO users (' . implode(',', $columns) . ') VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET ' . $assignments, $values);
    }

    public function queueReply(int $chatId, string $text, array $options = []): void
    {
        $this->job('message', null, $chatId, null, ['text' => $text, 'options' => $options]);
    }

    public function queueCallback(string $id): void { $this->job('callback', null, null, null, ['id' => $id]); }

    public function createBroadcast(int $adminId, string $text): array
    {
        $this->run('INSERT INTO broadcasts(admin_id,text,created_at) VALUES (?,?,?)', [$adminId, $text, time()]);
        $id = (int) $this->db->lastInsertId();
        $users = $this->run('SELECT id,language FROM users WHERE completed=1 AND subscribed=1 ORDER BY id')->fetchAll();
        foreach ($users as $user) {
            $this->job('translate', $id, null, $user['language'], [], true);
            $this->job('delivery', $id, $user['id'], $user['language'], [], true);
        }
        return ['id' => $id, 'recipients' => count($users)];
    }

    public function broadcast(int $id): ?array { return $this->one('SELECT * FROM broadcasts WHERE id=?', [$id]); }

    public function addCatchup(int $userId, string $language): void
    {
        $broadcast = $this->one("SELECT id FROM broadcasts WHERE status='ready' ORDER BY id DESC LIMIT 1");
        if ($broadcast === null) { return; }
        $this->job('translate', $broadcast['id'], null, $language);
        $this->job('delivery', $broadcast['id'], $userId, $language);
    }

    public function retryBroadcast(int $id): bool
    {
        $broadcast = $this->broadcast($id);
        if ($broadcast === null) { return false; }
        $changed = $this->run("UPDATE jobs SET status='pending', attempts=0, next_at=0, error=NULL WHERE broadcast_id=? AND status='failed'", [$id])->rowCount();
        if ($changed === 0) { return false; }
        $this->run("UPDATE broadcasts SET status=CASE WHEN status='failed' THEN 'preparing' ELSE status END, reported=0 WHERE id=?", [$id]);
        return true;
    }

    public function nextJob(int $now): ?array
    {
        // Network calls run outside transactions. The process lock guarantees one worker.
        $job = $this->one("SELECT j.* FROM jobs j LEFT JOIN broadcasts b ON b.id=j.broadcast_id
            WHERE j.status='pending' AND j.next_at<=? AND (
                j.kind IN ('message','callback','translate') OR
                (j.kind='delivery' AND b.status='ready' AND EXISTS (
                    SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language=j.language)))
            ORDER BY CASE j.kind WHEN 'callback' THEN 0 WHEN 'message' THEN 1 WHEN 'translate' THEN 2 ELSE 3 END, j.id LIMIT 1", [$now]);
        if ($job !== null) { $job['payload'] = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR); }
        return $job;
    }

    public function completeJob(int $id, string $status = 'done'): void { $this->run('UPDATE jobs SET status=? WHERE id=?', [$status, $id]); }
    public function advancePart(int $id): void { $this->run('UPDATE jobs SET part=part+1,attempts=0,next_at=0 WHERE id=?', [$id]); }

    public function deferJob(int $id, int $attempts, int $nextAt, string $error, bool $failed): void
    {
        $this->run('UPDATE jobs SET attempts=?,next_at=?,error=?,status=? WHERE id=?', [$attempts, $nextAt, $error, $failed ? 'failed' : 'pending', $id]);
    }

    public function translation(int $broadcastId, string $language): ?string
    {
        return $this->one('SELECT text FROM translations WHERE broadcast_id=? AND language=?', [$broadcastId, $language])['text'] ?? null;
    }

    public function saveTranslation(int $broadcastId, string $language, string $text): void
    {
        $this->run('INSERT INTO translations(broadcast_id,language,text) VALUES (?,?,?) ON CONFLICT DO NOTHING', [$broadcastId, $language, $text]);
    }

    public function disableSubscriber(int $id): void { $this->run('UPDATE users SET subscribed=0 WHERE id=?', [$id]); }

    public function refreshBroadcasts(): void
    {
        $this->transaction(function (): void {
            $this->db->exec("UPDATE broadcasts SET status='failed' WHERE status='preparing' AND EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind='translate' AND initial=1 AND status='failed')");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='delivery' AND status='pending' AND (EXISTS (SELECT 1 FROM broadcasts WHERE id=jobs.broadcast_id AND status='failed') OR EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language=jobs.language AND t.kind='translate' AND t.status='failed'))");
            $this->db->exec("UPDATE broadcasts SET status='ready' WHERE status='preparing' AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind='translate' AND initial=1 AND status!='done')");
            $finished = $this->run("SELECT * FROM broadcasts b WHERE reported=0 AND status IN ('ready','failed') AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=b.id AND initial=1 AND status='pending')")->fetchAll();
            foreach ($finished as $broadcast) {
                $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];
                foreach ($this->run("SELECT status,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='delivery' AND initial=1 GROUP BY status", [$broadcast['id']])->fetchAll() as $row) {
                    $counts[$row['status']] = (int) $row['total'];
                }
                $this->queueReply($broadcast['admin_id'], sprintf('Рассылка #%d: отправлено %d, пропущено %d, ошибки %d.', $broadcast['id'], $counts['done'], $counts['skipped'], $counts['failed']));
                $this->run('UPDATE broadcasts SET reported=1 WHERE id=?', [$broadcast['id']]);
            }
        });
    }

    private function job(string $kind, ?int $broadcastId, ?int $userId, ?string $language, array $payload = [], bool $initial = false): void
    {
        $this->run('INSERT OR IGNORE INTO jobs(kind,broadcast_id,user_id,language,payload,initial) VALUES (?,?,?,?,?,?)', [$kind, $broadcastId, $userId, $language, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $initial]);
    }

    private function run(string $sql, array $values = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function one(string $sql, array $values = []): ?array
    {
        $row = $this->run($sql, $values)->fetch();
        return $row === false ? null : $row;
    }
}
