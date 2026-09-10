<?php
declare(strict_types=1);

namespace Broadcast\Application;

final class Kernel
{
    public function __construct(private Store $store, private array $adminIds) {}

    public function handle(array $update): void
    {
        if (!isset($update['update_id']) || !is_int($update['update_id'])) { return; }
        // Commit the update receipt and all resulting jobs together, before polling advances its offset.
        $this->store->transaction(function () use ($update): void {
            if (!$this->store->acceptUpdate($update['update_id'])) { return; }
            if (isset($update['callback_query'])) { $this->callback($update['callback_query']); return; }
            $message = $update['message'] ?? null;
            if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private') { return; }
            $id = (int) ($message['from']['id'] ?? 0);
            if ($id <= 0 || (int) $message['chat']['id'] !== $id) { return; }
            if (in_array($id, $this->adminIds, true)) { $this->administrator($id, $message); return; }
            $this->subscriber($id, $message);
        });
    }

    private function administrator(int $id, array $message): void
    {
        $text = $message['text'] ?? '';
        if ($text === '' || !is_string($text)) {
            $this->store->queueReply($id, 'Поддерживается только обычный текст. Вложения и подписи не рассылаются.');
            return;
        }
        if (str_starts_with(ltrim($text), '/')) {
            if (preg_match('/^\/retry\s+([1-9][0-9]*)\s*$/D', $text, $match)) {
                $retried = $this->store->retryBroadcast((int) $match[1]);
                $this->store->queueReply($id, $retried ? 'Неуспешные задания поставлены на повтор.' : 'Рассылка не найдена или нет неуспешных заданий.');
            } else {
                $this->store->queueReply($id, "Режим администратора. Любой обычный текст автоматически рассылается подписчикам.\n/retry ID — повторить неуспешные задания\n/help — помощь");
            }
            return;
        }
        if (trim($text) === '') { return; }
        $broadcast = $this->store->createBroadcast($id, $text);
        $this->store->queueReply($id, sprintf('Рассылка #%d принята. Получателей: %d.', $broadcast['id'], $broadcast['recipients']));
    }

    private function subscriber(int $id, array $message): void
    {
        $text = trim($message['text'] ?? '');
        $user = $this->store->user($id);
        if ($user === null) {
            if ($text !== '/start') {
                $this->store->queueReply($id, '/start — Русский / English / Español / Français');
                return;
            }
            $user = ['id' => $id, 'language' => 'RU', 'step' => 'language', 'name' => '', 'company' => '', 'country' => '', 'phone' => '', 'subscribed' => 0, 'completed' => 0, 'choosing_language' => 1];
            $this->store->saveUser($user);
        }
        if ($text === '/start') {
            if ($user['completed']) {
                $user['subscribed'] = 1;
                $this->store->saveUser($user);
                $this->reply($user, 'active');
            } else { $this->prompt($user); }
            return;
        }
        if ($text === '/stop') {
            $user['subscribed'] = 0;
            $this->store->saveUser($user);
            $this->reply($user, 'stopped');
            return;
        }
        if ($text === '/language') {
            $user['choosing_language'] = 1;
            $this->store->saveUser($user);
            $this->reply($user, 'language', Messages::languageKeyboard());
            return;
        }
        if (str_starts_with($text, '/') || $user['completed']) { $this->reply($user, 'help'); return; }
        if ($user['step'] === 'language') { $this->prompt($user); return; }
        if ($user['step'] === 'phone') {
            $contact = $message['contact'] ?? null;
            if ($contact !== null) {
                if (($contact['user_id'] ?? null) !== $id) { $this->reply($user, 'invalid'); return; }
                $text = '+' . ltrim((string) $contact['phone_number'], '+');
            }
            $phone = preg_replace('/[\s()\-]/u', '', $text);
            if (!preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone)) { $this->reply($user, 'invalid'); return; }
            $user['phone'] = $phone;
            $user['step'] = 'complete'; $user['completed'] = 1; $user['subscribed'] = 1;
            $this->store->saveUser($user);
            $this->reply($user, 'welcome', ['reply_markup' => ['remove_keyboard' => true]]);
            $this->store->addCatchup($id, $user['language']);
            return;
        }
        if ($text === '' || mb_strlen($text) > 200) { $this->reply($user, 'invalid'); return; }
        $user[$user['step']] = $text;
        $user['step'] = ['name' => 'company', 'company' => 'country', 'country' => 'phone'][$user['step']];
        $this->store->saveUser($user);
        $this->prompt($user);
    }

    private function callback(array $callback): void
    {
        if (($callback['message']['chat']['type'] ?? '') !== 'private') { return; }
        $id = (int) ($callback['from']['id'] ?? 0);
        if ($id <= 0 || (int) $callback['message']['chat']['id'] !== $id) { return; }
        $this->store->queueCallback((string) $callback['id']);
        if (in_array($id, $this->adminIds, true)) { return; }
        $user = $this->store->user($id);
        $data = $callback['data'] ?? '';
        if ($user === null || !$user['choosing_language'] || !str_starts_with($data, 'language:')) { return; }
        $language = substr($data, strlen('language:'));
        if (!isset(Messages::LANGUAGES[$language])) { return; }
        $user['language'] = $language;
        $user['choosing_language'] = 0;
        if ($user['step'] === 'language') { $user['step'] = 'name'; }
        $this->store->saveUser($user);
        if ($user['completed']) { $this->reply($user, 'changed'); }
        else { $this->prompt($user); }
    }

    private function prompt(array $user): void
    {
        $options = [];
        if ($user['step'] === 'language') { $options = Messages::languageKeyboard(); }
        if ($user['step'] === 'phone') {
            $options = ['reply_markup' => ['keyboard' => [[['text' => Messages::text($user['language'], 'contact'), 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true]];
        }
        $this->reply($user, $user['step'], $options);
    }

    private function reply(array $user, string $key, array $options = []): void
    {
        $this->store->queueReply($user['id'], Messages::text($user['language'], $key), $options);
    }
}
