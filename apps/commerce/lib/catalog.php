<?php

declare(strict_types=1);

// Данные каталога магазина: рубрики, товары и их связка. Каждый экран делает по
// одному обращению к базе на список, а записи идут в транзакции tx(): связка
// товар-рубрика не должна появляться наполовину.

// Колонки, не раскрывающие потрохов товара (sku — артикул для витрины и учёта, не потрохи):
// карточка и списки используют только их.
const COMMERCE_CARD_COLUMNS = 'p.id, p.slug, p.title, p.sku, p.lead_text, p.cover, p.brand, p.status,'
    . ' p.price_retail, p.price_promo, p.currency, p.stock, p.created_at';

// Товар на витрине по адресу ищется среди опубликованных: черновик светится только
// в админке. Обновления остатка читает checkout тем же правилом.
const COMMERCE_FULL_COLUMNS = COMMERCE_CARD_COLUMNS . ', p.sku, p.barcode, p.price_cost, p.body,'
    . ' p.seo_title, p.seo_description, p.noindex, p.updated_at';

/**
 * Список рубрик для выбора и для навигации витрины.
 *
 * @return list<array{id: string, slug: string, title: string, order_index: int}>
 */
function commerceCategories(bool $onlyVisible = false): array
{
    /** @var list<array{id: string, slug: string, title: string, order_index: int}> $rows */
    $rows = all(
        sprintf(
            'SELECT id, slug, title, order_index FROM %s%s ORDER BY order_index, title',
            t('commerce_category'),
            $onlyVisible ? ' WHERE visible = 1' : ''
        ),
        []
    );

    return $rows;
}

/**
 * @return array{id: string, slug: string, title: string, visible: int}|null
 */
function commerceCategory(string $slug): ?array
{
    return one(
        'SELECT id, slug, title, visible FROM ' . t('commerce_category') . ' WHERE slug = ?',
        [$slug]
    );
}

/** Занят ли адрес рубрики другим объектом: проверка формы. */
function commerceCategorySlugTaken(string $slug, string $exceptId = ''): bool
{
    if ($exceptId === '') {
        return one(
            'SELECT id FROM ' . t('commerce_category') . ' WHERE slug = ?',
            [$slug]
        ) !== null;
    }

    return one(
        'SELECT id FROM ' . t('commerce_category') . ' WHERE slug = ? AND id <> ?',
        [$slug, $exceptId]
    ) !== null;
}

/**
 * Новая рубрика. Жёсткий контракт: значения уже проверены commerceValidateCategory().
 *
 * @param array{slug: string, title: string, order_index: int} $values
 * @return array{ok: bool, error: string, id: string, slug: string, notice: string}
 */
function commerceCreateCategory(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'id' => '', 'slug' => '', 'notice' => ''];
    }

    $id = newId();

    insert('commerce_category', [
        'id' => $id,
        'slug' => $slug,
        'title' => (string) $values['title'],
        'order_index' => (int) $values['order_index'],
        'visible' => 1,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.category_created', ['categoryId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'slug' => $slug, 'notice' => 'category_saved'];
}

/**
 * Правка рубрики. Заголовок и видимость идут втёмную, порядок управляет отдельным действием.
 *
 * @param array{slug: string, title: string} $values
 * @return array{ok: bool, error: string, slug: string, notice: string}
 */
function commerceUpdateCategory(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $row = one(
        'SELECT id, slug FROM ' . t('commerce_category') . ' WHERE id = ?',
        [$id]
    );

    if ($row === null) {
        return ['ok' => false, 'error' => 'Рубрики уже нет — обновите список.', 'slug' => '', 'notice' => ''];
    }

    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'slug' => '', 'notice' => ''];
    }

    run(
        'UPDATE ' . t('commerce_category')
        . ' SET slug = ?, title = ?, updated_at = ? WHERE id = ?',
        [
            $slug,
            (string) $values['title'],
            utcStamp($now ?? nowUtc()),
            $id,
        ]
    );

    return ['ok' => true, 'error' => '', 'slug' => $slug, 'notice' => 'category_saved'];
}

