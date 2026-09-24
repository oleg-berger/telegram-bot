<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure;

use Broadcast\Application\Store;
use Broadcast\Application\Messages;
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
        $columns = ['id', 'language', 'step', 'name', 'company', 'country', 'phone', 'subscribed', 'completed', 'choosing_language', 'email', 'status', 'submitted_at', 'approved_at', 'revision', 'edit_field'];
        $user += ['email' => '', 'status' => 'draft', 'submitted_at' => null, 'approved_at' => null, 'revision' => 0, 'edit_field' => ''];
        $values = array_map(fn ($key) => $user[$key], $columns);
        $assignments = implode(', ', array_map(fn ($key) => "$key=excluded.$key", array_slice($columns, 1)));
        $this->run('INSERT INTO users (' . implode(',', $columns) . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET ' . $assignments, $values);
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
        $users = $this->run("SELECT id,language FROM users WHERE status='approved' ORDER BY id")->fetchAll();
        foreach ($users as $user) {
            $this->job('translate', $id, null, $user['language'], [], true);
            $this->job('translate_subject', $id, null, $user['language'], [], true);
            $this->job('delivery', $id, $user['id'], $user['language'], [], true);
            $profile = $this->user($user['id']);
            $this->job('delivery', $id, $user['id'], $user['language'], ['email' => $profile['email']], true, 'email');
        }
        return ['id' => $id, 'recipients' => count($users)];
    }

    public function broadcast(int $id): ?array { return $this->one('SELECT * FROM broadcasts WHERE id=?', [$id]); }

    public function addCatchup(int $userId, string $language): bool
    {
        $broadcast = $this->one("SELECT id FROM broadcasts WHERE status='ready' ORDER BY id DESC LIMIT 1");
        if ($broadcast === null) { return false; }
        $this->job('translate', $broadcast['id'], null, $language);
        $this->job('translate_subject', $broadcast['id'], null, $language);
        $telegramAdded = $this->job('delivery', $broadcast['id'], $userId, $language);
        $emailAdded = $this->job('delivery', $broadcast['id'], $userId, $language, ['email' => $this->user($userId)['email']], false, 'email');
        if ($telegramAdded || $emailAdded) {
            $this->run('UPDATE broadcasts SET reported=0 WHERE id=?', [$broadcast['id']]);
        }
        return true;
    }

    public function contactTaken(string $field, string $value, int $exceptId): bool
    {
        if (!in_array($field, ['phone', 'email'], true)) { throw new \InvalidArgumentException('Unknown contact.'); }
        return $this->one("SELECT user_id FROM contacts WHERE $field=? AND user_id!=?", [$value, $exceptId]) !== null;
    }

    public function submitApplication(int $id): bool
    {
        $user = $this->user($id);
        if (!$user || !in_array($user['status'], ['draft', 'changes', 'unsubscribed'], true)) { return false; }
        if ($this->contactTaken('phone', $user['phone'], $id) || $this->contactTaken('email', $user['email'], $id)) { return false; }
        $this->run('INSERT INTO contacts(user_id,phone,email) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET phone=excluded.phone,email=excluded.email', [$id, $user['phone'], $user['email']]);
        $this->run("UPDATE users SET status='pending',submitted_at=COALESCE(submitted_at,?),revision=revision+1,completed=1,step='review',edit_field='' WHERE id=?", [time(), $id]);
        return true;
    }

    public function applications(?int $id = null): array
    {
        return $id === null ? $this->run("SELECT * FROM users WHERE status='pending' ORDER BY submitted_at,id LIMIT 30")->fetchAll()
            : $this->run('SELECT * FROM users WHERE id=? AND submitted_at IS NOT NULL', [$id])->fetchAll();
    }

    public function decideApplication(int $id, int $revision, string $status, int $actor): bool
    {
        if (!in_array($status, ['approved', 'changes', 'rejected', 'draft'], true)) { return false; }
        $from = $status === 'draft' ? 'rejected' : 'pending';
        return $this->run("UPDATE users SET status=?,revision=revision+1,subscribed=?,approved_at=?,edit_field='',step='review' WHERE id=? AND revision=? AND status=?", [$status, (int) ($status === 'approved'), $status === 'approved' ? time() : null, $id, $revision, $from])->rowCount() === 1;
    }

    public function unsubscribeUser(int $id): bool
    {
        // Unsubscribing stops both channels: Telegram checks subscribed, mail and audience snapshots check the status.
        return $this->run("UPDATE users SET subscribed=0,status='unsubscribed',revision=revision+1 WHERE id=? AND status='approved'", [$id])->rowCount() === 1;
    }

    public function resetTestUser(int $id): bool
    {
        // Keep the revision monotonic so old approval buttons cannot approve a new application.
        $changed = $this->run("UPDATE users SET language='RU',step='language',name='',company='',country='',phone='',email='',subscribed=0,completed=0,choosing_language=1,status='draft',submitted_at=NULL,approved_at=NULL,revision=revision+1,edit_field='' WHERE id=?", [$id])->rowCount();
        if ($changed === 0) {
            return false;
        }
        $this->run('DELETE FROM contacts WHERE user_id=?', [$id]);
        $this->run("UPDATE broadcasts SET reported=0 WHERE id IN (SELECT broadcast_id FROM jobs WHERE user_id=? AND kind='delivery' AND status IN ('pending','failed'))", [$id]);
        $this->run("UPDATE jobs SET status='skipped',payload='{}',error=NULL WHERE user_id=? AND status IN ('pending','failed')", [$id]);
        return true;
    }

    public function session(int $actor): ?array
    {
        $row = $this->one('SELECT payload FROM staff_sessions WHERE actor=?', [$actor]);
        return $row ? json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function saveSession(int $actor, ?array $session): void
    {
        if ($session === null) { $this->run('DELETE FROM staff_sessions WHERE actor=?', [$actor]); return; }
        $this->run('INSERT INTO staff_sessions(actor,payload) VALUES (?,?) ON CONFLICT(actor) DO UPDATE SET payload=excluded.payload', [$actor, json_encode($session, JSON_THROW_ON_ERROR)]);
    }

    public function createDraft(int $admin, string $text): array
    {
        $this->run('INSERT INTO drafts(admin_id,text,created_at) VALUES (?,?,?)', [$admin, $text, time()]);
        return $this->draft((int) $this->db->lastInsertId());
    }

    public function draft(int $id): ?array { return $this->one('SELECT * FROM drafts WHERE id=?', [$id]); }

    public function saveDraft(int $id, int $version, array $changes): bool
    {
        $set = [];
        $values = [];
        foreach (['text', 'subject', 'status'] as $field) {
            if (array_key_exists($field, $changes)) { $set[] = "$field=?"; $values[] = $changes[$field]; }
        }
        if (!$set) { return false; }
        $values[] = $id;
        $values[] = $version;
        return $this->run('UPDATE drafts SET ' . implode(',', $set) . ",version=version+1 WHERE id=? AND version=? AND status!='approved'", $values)->rowCount() === 1;
    }

    public function approveDraft(int $id, int $version, int $actor): ?array
    {
        $draft = $this->draft($id);
        if (!$draft || $draft['status'] !== 'pending' || $draft['version'] !== $version) { return null; }
        $broadcast = $this->createBroadcast($draft['admin_id'], $draft['text']);
        $this->run('UPDATE broadcasts SET subject=?,approved_by=? WHERE id=?', [$draft['subject'], $actor, $broadcast['id']]);
        $this->run("UPDATE drafts SET status='approved',version=version+1,broadcast_id=? WHERE id=?", [$broadcast['id'], $id]);
        return $broadcast;
    }

    public function queueComment(int $userId, string $language, string $text, string $prefix, int $staffId): void
    {
        $this->job('comment', null, $userId, $language, ['text' => $text, 'prefix' => $prefix, 'staff_id' => $staffId]);
    }

    public function queueExport(int $chatId): void { $this->job('export', null, $chatId, null, []); }

    public function exportUsers(): array { return $this->run('SELECT * FROM users WHERE submitted_at IS NOT NULL ORDER BY submitted_at,id')->fetchAll(); }

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
                j.kind IN ('message','callback','translate','comment','export') OR
                (j.kind='translate_subject' AND EXISTS (SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language=j.language)) OR
                (j.kind='delivery' AND b.status='ready' AND EXISTS (
                    SELECT 1 FROM translations t WHERE t.broadcast_id=j.broadcast_id AND t.language=j.language AND t.subject IS NOT NULL)))
            ORDER BY CASE j.kind WHEN 'callback' THEN 0 WHEN 'message' THEN 1 WHEN 'translate' THEN 2 WHEN 'translate_subject' THEN 2 ELSE 3 END, j.id LIMIT 1", [$now]);
        if ($job !== null) { $job['payload'] = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR); }
        return $job;
    }

    public function completeJob(int $id, string $status = 'done'): void { $this->run('UPDATE jobs SET status=?,error=NULL WHERE id=?', [$status, $id]); }
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

    public function translationSubject(int $id, string $language): ?string
    {
        return $this->one('SELECT subject FROM translations WHERE broadcast_id=? AND language=?', [$id, $language])['subject'] ?? null;
    }

    public function saveTranslationSubject(int $id, string $language, string $subject): void
    {
        $this->run('UPDATE translations SET subject=? WHERE broadcast_id=? AND language=?', [$subject, $id, $language]);
    }

    public function updateJobPayload(int $id, array $payload): void
    {
        $this->run('UPDATE jobs SET payload=? WHERE id=?', [json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }

    public function mailNextAt(): int
    {
        return (int) ($this->one("SELECT value FROM runtime_state WHERE name='mail_next_at'")['value'] ?? 0);
    }

    public function setMailNextAt(int $time): void
    {
        $this->run("INSERT INTO runtime_state(name,value) VALUES ('mail_next_at',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", [$time]);
    }

    public function retryAuxiliaryJob(int $jobId, int $actor, bool $superadmin): bool
    {
        $job = $this->one("SELECT * FROM jobs WHERE id=? AND kind IN ('comment','export') AND status='failed'", [$jobId]);
        if (!$job) { return false; }
        $payload = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
        $owner = $job['kind'] === 'comment' ? $payload['staff_id'] : $job['user_id'];
        if (!$superadmin && $owner !== $actor) { return false; }
        return $this->run("UPDATE jobs SET status='pending',attempts=0,next_at=0,error=NULL WHERE id=? AND status='failed'", [$jobId])->rowCount() === 1;
    }

    public function disableSubscriber(int $id): void { $this->run('UPDATE users SET subscribed=0 WHERE id=?', [$id]); }

    public function refreshBroadcasts(): void
    {
        $this->transaction(function (): void {
            $this->db->exec("UPDATE broadcasts SET status='failed' WHERE status='preparing' AND EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind IN ('translate','translate_subject') AND initial=1 AND status='failed')");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='delivery' AND status='pending' AND (EXISTS (SELECT 1 FROM broadcasts WHERE id=jobs.broadcast_id AND status='failed') OR EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language=jobs.language AND t.kind IN ('translate','translate_subject') AND t.status='failed'))");
            $this->db->exec("UPDATE jobs SET status='failed',error='translation_failed' WHERE kind='translate_subject' AND status='pending' AND EXISTS (SELECT 1 FROM jobs t WHERE t.broadcast_id=jobs.broadcast_id AND t.language=jobs.language AND t.kind='translate' AND t.status='failed')");
            $this->db->exec("UPDATE broadcasts SET status='ready' WHERE status='preparing' AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=broadcasts.id AND kind IN ('translate','translate_subject') AND initial=1 AND status!='done')");
            $finished = $this->run("SELECT * FROM broadcasts b WHERE reported=0 AND status IN ('ready','failed') AND NOT EXISTS (SELECT 1 FROM jobs WHERE broadcast_id=b.id AND report_included=1 AND status='pending')")->fetchAll();
            foreach ($finished as $broadcast) {
                $report = Messages::text('RU', 'broadcast_report', ['id' => $broadcast['id']]);
                foreach (['telegram', 'email'] as $channel) {
                    $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];
                    foreach ($this->run("SELECT status,COUNT(*) AS total FROM jobs WHERE broadcast_id=? AND kind='delivery' AND report_included=1 AND channel=? GROUP BY status", [$broadcast['id'], $channel])->fetchAll() as $row) {
                        $counts[$row['status']] = (int) $row['total'];
                    }
                    $report .= "\n" . Messages::text('RU', 'broadcast_report_' . $channel, $counts);
                }
                if ($broadcast['approved_by']) {
                    $this->queueReply($broadcast['approved_by'], $report);
                }
                $this->run('UPDATE broadcasts SET reported=1 WHERE id=?', [$broadcast['id']]);
            }
        });
    }

    private function job(string $kind, ?int $broadcastId, ?int $userId, ?string $language, array $payload = [], bool $initial = false, string $channel = 'telegram'): bool
    {
        // Every delivery waits in the report, including catch-up sends; auxiliary jobs follow the initial snapshot.
        return $this->run('INSERT OR IGNORE INTO jobs(kind,broadcast_id,user_id,language,payload,initial,report_included,channel) VALUES (?,?,?,?,?,?,?,?)', [$kind, $broadcastId, $userId, $language, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $initial, (int) ($initial || $kind === 'delivery'), $channel])->rowCount() === 1;
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
