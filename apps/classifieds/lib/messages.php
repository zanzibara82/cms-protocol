<?php

declare(strict_types=1);

// Переписка по объявлению. Тема — это тройка «объявление + покупатель + продавец»;
// отдельной таблицы тем нет, а список кабинета собирает последние сообщения по парам.

/**
 * Отправка сообщения. Покупатель пишет только по открытому объявлению и только владельцу;
 * владелец отвечает лишь тому, с кем по этому объявлению уже есть переписка.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsMessageSend(
    string $listingId,
    string $senderId,
    string $recipientId,
    string $body,
    ?DateTimeImmutable $now = null
): array {
    $body = trim($body);

    if ($body === '') {
        return ['ok' => false, 'error' => 'Сообщение пустое — напишите пару слов.', 'notice' => ''];
    }

    if (mb_strlen($body) > CLASSIFIEDS_MESSAGE_MAX) {
        return [
            'ok' => false,
            'error' => 'Сообщение длиннее ' . CLASSIFIEDS_MESSAGE_MAX . ' символов — сократите.',
            'notice' => '',
        ];
    }

    $listing = one(
        'SELECT id, owner_id, slug, title, status, expires_at, price, currency, category_id'
        . ' FROM ' . t('classifieds_listing') . ' WHERE id = ?',
        [$listingId]
    );

    if ($listing === null) {
        return ['ok' => false, 'error' => 'Объявление не найдено — возможно, его уже удалили.', 'notice' => ''];
    }

    $ownerId = (string) $listing['owner_id'];

    if ($senderId === $ownerId) {
        if ($recipientId === $ownerId || !classifiedsMessageHasExchange($listingId, $ownerId, $recipientId)) {
            return ['ok' => false, 'error' => 'Ответить можно только тому, кто уже писал по объявлению.', 'notice' => ''];
        }
    } else {
        if ($recipientId !== $ownerId) {
            return ['ok' => false, 'error' => 'Писать можно только продавцу объявления.', 'notice' => ''];
        }

        if ((string) $listing['status'] !== 'PUBLISHED' || classifiedsListingIsExpired($listing)) {
            return ['ok' => false, 'error' => 'Объявление уже не принимает сообщения.', 'notice' => ''];
        }
    }

    if ($recipientId === '' || $recipientId === $senderId) {
        return ['ok' => false, 'error' => 'Не удалось определить получателя.', 'notice' => ''];
    }

    $recipient = one('SELECT id FROM ' . t('user') . ' WHERE id = ?', [$recipientId]);

    if ($recipient === null) {
        return ['ok' => false, 'error' => 'Получатель не найден.', 'notice' => ''];
    }

    $moment = $now ?? nowUtc();
    $messageId = newId();
    insert('classifieds_message', [
        'id' => $messageId,
        'listing_id' => $listingId,
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'body' => $body,
        'read_at' => null,
        'created_at' => utcStamp($moment),
    ]);
    classifiedsNotify(
        $recipientId,
        'message',
        'Новое сообщение по объявлению «' . (string) $listing['title'] . '»: ' . excerpt($body, CLASSIFIEDS_MESSAGE_PREVIEW),
        $listingId,
        $messageId,
        $moment
    );
    logInfo('classifieds.message_sent', ['listingId' => $listingId, 'messageId' => $messageId]);

    // Событие доски: продавец может принимать сообщения через своего приёмника.
    // Событие — только когда письмо пришло продавцу: ответ покупателю в списке событий
    // не значится. В теле — факты об объявлении и отправителе, без текста письма:
    // текст лежит в кабинете, приёмник забирает его сам.
    if ($recipientId === $ownerId) {
        $sender = one('SELECT id, name FROM ' . t('user') . ' WHERE id = ?', [$senderId]);
        classifiedsWebhookEvent('message.received', array_merge(
            classifiedsWebhookListingData($listing),
            [
                'message_id' => $messageId,
                'sender' => [
                    'id' => $senderId,
                    'name' => (string) ($sender['name'] ?? ''),
                ],
            ]
        ));
    }

    return ['ok' => true, 'error' => '', 'notice' => 'message_sent'];
}

/** Была ли переписка между владельцем и покупателем по этому объявлению. */
function classifiedsMessageHasExchange(string $listingId, string $ownerId, string $buyerId): bool
{
    return one(
        'SELECT id FROM ' . t('classifieds_message')
        . ' WHERE listing_id = ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) LIMIT 1',
        [$listingId, $ownerId, $buyerId, $buyerId, $ownerId]
    ) !== null;
}