/**
 * Удаление рубрики. Рубрика с привязанными товарами не удаляется втихую: правило в базе
 * (FK RESTRICT) повторяет форму, чтобы человек увидел причину отказа.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteCategory(string $id): array
{
    $used = (int) value(
        'SELECT COUNT(*) FROM ' . t('commerce_product_category') . ' WHERE category_id = ?',
        [$id]
    );

    if ($used > 0) {
        return [
            'ok' => false,
            'error' => 'Рубрику используют товары (' . $used . '). Сначала снимите их с рубрики.',
            'notice' => '',
        ];
    }

    run('DELETE FROM ' . t('commerce_category') . ' WHERE id = ?', [$id]);

    return ['ok' => true, 'error' => '', 'notice' => 'category_deleted'];
}

/**
 * Порядок рубрик: обмен местами с соседом. Соседа выбирает вызывающий код по видимости
 * экрана, здесь — только обмен значениями с сохранением направления.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceSwapCategories(string $firstId, string $secondId): array
{
    $first = one(
        'SELECT id, order_index FROM ' . t('commerce_category') . ' WHERE id = ?',
        [$firstId]
    );
    $second = one(
        'SELECT id, order_index FROM ' . t('commerce_category') . ' WHERE id = ?',
        [$secondId]
    );

    if ($first === null || $second === null) {
        return ['ok' => false, 'error' => 'Рубрика исчезла — обновите список.', 'notice' => ''];
    }

    tx(static function () use ($first, $second): void {
        run('UPDATE ' . t('commerce_category') . ' SET order_index = ? WHERE id = ?', [
            (int) $second['order_index'] + 1,
            (string) $first['id'],
        ]);
        run('UPDATE ' . t('commerce_category') . ' SET order_index = ? WHERE id = ?', [
            (int) $first['order_index'],
            (string) $second['id'],
        ]);
    });

    return ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
}

// ---------------------------------------------------------------------------
// Товары
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>|null
 */
function commerceProductById(string $id): ?array
{
    $row = one(
        'SELECT ' . COMMERCE_FULL_COLUMNS . ' FROM ' . t('commerce_product') . ' p WHERE p.id = ?',
        [$id]
    );

    return $row;
}

/**
 * @return array<string, mixed>|null
 */
function commerceProductBySlug(string $slug, bool $publishedOnly = true): ?array
{
    $args = [$slug];

    if ($publishedOnly) {
        $args[] = 'PUBLISHED';
    }

    $query = sprintf(
        'SELECT %s FROM %s p WHERE p.slug = ?%s',
        COMMERCE_FULL_COLUMNS,
        t('commerce_product'),
        $publishedOnly ? ' AND p.status = ?' : ''
    );

    return one($query, $args);
}

/** Занят ли адрес товара другим объектом: проверка формы. */
function commerceProductSlugTaken(string $slug, string $exceptId = ''): bool
{
    return one(
        sprintf(
            'SELECT id FROM %s WHERE slug = ?%s',
            t('commerce_product'),
            $exceptId !== '' ? ' AND id <> ?' : ''
        ),
        $exceptId !== '' ? [$slug, $exceptId] : [$slug]
    ) !== null;
}

/**
 * Рубрики товара парой (id, title): пустой список значит «товар без рубрики».
 *
 * @return list<array{id: string, title: string, slug: string}>
 */
function commerceProductCategories(string $productId): array
{
    $rows = all(
        'SELECT c.id, c.title, c.slug FROM ' . t('commerce_product_category') . ' pc'
        . ' JOIN ' . t('commerce_category') . ' c ON c.id = pc.category_id'
        . ' WHERE pc.product_id = ?'
        . ' ORDER BY c.order_index, c.title',
        [$productId]
    );

    /** @var list<array{id: string, title: string, slug: string}> */
    return $rows;
}

/**
 * Список товаров для админ-экрана и витрины. Фильтры приходят уже чистыми; поиск по
 * названию и артикулу идёт через escapeLike() — знак % в слове «скидка%» не открывает
 * веер незапланированных совпадений. Фильтры характеристик приходят списком условий
 * «атрибут -> значение или диапазон»: на рубрику он либо сужает, либо ничего не
 * находит — сводка чисел не врёт. Витрина с variants_search захватывает в поиск ещё
 * варианты товара: покупатель набирает SKU варианта и приходит на товар. Сортировка
 * price-asc/price-desc идёт по «витринной» цене: наличие вариантов честно ведёт
 * к минимуму цен вариантов, без вариантов — к цене самого товара.
 *
 * @param array{q?: string, category?: string, status?: string, page?: int, per_page?: int, published_only?: bool, sort?: string, variants_search?: bool, attributes?: list<array{attribute_id: string, value: string, from: ?float, to: ?float}>} $filter
 * @return array{rows: list<array<string, mixed>>, total: int, applied: list<string>}
 */
