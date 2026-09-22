<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\TextParts;

final class Staff
{
    public function __construct(private Store $store, private array $superadmins, private bool $adminsCanApprove, private bool $adminsCanExport) {}

    private function super(int $id): bool
    {
        return in_array($id, $this->superadmins, true);
    }

    private function approveUsers(int $id): bool
    {
        return $this->super($id) || $this->adminsCanApprove;
    }

    private function reply(int $id, string $text, array $buttons = []): void
    {
        $parts = TextParts::split($text);
        foreach ($parts as $i => $part) {
            $this->store->queueReply($id, $part, $i === count($parts) - 1 && $buttons ? ['reply_markup' => ['inline_keyboard' => $buttons]] : []);
        }
    }

    public function message(int $id, array $message): void
    {
        $text = trim($message['text'] ?? '');
        if ($text === '' || !isset($message['text'])) {
            $this->reply($id, 'Поддерживается только обычный текст. Вложения не рассылаются.');
            return;
        }
        if (str_starts_with($text, '/')) { $this->command($id, $text); return; }
        $session = $this->store->session($id);
        if ($session && str_starts_with($session['kind'], 'app:')) { $this->applicationComment($id, $session, $text); return; }
        if ($session && $session['kind'] === 'draft:return') {
            if (!$this->super($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
            $draft = $this->store->draft($session['id']);
            if (!$draft || $draft['status'] !== 'pending' || $draft['version'] !== $session['version']) { $this->stale($id); return; }
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'changes']);
            $this->reply($draft['admin_id'], '✏️ Объявление #' . $draft['id'] . " возвращено на доработку.\n" . $text);
            $this->preview($draft['admin_id'], $this->store->draft($draft['id']));
            $this->store->saveSession($id, null);
            $this->reply($id, 'Комментарий отправлен автору.');
            return;
        }
        if ($session && in_array($session['kind'], ['draft:subject', 'draft:text'], true)) {
            $draft = $this->store->draft($session['id']);
            if (!$draft || $draft['admin_id'] !== $id || !in_array($draft['status'], ['draft', 'changes'], true) || $draft['version'] !== $session['version']) { $this->stale($id); return; }
            $field = $session['kind'] === 'draft:subject' ? 'subject' : 'text';
            if ($field === 'subject' && (mb_strlen($text) > 200 || preg_match('/[\r\n]/', $text))) {
                $this->reply($id, 'Укажите тему одной строкой, до 200 символов.');
                return;
            }
            $this->store->saveDraft($draft['id'], $draft['version'], [$field => $text]);
            $this->store->saveSession($id, null);
            $this->preview($id, $this->store->draft($draft['id']));
            return;
        }
        $draft = $this->store->createDraft($id, $text);
        $this->store->saveSession($id, ['kind' => 'draft:subject', 'id' => $draft['id'], 'version' => $draft['version']]);
        $this->reply($id, '✉️ Черновик #' . $draft['id'] . " сохранён. Введите тему письма одной строкой (до 200 символов).\n/cancel — отменить черновик");
    }

