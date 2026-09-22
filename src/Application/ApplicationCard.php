<?php
declare(strict_types=1);

namespace Broadcast\Application;

final class ApplicationCard
{
    public static function send(Store $store, int $staff, array $user): void
    {
        $id = $user['id'];
        $revision = $user['revision'];
        $rows = [];
        if ($user['status'] === 'pending') {
            foreach (['approve' => '✅ Одобрить', 'changes' => '✏️ Вернуть на исправление', 'reject' => '❌ Отклонить'] as $action => $label) {
                $rows[] = [['text' => $label, 'callback_data' => "app:$action:$id:$revision"]];
            }
        } elseif ($user['status'] === 'rejected') {
            $rows[] = [['text' => 'Разрешить повторную подачу', 'callback_data' => "app:reopen:$id:$revision"]];
        }
        $store->queueReply(
            $staff,
            "Заявка #$id · " . $user['status'] . "\n" . Messages::text('RU', 'review', [...$user, 'language' => Messages::LANGUAGES[$user['language']]]),
            ['reply_markup' => ['inline_keyboard' => $rows]],
        );
    }
}
