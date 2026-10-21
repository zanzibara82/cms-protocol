<?php

declare(strict_types=1);

// JSON-выдача доски (C6): опубликованные объявления и карточка объявления. Только
// чтение и только то, что и так видно на витрине: персональное (переписка, избранное,
// кабинет) в API не попадает, поэтому ключ не нужен — читать витринные цены посторонний
// может и страницей каталога.

/** Сколько объявлений отдаёт одна порция. */
const CLASSIFIEDS_API_PAGE_SIZE = 20;

/**
 * Порция объявлений для потока. Отметка «after» — дата создания последней строки
 * прошлого ответа: следующая порция берётся объявлениями, старше отметки. Мусор в
 * отметке снимается: отвечает первая порция, без пустой выборки.
 *
 * @return list<array<string, mixed>>
 */
function classifiedsApiListingList(string $after): array
{
    $where = [];
    $args = [];

    if (fromDb($after) !== null) {
        $where[] = 'l.created_at < ?';
        $args[] = $after;
    }

    $where[] = "l.status = 'PUBLISHED'";
    // Объявление, закрытое от индексации, в выдачу не идёт: иначе API было бы обходным
    // путём к тому, что владелец просил не показывать.
    $where[] = 'l.noindex = 0';
    $where[] = CLASSIFIEDS_ACTIVE_SQL;

    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT ' . CLASSIFIEDS_CARD_COLUMNS . ', l.views_count, l.views_public'
        . ' FROM ' . t('classifieds_listing') . ' l'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . (string) CLASSIFIEDS_API_PAGE_SIZE,
        $args
    );

    return $rows;
}

/**
 * Строка выдачи: карточные поля без потрохов. Описание и характеристики в список не
 * идут — список читают быстро, карточку запрашивают отдельно.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function classifiedsApiListingLine(array $row): array
{
    $line = [
        'id' => (string) $row['id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'price' => $row['price'] === null ? null : (float) $row['price'],
        'currency' => (string) $row['currency'],
        'negotiable' => (int) $row['negotiable'] === 1,
        'location' => (string) ($row['location'] ?? ''),
        'image' => (string) ($row['image'] ?? ''),
        'url' => absoluteUrl(classifiedsListingHref((string) $row['slug'])),
        // Отметка остаётся в том же штампе, что в базе (UTC): поток «after» прочитает
        // её обратно одной строкой без перевода поясов.
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];

    // Счётчик просмотров отдаём, только если владелец разрешил его показывать.
    if ((int) ($row['views_public'] ?? 0) === 1) {
        $line['views'] = (int) ($row['views_count'] ?? 0);
    }

    return $line;
}

/**
 * Карточка объявления: строка списка плюс описание и характеристики.
 *
 * @param array<string, mixed> $listing
 * @return array<string, mixed>
 */
function classifiedsApiListingCard(array $listing): array
{
    $card = classifiedsApiListingLine($listing);
    $card['body'] = (string) ($listing['body'] ?? '');
    $card['attributes'] = array_map(
        static fn (array $attribute): array => [
            'title' => (string) $attribute['title'],
            'value' => (string) $attribute['display'],
        ],
        classifiedsListingAttributeValues((string) $listing['id'])
    );

    return $card;
}

/**
 * Ответ списком: items и next_after (null после последней порции). Неполная порция —
 * признак конца потока.
 *
 * @param list<array<string, mixed>> $rows
 */
function classifiedsApiListings(array $rows): void
{
    $lines = array_map(
        static fn (array $row): array => classifiedsApiListingLine($row),
        $rows
    );
    $nextAfter = null;

    if ($rows !== [] && count($rows) >= CLASSIFIEDS_API_PAGE_SIZE) {
        $nextAfter = (string) $rows[count($rows) - 1]['created_at'];
    }

    respond(200, (string) json_encode([
        'items' => $lines,
        'next_after' => $nextAfter,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Robots-Tag' => 'noindex',
        'Cache-Control' => 'no-store',
    ]);
}

/**
 * Ответ карточкой; отсутствующее объявление красит 404 в том же JSON.
 *
 * @param array<string, mixed>|null $listing
 */
function classifiedsApiListing(?array $listing): void
{
    if ($listing === null) {
        respond(404, (string) json_encode(['error' => 'Такого объявления нет'], JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'no-store',
        ]);
    }

    respond(200, (string) json_encode(
        classifiedsApiListingCard($listing),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ), [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Robots-Tag' => 'noindex',
        'Cache-Control' => 'no-store',
    ]);
}

/** @param array<string, string> $params */
function classifiedsApiListingsPage(array $params = []): void
{
    // Отметка потока «after» разбирается в списке: мусор там снимается без ярости.
    $after = trim((string) queryString('after', ''));

    classifiedsApiListings(classifiedsApiListingList($after));
}

/** @param array<string, string> $params */
function classifiedsApiListingPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $listing = classifiedsPublishedListingWithCategory($slug);

    if ($listing === null) {
        classifiedsApiListing(null);

        return;
    }

    classifiedsApiListing(classifiedsListingShape($listing));
}