    private function command(int $id, string $text): void
    {
        if (preg_match('/^\/retryjob\s+([1-9][0-9]*)$/D', $text, $match)) {
            if (!$this->approveUsers($id) && !$this->adminsCanExport) { $this->reply($id, 'Недостаточно прав.'); return; }
            $this->reply($id, $this->store->retryAuxiliaryJob((int) $match[1], $id, $this->super($id)) ? 'Задание поставлено на повтор.' : 'Задание недоступно или не содержит ошибки.');
            return;
        }
        if (preg_match('/^\/unsubscribe\s+([1-9][0-9]*)$/D', $text, $match)) {
            if (!$this->super($id)) { $this->reply($id, 'Отписка пользователей доступна только суперадминистратору.'); return; }
            $this->reply($id, $this->store->unsubscribeUser((int) $match[1]) ? 'Пользователь отписан от рассылок в Telegram и на почте.' : 'Пользователь не найден среди одобренных.');
            return;
        }
        if (preg_match('/^\/userdata$/iD', $text)) {
            if (!$this->super($id) && !$this->adminsCanExport) { $this->reply($id, 'Выгрузка доступна только суперадминистратору.'); return; }
            $this->store->queueExport($id);
            $this->reply($id, '📊 Готовим Excel с общей базой отправленных заявок.');
            return;
        }
        if (preg_match('/^\/requests(?:\s+([1-9][0-9]*))?$/D', $text, $match)) {
            if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав для рассмотрения заявок.'); return; }
            $users = $this->store->applications(isset($match[1]) ? (int) $match[1] : null);
            if (!$users) { $this->reply($id, 'Заявок не найдено.'); }
            foreach ($users as $user) { ApplicationCard::send($this->store, $id, $user); }
            if (count($users) === 30) { $this->reply($id, 'Показаны первые 30 заявок. После рассмотрения вызовите /requests ещё раз. Поиск: /requests ID.'); }
            return;
        }
        if (preg_match('/^\/retry\s+([1-9][0-9]*)$/D', $text, $match)) {
            $broadcast = $this->store->broadcast((int) $match[1]);
            if (!$broadcast || !$broadcast['approved_by'] || (!$this->super($id) && $broadcast['admin_id'] !== $id)) {
                $this->reply($id, 'Рассылка недоступна или ещё не одобрена.');
                return;
            }
            $this->reply($id, $this->store->retryBroadcast($broadcast['id']) ? 'Неуспешные задания поставлены на повтор.' : 'Неуспешных заданий нет.');
            return;
        }
        if ($text === '/cancel') {
            $session = $this->store->session($id);
            if ($session && in_array($session['kind'], ['draft:subject', 'draft:text'], true)) {
                $draft = $this->store->draft($session['id']);
                if ($draft && $draft['admin_id'] === $id && in_array($draft['status'], ['draft', 'changes'], true)) {
                    $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'cancelled']);
                }
            }
            $this->store->saveSession($id, null);
            $this->reply($id, 'Текущее действие отменено.');
            return;
        }
        if (preg_match('/^\/draft\s+([1-9][0-9]*)$/D', $text, $match)) {
            $draft = $this->store->draft((int) $match[1]);
            if ($draft && ($draft['admin_id'] === $id || $this->super($id))) { $this->preview($id, $draft); } else { $this->reply($id, 'Черновик недоступен.'); }
            return;
        }
        $help = "📝 Отправьте текст объявления, затем тему письма. Рассылка начнётся только после одобрения суперадминистратором.\n/draft ID — открыть черновик\n/retry ID — повторить ошибки рассылки\n/cancel — отменить ввод\n/help — помощь";
        if ($this->approveUsers($id)) { $help .= "\n/requests [ID] — заявки пользователей"; }
        if ($this->super($id) || $this->adminsCanExport) { $help .= "\n/userdata — общая база Excel"; }
        if ($this->approveUsers($id) || $this->adminsCanExport) { $help .= "\n/retryjob ID — повторить ошибку комментария или Excel"; }
        if ($this->super($id)) { $help .= "\n/unsubscribe ID — отписать пользователя"; }
        $this->reply($id, $help);
    }

    public function callback(int $id, string $data): void
    {
        if (preg_match('/^app:(approve|changes|reject|reopen):([1-9][0-9]*):([0-9]+)$/D', $data, $match)) {
            if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
            [$all, $action, $userId, $revision] = $match;
            $user = $this->store->user((int) $userId);
            $expected = $action === 'reopen' ? 'rejected' : 'pending';
            if (!$user || $user['revision'] !== (int) $revision || $user['status'] !== $expected) { $this->stale($id); return; }
            if (in_array($action, ['changes', 'reject'], true)) {
                $this->store->saveSession($id, ['kind' => 'app:' . $action, 'id' => (int) $userId, 'revision' => (int) $revision]);
                $this->reply($id, 'Укажите причину. Комментарий будет переведён на язык пользователя. /cancel — отменить.');
                return;
            }
            $status = $action === 'approve' ? 'approved' : 'draft';
            if ($this->store->decideApplication((int) $userId, (int) $revision, $status, $id)) {
                $this->store->queueReply((int) $userId, Messages::text($user['language'], $action === 'approve' ? 'approved' : 'reopened', $user));
                if ($action === 'approve' && !$this->store->addCatchup((int) $userId, $user['language'])) {
                    $this->store->queueReply((int) $userId, Messages::text($user['language'], 'empty'));
                }
                $this->reply($id, $action === 'approve' ? '✅ Пользователь одобрен.' : 'Повторная подача разрешена.');
            }
            return;
        }
        if (!preg_match('/^draft:(submit|approve|return|text|subject|cancel):([1-9][0-9]*):([0-9]+)$/D', $data, $match)) { return; }
        [$all, $action, $draftId, $version] = $match;
        $draft = $this->store->draft((int) $draftId);
        if (!$draft || $draft['version'] !== (int) $version) { $this->stale($id); return; }
        if (in_array($action, ['approve', 'return'], true)) {
            if (!$this->super($id)) { $this->reply($id, 'Только суперадминистратор может согласовать рассылку.'); return; }
            if ($draft['status'] !== 'pending') { $this->stale($id); return; }
            if ($action === 'return') {
                $this->store->saveSession($id, ['kind' => 'draft:return', 'id' => $draft['id'], 'version' => $draft['version']]);
                $this->reply($id, 'Напишите обязательный комментарий для автора. /cancel — отменить.');
                return;
            }
            $broadcast = $this->store->approveDraft($draft['id'], $draft['version'], $id);
            if ($broadcast) {
                foreach (array_unique([$id, $draft['admin_id']]) as $staff) {
                    $this->reply($staff, '✅ Рассылка #' . $broadcast['id'] . ' одобрена. Получателей: ' . $broadcast['recipients'] . '. Готовим переводы.');
                }
            }
            return;
        }
        if ($draft['admin_id'] !== $id || !in_array($draft['status'], ['draft', 'changes'], true)) {
            $this->reply($id, 'Изменение недоступно. Дождитесь возврата на доработку.');
            return;
        }
        if ($action === 'submit') {
            if (trim($draft['subject']) === '') { $this->reply($id, 'Сначала укажите тему письма.'); return; }
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'pending']);
            $this->store->saveSession($id, null);
            foreach ($this->superadmins as $super) { $this->preview($super, $this->store->draft($draft['id'])); }
            $this->reply($id, '📨 Объявление отправлено на согласование.');
        } elseif ($action === 'cancel') {
            $this->store->saveDraft($draft['id'], $draft['version'], ['status' => 'cancelled']);
            $this->store->saveSession($id, null);
            $this->reply($id, 'Черновик отменён.');
        } else {
            $this->store->saveSession($id, ['kind' => 'draft:' . $action, 'id' => $draft['id'], 'version' => $draft['version']]);
            $this->reply($id, $action === 'text' ? 'Введите новый текст объявления.' : 'Введите тему письма одной строкой (до 200 символов).');
        }
    }

    private function applicationComment(int $id, array $session, string $text): void
    {
        if (!$this->approveUsers($id)) { $this->reply($id, 'Недостаточно прав.'); return; }
        $user = $this->store->user($session['id']);
        $status = $session['kind'] === 'app:reject' ? 'rejected' : 'changes';
        if (!$user || !$this->store->decideApplication($user['id'], $session['revision'], $status, $id)) { $this->stale($id); return; }
        $this->store->queueComment($user['id'], $user['language'], $text, Messages::text($user['language'], $status), $id);
        $this->store->saveSession($id, null);
        $this->reply($id, 'Решение сохранено. Комментарий поставлен на перевод и доставку.');
    }

    private function preview(int $id, array $draft): void
    {
        $rows = [];
        $suffix = $draft['id'] . ':' . $draft['version'];
        if ($draft['status'] === 'pending' && $this->super($id)) {
            $actions = ['approve' => '✅ Одобрить и разослать', 'return' => '✏️ Вернуть на доработку'];
        } elseif ($draft['admin_id'] === $id && in_array($draft['status'], ['draft', 'changes'], true)) {
            $actions = ['submit' => '📨 На согласование', 'text' => '✏️ Изменить текст', 'subject' => '✏️ Изменить тему', 'cancel' => 'Отменить черновик'];
        } else {
            $actions = [];
        }
        foreach ($actions as $action => $label) { $rows[] = [['text' => $label, 'callback_data' => 'draft:' . $action . ':' . $suffix]]; }
        $this->reply($id, 'Объявление #' . $draft['id'] . ' · ' . $draft['status'] . "\nТема: " . $draft['subject'] . "\n\n" . $draft['text'], $rows);
    }

    private function stale(int $id): void
    {
        $this->store->saveSession($id, null);
        $this->reply($id, 'Это действие устарело или уже выполнено. Откройте актуальную заявку /requests ID или черновик /draft ID.');
    }
}
