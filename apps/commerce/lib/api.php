<?php

declare(strict_types=1);

// Commerce API (M6): JSON-ответы о витрине только на чтение. Публикуются те же
// данные, что видит покупатель: опубликованные товары, их цены и варианты.
// Персональные вещи (заказы, клиенты, потроха товара) API не отдаёт вовсе, поэтому
// ключ не чудит: читать витринные цены посторонний может и страницей каталога.

/**
 * Композитный курсор потока. Признаются две формы: «штамп|id» (композит) и чистый штамп
 * прежнего формата — старые клиенты продолжают листать, но внутри одной секунды границу
 * держит только композит: READ COMMITTED и секундная точность штампа давали порции без
 * остатков строки. Мусор в отметке снимается: отвечает первая порция, без ярости.
 *
 * @return array{stamp: string, id: string} пустые строки означают «отметки нет»
 */
function commerceApiCursorParse(string $after): array
{
    $after = trim($after);

    if ($after === '') {
        return ['stamp' => '', 'id' => ''];
    }

    $parts = explode('|', $after, 2);
    $stampRaw = (string) $parts[0];
    $id = (string) ($parts[1] ?? '');

    // Штамп обязан читаться датой базы: иначе весь курсор — мусор, и первая порция честнее.
    if (fromDb($stampRaw) === null) {
        return ['stamp' => '', 'id' => ''];
    }

    // Хвост композита обязан быть формой идентификатора: подделка «штамп|id|мусор» иначе
    // даёт хвост «id|мусор», а строковое сравнение с ним включает и саму строку-границу —
    // соседняя порция отдавала хвостовую строку повторно. Форма не сошлась — курсор
    // считается мусором целиком: отвечает первая порция, а не полуверная выборка.
    if ($id !== '' && preg_match('/^[0-9a-z]{1,32}$/', $id) !== 1) {
        return ['stamp' => '', 'id' => ''];
    }

    return ['stamp' => $stampRaw, 'id' => $id];
}

/** @return list<array<string, mixed>> */
function commerceApiProductList(string $after): array
{
    $where = [];
    $args = [];
    $cursor = commerceApiCursorParse($after);

    // Отметка потока: следующая порция берётся товарами, старше присланной отметки, а
    // внутри одной и той же секунды — по идентификатору: сортировка «created_at DESC,
    // id DESC» и курсор держат согласованность между порциями.
    if ($cursor['stamp'] !== '') {
        if ($cursor['id'] !== '') {
            $where[] = '(p.created_at < ? OR (p.created_at = ? AND p.id < ?))';
            $args[] = $cursor['stamp'];
            $args[] = $cursor['stamp'];
            $args[] = $cursor['id'];
        } else {
            $where[] = 'p.created_at < ?';
            $args[] = $cursor['stamp'];
        }
    }

    $where[] = "p.status = 'PUBLISHED'";
    $clause = ' WHERE ' . implode(' AND ', $where);

    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        sprintf(
            'SELECT %s FROM %s p%s ORDER BY p.created_at DESC, p.id DESC LIMIT %d',
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

    // Полная порция обещает продолжение: отметка отдаётся композитом «штамп|id»
    // последней строки — следующая порция читает его и доедает строки той же
    // секунды по идентификатору, без потерь между порциями.
    if ($rows !== [] && count($rows) >= COMMERCE_API_PAGE_SIZE) {
        $last = $rows[count($rows) - 1];

        $nextAfter = (string) $last['created_at'] . '|' . (string) $last['id'];
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