function commerceProductList(array $filter): array
{
    $applied = [];
    $where = [];
    $args = [];

    if ((bool) ($filter['published_only'] ?? false)) {
        $where[] = 'p.status = ?';
        $args[] = 'PUBLISHED';
    }

    $status = (string) ($filter['status'] ?? '');
    if (in_array($status, COMMERCE_PRODUCT_STATUSES, true)) {
        $where[] = 'p.status = ?';
        $args[] = $status;
    }

    $category = (string) ($filter['category'] ?? '');
    if ($category !== '') {
        $where[] = 'p.id IN (SELECT product_id FROM ' . t('commerce_product_category') . ' WHERE category_id = ?)';
        $args[] = $category;
    }

    $query = (string) ($filter['q'] ?? '');
    if ($query !== '') {
        $needle = '%' . escapeLike($query) . '%';
        $clauses = ['p.title LIKE ?', 'p.sku LIKE ?'];
        $queryArgs = [$needle, $needle];

        // Варианты товара участвуют в поиске витрины: SKU и название варианта ведут
        // к товару. Админ-список ищет только по товару — тонкая настройка хранится здесь.
        if ((bool) ($filter['variants_search'] ?? false)) {
            $clauses[] = 'p.id IN (SELECT v.product_id FROM ' . t('commerce_variant')
                . ' v WHERE v.sku LIKE ? OR v.title LIKE ?)';
            $queryArgs[] = $needle;
            $queryArgs[] = $needle;
        }

        $where[] = '(' . implode(' OR ', $clauses) . ')';
        $args = array_merge($args, $queryArgs);
    }

    /** @var list<array{attribute_id: string, value: string, from: ?float, to: ?float}> $attributeFilters */
    $attributeFilters = (array) ($filter['attributes'] ?? []);

    foreach ($attributeFilters as $condition) {
        $clause = 'p.id IN (SELECT pa.product_id FROM ' . t('commerce_product_attribute')
            . ' pa WHERE pa.attribute_id = ?';
        $attributeArgs = [$condition['attribute_id']];

        if ($condition['value'] !== '') {
            $clause .= ' AND pa.value = ?';
            $attributeArgs[] = $condition['value'];
        }

        if ($condition['from'] !== null) {
            $clause .= ' AND pa.value_num >= ?';
            $attributeArgs[] = $condition['from'];
        }

        if ($condition['to'] !== null) {
            $clause .= ' AND pa.value_num <= ?';
            $attributeArgs[] = $condition['to'];
        }

        $clause .= ')';
        $where[] = $clause;
        $args = array_merge($args, $attributeArgs);
        $applied[] = $condition['attribute_id'];
    }

    $clause = $where === [] ? ' WHERE 1=1' : ' WHERE ' . implode(' AND ', $where);
    $perPage = max(1, min((int) ($filter['per_page'] ?? 12), 60));
    $page = max(1, (int) ($filter['page'] ?? 1));

    // Сортировка витрины: новизна по умолчанию; цена идёт по витринной цене — «от»:
    // товар с вариантами сортируется минимумом цен вариантов, без вариантов — ценой.
    $sort = (string) ($filter['sort'] ?? '');
    $order = 'p.created_at DESC, p.id';

    if ($sort === 'price-asc' || $sort === 'price-desc') {
        $order = 'COALESCE((SELECT MIN(COALESCE(v.price_promo, v.price_retail)) FROM '
            . t('commerce_variant') . ' v WHERE v.product_id = p.id), COALESCE(p.price_promo, p.price_retail))'
            . ($sort === 'price-asc' ? ' ASC' : ' DESC') . ', p.created_at DESC, p.id';
    }

    $total = (int) value(
        sprintf('SELECT COUNT(*) FROM %s p%s', t('commerce_product'), $clause),
        $args
    );

    $offset = ($page - 1) * $perPage;
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        sprintf(
            'SELECT %s FROM %s p%s ORDER BY %s LIMIT %d OFFSET %d',
            COMMERCE_CARD_COLUMNS,
            t('commerce_product'),
            $clause,
            $order,
            $perPage,
            $offset
        ),
        $args
    );

    // Since per_page is a parameter here, the caller recomputes the pager itself.
    return ['rows' => $rows, 'total' => $total, 'per_page' => $perPage, 'applied' => $applied];
}

