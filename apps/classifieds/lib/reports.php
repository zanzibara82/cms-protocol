<?php

declare(strict_types=1);

// Жалобы на объявления: покупатель выбирает причину, редактор закрывает жалобу.
// Повторная открытая жалоба того же человека на то же объявление не плодит строки.

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsReportCreate(
    string $listingId,
    string $reporterId,
    string $reason,
    string $comment,
    ?DateTimeImmutable $now = null
): array {
    if (!array_key_exists($reason, CLASSIFIEDS_REPORT_REASONS)) {
        return ['ok' => false, 'error' => 'Выберите причину жалобы из списка.', 'notice' => ''];
    }

    $listing = one(
        'SELECT l.id, l.owner_id, l.slug, l.title FROM ' . t('classifieds_listing') . ' l'
        . " WHERE l.id = ? AND l.status = 'PUBLISHED' AND " . CLASSIFIEDS_ACTIVE_SQL,
        [$listingId]
    );

    if ($listing === null) {
        return ['ok' => false, 'error' => 'Объявление недоступно — возможно, его уже сняли.', 'notice' => ''];
    }

    if ((string) $listing['owner_id'] === $reporterId) {
        return ['ok' => false, 'error' => 'На своё объявление жаловаться не нужно.', 'notice' => ''];
    }

    $comment = trim($comment);

    if (mb_strlen($comment) > CLASSIFIEDS_REPORT_COMMENT_MAX) {
        return [
            'ok' => false,
            'error' => 'Комментарий длиннее ' . CLASSIFIEDS_REPORT_COMMENT_MAX . ' символов — сократите.',
            'notice' => '',
        ];
    }

    $duplicate = one(
        'SELECT id FROM ' . t('classifieds_report')
        . " WHERE listing_id = ? AND reporter_id = ? AND status = 'NEW'",
        [$listingId, $reporterId]
    );

    if ($duplicate !== null) {
        return ['ok' => true, 'error' => '', 'notice' => 'report_duplicate'];
    }

    insert('classifieds_report', [
        'id' => newId(),
        'listing_id' => $listingId,
        'reporter_id' => $reporterId,
        'reason' => $reason,
        'comment' => $comment !== '' ? $comment : null,
        'status' => 'NEW',
        'resolver_id' => null,
        'created_at' => utcStamp($now ?? nowUtc()),
        'resolved_at' => null,
    ]);
    logInfo('classifieds.report_created', ['listingId' => $listingId, 'reason' => $reason]);

    return ['ok' => true, 'error' => '', 'notice' => 'report_sent'];
}

/** @return list<array<string, mixed>> */
function classifiedsReportQueue(): array
{
    return all(
        'SELECT r.id, r.reason, r.comment, r.status, r.created_at, r.resolved_at,'
        . ' l.id AS listing_id, l.slug AS listing_slug, l.title AS listing_title,'
        . ' u.name AS reporter_name, u.email AS reporter_email,'
        . ' ru.name AS resolver_name'
        . ' FROM ' . t('classifieds_report') . ' r'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = r.listing_id'
        . ' JOIN ' . t('user') . ' u ON u.id = r.reporter_id'
        . ' LEFT JOIN ' . t('user') . ' ru ON ru.id = r.resolver_id'
        . " ORDER BY CASE WHEN r.status = 'NEW' THEN 0 ELSE 1 END, r.created_at DESC, r.id DESC"
    );
}

function classifiedsReportNewCount(): int
{
    return (int) value('SELECT COUNT(*) FROM ' . t('classifieds_report') . " WHERE status = 'NEW'");
}

/** @return array{ok: bool, error: string, notice: string} */
function classifiedsReportResolve(
    string $id,
    string $resolverId,
    ?DateTimeImmutable $now = null
): array {
    $report = one(
        'SELECT r.id, r.reporter_id, r.status, l.slug, l.title'
        . ' FROM ' . t('classifieds_report') . ' r'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = r.listing_id'
        . ' WHERE r.id = ?',
        [$id]
    );

    if ($report === null) {
        return ['ok' => false, 'error' => 'Жалоба уже удалена — обновите список.', 'notice' => ''];
    }

    if ((string) $report['status'] !== 'NEW') {
        return ['ok' => false, 'error' => 'Эта жалоба уже рассмотрена.', 'notice' => ''];
    }

    $moment = $now ?? nowUtc();
    run(
        'UPDATE ' . t('classifieds_report') . ' SET status = ?, resolver_id = ?, resolved_at = ? WHERE id = ?',
        ['RESOLVED', $resolverId, utcStamp($moment), $id]
    );
    classifiedsNotify(
        (string) $report['reporter_id'],
        'report',
        'Жалоба на объявление «' . (string) $report['title'] . '» рассмотрена.',
        null,
        null,
        $moment
    );
    logInfo('classifieds.report_resolved', ['reportId' => $id]);

    return ['ok' => true, 'error' => '', 'notice' => 'report_resolved'];
}
