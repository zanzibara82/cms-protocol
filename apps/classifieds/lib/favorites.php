<?php

declare(strict_types=1);

/**
 * Добавление в избранное. Повторное нажатие не ошибка: INSERT IGNORE сохраняет
 * первый момент добавления и не плодит дубли.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsFavoriteAdd(string $userId, string $listingId, ?DateTimeImmutable $now = null): array
{
    $listing = classifiedsListingById($listingId);

    if ($listing === null || (string) $listing['status'] !== 'PUBLISHED' || classifiedsListingIsExpired($listing, $now)) {
        return ['ok' => false, 'error' => 'Объявление недоступно — возможно, срок публикации истёк.', 'notice' => ''];
    }

    run(
        'INSERT IGNORE INTO ' . t('classifieds_favorite') . ' (user_id, listing_id, created_at) VALUES (?, ?, ?)',
        [$userId, $listingId, utcStamp($now ?? nowUtc())]
    );
    logInfo('classifieds.favorite_added', ['listingId' => $listingId, 'userId' => $userId]);

    return ['ok' => true, 'error' => '', 'notice' => 'favorite_added'];
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function classifiedsFavoriteRemove(string $userId, string $listingId): array
{
    run(
        'DELETE FROM ' . t('classifieds_favorite') . ' WHERE user_id = ? AND listing_id = ?',
        [$userId, $listingId]
    );
    logInfo('classifieds.favorite_removed', ['listingId' => $listingId, 'userId' => $userId]);

    return ['ok' => true, 'error' => '', 'notice' => 'favorite_removed'];
}

function classifiedsIsFavorite(string $userId, string $listingId): bool
{
    return one(
        'SELECT 1 AS found FROM ' . t('classifieds_favorite') . ' WHERE user_id = ? AND listing_id = ?',
        [$userId, $listingId]
    ) !== null;
}

function classifiedsFavoriteCount(string $userId): int
{
    return (int) value(
        'SELECT COUNT(*) FROM ' . t('classifieds_favorite') . ' WHERE user_id = ?',
        [$userId]
    );
}

/**
 * Избранное показывает только доступные объявления: истёкшие и снятые не исчезают
 * из базы, но в списке не появляются.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsFavoriteListings(string $userId): array
{
    return all(
        'SELECT ' . CLASSIFIEDS_CARD_COLUMNS
        . ' FROM ' . t('classifieds_favorite') . ' f'
        . ' JOIN ' . t('classifieds_listing') . ' l ON l.id = f.listing_id'
        . " WHERE f.user_id = ? AND l.status = 'PUBLISHED' AND " . CLASSIFIEDS_ACTIVE_SQL
        . ' ORDER BY f.created_at DESC, l.id DESC',
        [$userId]
    );
}
