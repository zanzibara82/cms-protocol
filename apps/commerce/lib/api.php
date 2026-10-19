<?php

declare(strict_types=1);

// Commerce API (M6): JSON-ответы о витрине только на чтение. Публикуются те же
// данные, что видит покупатель: опубликованные товары, их цены и варианты.
// Персональные вещи (заказы, клиенты, потроха товара) API не отдаёт вовсе, поэтому
// ключ не чудит: читать витринные цены посторонний может и страницей каталога.

/** @return list<array<string, mixed>> */
function commerceApiProductList(string $after): array
{
    $where = [];
    $args = [];

    // Отметка потока: следующая порция берётся товарами, старше присланной отметки.
    // Отметка ставится датой created_at последней строки прошлого ответа. Мусор в
    // отметке снимается: отвечает первая порция, без ярости и без пустой выборки.
    if (fromDb($after) !== null) {
        $where[] = 'p.created_at < ?';
        $args[] = $after;
    }

    $where[] = "p.status = 'PUBLISHED'";
    $clause = ' WHERE ' . implode(' AND ', $where);

    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        sprintf(
            'SELECT %s FROM %s p%s ORDER BY p.created_at DESC, p.id LIMIT %d',
            COMMERCE_CARD_COLUMNS,
            t('commerce_product'),
            $clause,
            COMMERCE_API_PAGE_SIZE
        ),
        $args
    );

    return $rows;
}

/**
 * Одна строка API: карточные поля без потрохов. Хвост доставки (штрихкоды,
 * закупочная цена, seo-значения) наружу не идёт — для витрины она ничего не решает.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function commerceApiProductLine(array $row): array
{
    $available = (int) $row['stock'] > 0;

    $line = [
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'sku' => (string) $row['sku'],
        'brand' => (string) ($row['brand'] ?? ''),
        'lead_text' => (string) ($row['lead_text'] ?? ''),
        'price_retail' => (float) $row['price_retail'],
        'price_promo' => $row['price_promo'] !== null ? (float) $row['price_promo'] : null,
        'currency' => (string) $row['currency'],
        'available' => $available,
        'url' => absoluteUrl('/shop/' . rawurlencode((string) $row['slug'])),
        // Отметка остаётся в том же штампе, что в базе (UTC): поток «after» прочитает
        // её обратно одной строкой без перевода поясов.
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];

    return $line;
}

/**
 * Полная карточка API: тот же товар плюс варианты. Строки вариантов несут свои цены
 * и остаток — для приёмников, что собирают «от X ₽» сами.
 *
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function commerceApiProductCard(array $product): array
{
    $card = commerceApiProductLine($product);

    $card['variants'] = array_map(
        static function (array $row): array {
            return [
                'sku' => (string) $row['sku'],
                'title' => (string) $row['title'],
                'price_retail' => (float) $row['price_retail'],
                'price_promo' => $row['price_promo'] !== null ? (float) $row['price_promo'] : null,
                'available' => (int) $row['stock'] > 0,
            ];
        },
        commerceVariants((string) $product['id'])
    );

    return $card;
}

/**
 * Ответ API списком. Структура: items и next_after (null после последней порции):
 * неполная порция — признак конца потока, отдельного «сколько осталось» не придётся
 * выдумывать.
 *
 * @param list<array<string, mixed>> $rows
 */
function commerceApiProducts(array $rows): void
{
    $lines = array_map(
        static fn (array $row): array => commerceApiProductLine($row),
        $rows
    );
    $nextAfter = null;

    if ($rows !== [] && count($rows) >= COMMERCE_API_PAGE_SIZE) {
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
 * Ответ API одной карточкой; отсутствующий товар красит 404 в том же JSON.
 *
 * @param array<string, mixed>|null $product
 */
function commerceApiProduct(?array $product): void
{
    if ($product === null) {
        respond(404, (string) json_encode(['error' => 'Такого товара нет'], JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'no-store',
        ]);
    }

    respond(200, (string) json_encode(
        commerceApiProductCard($product),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ), [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Robots-Tag' => 'noindex',
        'Cache-Control' => 'no-store',
    ]);
}

/** @param array<string, string> $params */
function commerceApiProductsPage(array $params = []): void
{
    // Отметка потока «after» разбирается в списке: мусор там снимается без ярости.
    $after = trim((string) queryString('after', ''));

    commerceApiProducts(commerceApiProductList($after));
}

/** @param array<string, string> $params */
function commerceApiProductPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $product = commerceProductBySlug($slug);

    commerceApiProduct($product);
}