/**
 * Сообщения темы по возрастанию. Заодно входящие от собеседника помечаются прочитанными.
 *
 * @return array{listing: array<string, mixed>, other: array{id: string, name: string}, messages: list<array<string, mixed>>}|null
 */
function classifiedsMessageThread(string $listingId, string $userId, string $otherId, ?DateTimeImmutable $now = null): ?array
{
    $listing = one(
        'SELECT id, owner_id, slug, title, status FROM ' . t('classifieds_listing') . ' WHERE id = ?',
        [$listingId]
    );

    if ($listing === null) {
        return null;
    }

    $ownerId = (string) $listing['owner_id'];

    if ($userId === $ownerId) {
        if ($otherId === '' || $otherId === $ownerId || !classifiedsMessageHasExchange($listingId, $ownerId, $otherId)) {
            return null;
        }
    } elseif ($otherId !== $ownerId) {
        return null;
    }

    $other = one('SELECT id, name FROM ' . t('user') . ' WHERE id = ?', [$otherId]);

    if ($other === null) {
        return null;
    }

    run(
        'UPDATE ' . t('classifieds_message') . ' SET read_at = ?'
        . ' WHERE listing_id = ? AND sender_id = ? AND recipient_id = ? AND read_at IS NULL',
        [utcStamp($now ?? nowUtc()), $listingId, $otherId, $userId]
    );

    $messages = all(
        'SELECT m.id, m.sender_id, m.recipient_id, m.body, m.created_at, u.name AS sender_name'
        . ' FROM ' . t('classifieds_message') . ' m'
        . ' JOIN ' . t('user') . ' u ON u.id = m.sender_id'
        . ' WHERE m.listing_id = ? AND ((m.sender_id = ? AND m.recipient_id = ?)'
        . ' OR (m.sender_id = ? AND m.recipient_id = ?))'
        . ' ORDER BY m.created_at ASC, m.id ASC',
        [$listingId, $userId, $otherId, $otherId, $userId]
    );

    return [
        'listing' => $listing,
        'other' => ['id' => (string) $other['id'], 'name' => (string) $other['name']],
        'messages' => $messages,
    ];
}

/**
 * Список тем кабинета: последнее сообщение, собеседник и число непрочитанных.
 *
 * @return list<array{listing_id: string, listing_slug: string, listing_title: string, other_id: string, other_name: string, last_body: string, last_at: string, unread: int}>
 */
function classifiedsMessageThreads(string $userId, int $limit = 200): array
{
    $rows = all(
        'SELECT m.id, m.listing_id, m.sender_id, m.recipient_id, m.body, m.read_at, m.created_at,'
        . ' l.slug AS listing_slug, l.title AS listing_title,'
        . ' su.name AS sender_name, ru.name AS recipient_name'
        . ' FROM ' . t('classifieds_message') . ' m'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = m.listing_id'
        . ' JOIN ' . t('user') . ' su ON su.id = m.sender_id'
        . ' JOIN ' . t('user') . ' ru ON ru.id = m.recipient_id'
        . ' WHERE m.sender_id = ? OR m.recipient_id = ?'
        . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . (string) max(1, $limit),
        [$userId, $userId]
    );

    $threads = [];

    foreach ($rows as $row) {
        $fromMe = (string) $row['sender_id'] === $userId;
        $otherId = $fromMe ? (string) $row['recipient_id'] : (string) $row['sender_id'];
        $key = (string) $row['listing_id'] . ':' . $otherId;

        if (!isset($threads[$key])) {
            $threads[$key] = [
                'listing_id' => (string) $row['listing_id'],
                'listing_slug' => (string) $row['listing_slug'],
                'listing_title' => (string) $row['listing_title'],
                'other_id' => $otherId,
                'other_name' => (string) ($fromMe ? $row['recipient_name'] : $row['sender_name']),
                'last_body' => (string) $row['body'],
                'last_at' => (string) $row['created_at'],
                'unread' => 0,
            ];
        }

        if (!$fromMe && $row['read_at'] === null) {
            $threads[$key]['unread']++;
        }
    }

    return array_values($threads);
}
