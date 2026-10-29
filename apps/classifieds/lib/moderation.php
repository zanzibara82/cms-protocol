<?php

declare(strict_types=1);

// Очередь проверки: продавец отправляет объявление, редактор публикует или отклоняет.
// Решение и причина остаются в объявлении — кабинет показывает их без отдельного журнала.

/** @return list<array<string, mixed>> */
function classifiedsModerationQueue(): array
{
    return all(
        'SELECT l.id, l.slug, l.title, l.body, l.price, l.currency, l.negotiable, l.location,'
        . ' l.image, l.status, l.submitted_at, l.created_at, l.owner_id,'
        . ' u.name AS owner_name, u.email AS owner_email,'
        . ' c.title AS category_title, c.slug AS category_slug'
        . ' FROM ' . t('classifieds_listing') . ' l'
        . ' JOIN ' . t('user') . ' u ON u.id = l.owner_id'
        . ' LEFT JOIN ' . t('classifieds_category') . ' c ON c.id = l.category_id'
        . " WHERE l.status = 'PENDING_REVIEW'"
        . ' ORDER BY l.submitted_at ASC, l.created_at ASC, l.id ASC'
    );
}

function classifiedsModerationPendingCount(): int
{
    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_listing') . " WHERE status = 'PENDING_REVIEW'"
    );
}

/**
 * Решение редактора. Одобрение ставит новый срок публикации; отказ требует причину,
 * которую увидит продавец в кабинете.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsModerationDecide(
    string $id,
    string $moderatorId,
    string $decision,
    string $note,
    ?DateTimeImmutable $now = null
): array {
    $listing = classifiedsListingById($id);

    if ($listing === null) {
        return ['ok' => false, 'error' => 'Объявление не найдено — обновите очередь.', 'notice' => ''];
    }

    if ((string) $listing['status'] !== 'PENDING_REVIEW') {
        return ['ok' => false, 'error' => 'Объявление уже не ждёт проверки — обновите очередь.', 'notice' => ''];
    }

    $note = trim($note);

    if ($decision === 'reject' && $note === '') {
        return ['ok' => false, 'error' => 'Напишите причину отказа — продавец должен её увидеть.', 'notice' => ''];
    }

    if (mb_strlen($note) > CLASSIFIEDS_MODERATION_NOTE_MAX) {
        return [
            'ok' => false,
            'error' => 'Причина длиннее ' . CLASSIFIEDS_MODERATION_NOTE_MAX . ' символов — сократите.',
            'notice' => '',
        ];
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['ok' => false, 'error' => 'Неизвестное решение проверки.', 'notice' => ''];
    }

    $moment = $now ?? nowUtc();
    $approved = $decision === 'approve';

    // Проверку «ждёт проверки» прошли двое одновременно — проигравший UPDATE обязан
    // кончиться отказом, а не вторым уведомлением и вторым событием: два письма продавцу
    // о одном решении врут. Условный UPDATE решает гонку одной инструкцией; ноль строк
    // различается перечитыванием — строку удалили или её уже решили.
    //
    // UPDATE и перечитывание обязаны жить в одной транзакции: строчный замок от UPDATE
    // держится до фиксации, и чужой DELETE или смена статуса (владелец успел снять с
    // публикации) встаёт в очередь, а не вклинивается в окно между инструкциями. Без
    // транзакции бывало и так: решение уже применено, а ответ — «не найдено» без уведомления
    // и события; либо событие уносило уже чужой статус. Уведомление и событие отправляются
    // после фиксации: медленный вебхук-транспорт не должен держать замок строки.
    $outcome = tx(static function () use ($id, $approved, $note, $moment): array {
        $rows = run(
            'UPDATE ' . t('classifieds_listing')
            . ' SET status = ?, expires_at = ?, moderation_note = ?, submitted_at = NULL, updated_at = ?'
            . " WHERE id = ? AND status = 'PENDING_REVIEW'",
            [
                $approved ? 'PUBLISHED' : 'REJECTED',
                $approved ? classifiedsListingNewExpiry($moment) : null,
                $approved ? null : $note,
                utcStamp($moment),
                $id,
            ]
        );

        // Перечитывание под тем же замком: решение уже применено (или UPDATE промахнулся),
        // чужих изменений между инструкциями быть не может — событие унесёт НОВЫЙ статус.
        return ['rows' => $rows, 'listing' => classifiedsListingById($id)];
    });

    $rows = $outcome['rows'];
    $listing = $outcome['listing'];

    if ($rows === 0) {
        if ($listing === null) {
            return ['ok' => false, 'error' => 'Объявление не найдено — обновите очередь.', 'notice' => ''];
        }

        return ['ok' => false, 'error' => 'Объявление уже не ждёт проверки — обновите очередь.', 'notice' => ''];
    }

    // Ветка последовательно недостижима: UPDATE прошёл, значит строка существует и сидит
    // под его замком до фиксации транзакции. Оставлена страховкой — если перечитывание
    // когда-нибудь вынесут из транзакции, отказ обязан вернуться к человеку, а не стать 500.
    if ($listing === null) {
        return ['ok' => false, 'error' => 'Объявление не найдено — обновите очередь.', 'notice' => ''];
    }

    $body = $approved
        ? 'Объявление «' . (string) $listing['title'] . '» прошло проверку и опубликовано.'
        : 'Объявление «' . (string) $listing['title'] . '» отклонено: ' . $note;
    classifiedsNotify(
        (string) $listing['owner_id'],
        'moderation',
        $body,
        $id,
        null,
        $moment
    );
    logInfo('classifieds.moderation_decided', [
        'listingId' => $id,
        'decision' => $decision,
        'moderatorId' => $moderatorId,
    ]);

    // Событие доски: подписчику важно знать, что объявление вышло или было отклонено.
    classifiedsWebhookEvent(
        $approved ? 'listing.published' : 'listing.rejected',
        classifiedsWebhookListingData($listing)
    );

    return [
        'ok' => true,
        'error' => '',
        'notice' => $approved ? 'moderation_approved' : 'moderation_rejected',
    ];
}
