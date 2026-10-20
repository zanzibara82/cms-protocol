<?php

declare(strict_types=1);

// Уведомления доски: короткие записи для колокольчика. Отдельная таблица вместо
// вычисления на лету — сообщение можно прочитать, а уведомление остаётся в истории.

/** Создаёт уведомление; тело обрезается по колонке, ссылки необязательны. */
function classifiedsNotify(
    string $userId,
    string $kind,
    string $body,
    ?string $listingId = null,
    ?string $messageId = null,
    ?DateTimeImmutable $now = null
): void {
    if ($userId === '' || $body === '') {
        return;
    }

    insert('classifieds_notification', [
        'id' => newId(),
        'user_id' => $userId,
        'kind' => $kind,
        'listing_id' => $listingId,
        'message_id' => $messageId,
        'body' => mb_substr($body, 0, 500),
        'read_at' => null,
        'created_at' => utcStamp($now ?? nowUtc()),
    ]);
}

/**
 * Последние уведомления пользователя вместе с адресом объявления.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsNotificationsFor(string $userId, int $limit = 50): array
{
    return all(
        'SELECT n.id, n.kind, n.body, n.read_at, n.created_at, l.slug AS listing_slug'
        . ' FROM ' . t('classifieds_notification') . ' n'
        . ' LEFT JOIN ' . t('classifieds_listing') . ' l ON l.id = n.listing_id'
        . ' WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT ' . (string) max(1, $limit),
        [$userId]
    );
}

function classifiedsUnreadNotifications(string $userId): int
{
    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND read_at IS NULL',
        [$userId]
    );
}

/** Непрочитанные входящие сообщения: счётчик для ссылки в кабинете. */
function classifiedsUnreadMessages(string $userId): int
{
    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_message') . ' WHERE recipient_id = ? AND read_at IS NULL',
        [$userId]
    );
}

/** @return array{ok: bool, error: string, notice: string} */
function classifiedsNotificationsMarkRead(string $userId, ?DateTimeImmutable $now = null): array
{
    run(
        'UPDATE ' . t('classifieds_notification') . ' SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
        [utcStamp($now ?? nowUtc()), $userId]
    );

    return ['ok' => true, 'error' => '', 'notice' => 'notifications_read'];
}