/**
 * Новый товар внутри транзакции: строка товара и его связи с рубриками идут либо
 * целиком, либо никак.
 *
 * @param array{fields: array<string, string>, prices: array<string, ?float>, stock: int, body: list<array{title: string, text: string}>, slug: string, categories: list<string>} $values
 * @return array{ok: bool, error: string, id: string, slug: string, notice: string}
 */
function commerceCreateProduct(array $values, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $fields = $values['fields'];
    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'id' => '', 'slug' => '', 'notice' => ''];
    }

    $id = newId();
    $prices = $values['prices'];

    // Слаг и артикул держатся уникальными ключами: параллельное создание того же товара
    // гонит наш INSERT в отказ базы. Кто именно занят — читается перечитыванием, потому что
    // текст исключения не обещает имя ключа: у человека формулировка своя для каждой из причин.
    try {
        tx(static function () use ($id, $slug, $values, $fields, $prices, $moment): void {
            insert('commerce_product', [
                'id' => $id,
                'slug' => $slug,
                'sku' => (string) $fields['sku'],
                'title' => (string) $fields['title'],
                'lead_text' => (string) $fields['lead_text'] !== '' ? (string) $fields['lead_text'] : null,
                'cover' => null,
                'body' => $values['body'] !== [] ? json_encode($values['body'], JSON_UNESCAPED_UNICODE) : null,
                'brand' => (string) $fields['brand'] !== '' ? (string) $fields['brand'] : null,
                'barcode' => (string) $fields['barcode'] !== '' ? (string) $fields['barcode'] : null,
                'price_retail' => (float) $prices['price_retail'],
                'price_promo' => $prices['price_promo'],
                'price_cost' => $prices['price_cost'],
                'currency' => (string) $fields['currency'],
                'stock' => (int) $values['stock'],
                'status' => (string) $fields['status'],
                'seo_title' => (string) $fields['seo_title'] !== '' ? (string) $fields['seo_title'] : null,
                'seo_description' => $fields['seo_description'] !== ''
                    ? (string) $fields['seo_description'] : null,
                'noindex' => (string) $fields['noindex'] === '1' ? 1 : 0,
                'created_at' => utcStamp($moment),
                'updated_at' => utcStamp($moment),
            ]);

            foreach ($values['categories'] as $categoryId) {
                insert('commerce_product_category', [
                    'product_id' => $id,
                    'category_id' => $categoryId,
                ]);
            }
        });
    } catch (Throwable $insertError) {
        // Причина разыменовывается пробами: артикул приоритетнее адреса. Слаг занят либо
        // заняты оба — формулировка о слаге остаётся запасной и честной.
        return [
            'ok' => false,
            'error' => duplicateReason(
                $insertError,
                static fn (): string => (int) value(
                    'SELECT COUNT(*) FROM ' . t('commerce_product') . ' WHERE sku = ?',
                    [(string) $fields['sku']]
                ) > 0 ? 'Артикул уже занят другим товаром — предложите свой.' : '',
                'Такой адрес уже занят другим товаром — предложите свой.'
            ),
            'id' => '',
            'slug' => '',
            'notice' => '',
        ];
    }

    logInfo('commerce.product_created', ['productId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'id' => $id, 'slug' => $slug, 'notice' => 'product_saved'];
}

/**
 * Правка товара. Мониторинг снимков не ведётся: история правок — шаг M-этапа, не базового
 * набора; базовый контракт — информация «когда последний раз правлен» в updated_at.
 *
 * @param array{fields: array<string, string>, prices: array<string, ?float>, stock: int, body: list<array{title: string, text: string}>, slug: string, categories: list<string>} $values
 * @return array{ok: bool, error: string, slug: string, notice: string}
 */
function commerceUpdateProduct(string $id, array $values, string $previousSlug, ?DateTimeImmutable $now = null): array
{
    $row = commerceProductById($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Товара уже нет — обновите список.', 'slug' => '', 'notice' => ''];
    }

    $moment = $now ?? nowUtc();
    $fields = $values['fields'];
    $prices = $values['prices'];
    $slug = (string) $values['slug'];

    if ($slug === '') {
        return ['ok' => false, 'error' => 'Адрес не собрался из названия.', 'slug' => '', 'notice' => ''];
    }

    // Правка и чужое сохранение могут претендовать на один слаг или артикул: проигравший
    // UPDATE получает отказ уникального ключа. Причина разыменовывается перечитыванием —
    // человеку видна формулировка о том поле, которое действительно занято.
    try {
        tx(static function () use ($id, $slug, $values, $fields, $prices, $moment): void {
            run(
                'UPDATE ' . t('commerce_product') . ' SET slug = ?, sku = ?, title = ?, lead_text = ?, body = ?,'
                . ' brand = ?, barcode = ?, price_retail = ?, price_promo = ?, price_cost = ?, currency = ?,'
                . ' stock = ?, status = ?, seo_title = ?, seo_description = ?, noindex = ?, updated_at = ?'
                . ' WHERE id = ?',
                [
                    $slug,
                    (string) $fields['sku'],
                    (string) $fields['title'],
                    (string) $fields['lead_text'] !== '' ? (string) $fields['lead_text'] : null,
                    $values['body'] !== [] ? json_encode($values['body'], JSON_UNESCAPED_UNICODE) : null,
                    (string) $fields['brand'] !== '' ? (string) $fields['brand'] : null,
                    (string) $fields['barcode'] !== '' ? (string) $fields['barcode'] : null,
                    (float) $prices['price_retail'],
                    $prices['price_promo'],
                    $prices['price_cost'],
                    (string) $fields['currency'],
                    (int) $values['stock'],
                    (string) $fields['status'],
                    (string) $fields['seo_title'] !== '' ? (string) $fields['seo_title'] : null,
                    $fields['seo_description'] !== '' ? (string) $fields['seo_description'] : null,
                    (string) $fields['noindex'] === '1' ? 1 : 0,
                    utcStamp($moment),
                    $id,
                ]
            );

            run('DELETE FROM ' . t('commerce_product_category') . ' WHERE product_id = ?', [$id]);

            foreach ($values['categories'] as $categoryId) {
                insert('commerce_product_category', [
                    'product_id' => $id,
                    'category_id' => $categoryId,
                ]);
            }
        });
    } catch (Throwable $updateError) {
        // Причина разыменовывается пробами: артикул приоритетнее адреса, свой товар из
        // пробы исключён (id <> ?) — правка не называет занятым собственный артикул.
        return [
            'ok' => false,
            'error' => duplicateReason(
                $updateError,
                static fn (): string => (int) value(
                    'SELECT COUNT(*) FROM ' . t('commerce_product') . ' WHERE sku = ? AND id <> ?',
                    [(string) $fields['sku'], $id]
                ) > 0 ? 'Артикул уже занят другим товаром — предложите свой.' : '',
                'Такой адрес уже занят другим товаром — предложите свой.'
            ),
            'slug' => '',
            'notice' => '',
        ];
    }

    logInfo('commerce.product_updated', ['productId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'error' => '', 'slug' => $slug, 'notice' => 'product_saved'];
}

/**
 * Удаление товара. Товар в заказах не удаляется: снимки строк заказа ссылаются на него
 * FK RESTRICT, и для честности отчёта ограничение повторяет форма.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteProduct(string $id): array
{
    $ordered = (int) value(
        'SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE product_id = ?',
        [$id]
    );

    if ($ordered > 0) {
        return [
            'ok' => false,
            'error' => 'Товар участвует в заказах (' . $ordered . '). Уберите его из продажи, а не удаляйте.',
            'notice' => '',
        ];
    }

    run('DELETE FROM ' . t('commerce_product') . ' WHERE id = ?', [$id]);

    return ['ok' => true, 'error' => '', 'notice' => 'product_deleted'];
}

/**
 * Адрес товара к статусу: снятие из продажи или возврат на витрину. Список строк
 * заказа при этом не трогается: снимки FK RESTRICT остаются на месте.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceSetProductStatus(string $id, string $status): array
{
    if (!in_array($status, COMMERCE_PRODUCT_STATUSES, true)) {
        return ['ok' => false, 'error' => 'Неизвестный статус товара.', 'notice' => ''];
    }

    $done = run(
        'UPDATE ' . t('commerce_product') . ' SET status = ?, updated_at = ? WHERE id = ?',
        [$status, utcStamp(nowUtc()), $id]
    ) === 1;

    if (!$done) {
        return ['ok' => false, 'error' => 'Товар не изменился — обновите список.', 'notice' => ''];
    }

    return ['ok' => true, 'error' => '', 'notice' => $status === 'PUBLISHED' ? 'product_published' : 'product_hidden'];
}
