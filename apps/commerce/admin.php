<?php

declare(strict_types=1);

// Экраны администратора магазина. Гейт маршрута ('permission' => 'login') пускает за
// клавиатуру, POST-действия проверяют токен (csrfCheck) и свой результат отдают
// finishAppAction(): отказ гасит список с причиной, успех — с ключом уведомления.

const COMMERCE_NAV_PATHS = [
    '/commerce/admin' => 'Обзор',
    '/commerce/admin/products' => 'Товары',
    '/commerce/admin/categories' => 'Рубрики',
    '/commerce/admin/coupons' => 'Купоны',
    '/commerce/admin/promos' => 'Промо-правила',
    '/commerce/admin/orders' => 'Заказы',
];

const COMMERCE_NOTICE_TEXT = [
    'product_saved' => 'Товар сохранён.',
    'product_published' => 'Товар опубликован.',
    'product_hidden' => 'Товар снят с продажи.',
    'product_deleted' => 'Товар удалён.',
    'category_saved' => 'Рубрика сохранена.',
    'category_deleted' => 'Рубрика удалена.',
    'order_updated' => 'Заказ обработан.',
    'cart_cleared' => 'Корзина опустошена.',
    'variant_saved' => 'Вариант сохранён.',
    'variant_deleted' => 'Вариант удалён.',
    'attribute_saved' => 'Характеристика добавлена.',
    'attribute_deleted' => 'Характеристика удалена.',
    'coupon_saved' => 'Купон сохранён.',
    'coupon_deleted' => 'Купон удалён.',
    'promo_saved' => 'Правило промо сохранено.',
    'promo_deleted' => 'Правило промо удалено.',
];

/**
 * Ссылки-вкладки. Активная вкладка печатается шаблоном как разметка со статическими
 * классами; здесь — только адреса, подписи и признак.
 */
function commerceAdminNav(string $currentPath): string
{
    $items = '';

    foreach (COMMERCE_NAV_PATHS as $href => $label) {
        $active = $currentPath === $href
            || ($href !== '/commerce/admin' && str_starts_with($currentPath, $href . '/'));
        $class = $active
            ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e($label) . '</a></li>';
    }

    return '<nav aria-label="Экраны магазина"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

function commerceAdminNotice(): string
{
    return noticeForAdminScreen(COMMERCE_NOTICE_TEXT);
}

/** Причина отказа из сессионного flash вместе со стандартным заголовком ядра.
 * @return array{alertTitle: string, alertReason: string}
 */
function commerceAdminAlert(): array
{
    $reason = takeAdminFlashReason();

    return [
        'alertTitle' => adminFailureTitle($reason),
        'alertReason' => $reason,
    ];
}

/**
 * Блок характеристик для формы товара: набор по выбранным рубрикам, значения при
 * открытии — из базы, при отказе — набранное из POST.
 *
 * @param string $productId пусто для нового товара
 * @param list<string> $categoryIds
 * @param array<string, mixed> $post
 * @return list<array{id: string, slug: string, label: string, type: string, unit: ?string, choices: list<string>, value: string}>
 */
function commerceAttributeFields(string $productId, array $categoryIds, array $post): array
{
    $stored = [];
    if ($productId !== '') {
        foreach (commerceProductAttributes($productId) as $row) {
            $stored[(string) $row['id']] = (string) $row['value'];
        }
    }

    $out = [];

    foreach (commerceAttributesForCategories($categoryIds) as $attribute) {
        $value = trim((string) ($post['attr_' . $attribute['id']] ?? ''));
        if ($value === '' && isset($stored[(string) $attribute['id']])) {
            $value = $stored[(string) $attribute['id']];
        }

        $choices = [];
        if ((string) $attribute['type'] === 'CHOICE') {
            $choices = array_map(
                static fn (array $row): string => (string) $row['value'],
                commerceAttributeChoices((string) $attribute['id'])
            );
        }

        $out[] = [
            'id' => (string) $attribute['id'],
            'slug' => (string) $attribute['slug'],
            'label' => (string) $attribute['label'],
            'type' => (string) $attribute['type'],
            'unit' => $attribute['unit'],
            'choices' => $choices,
            'value' => $value,
        ];
    }

    return $out;
}

/** @param array<string, string> $params */
function commerceAdminSummary(array $params = []): void
{
    $counts = [
        'products' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_product')),
        'published' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_product') . " WHERE status = 'PUBLISHED'"),
        'categories' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_category')),
        'orders_new' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_order') . " WHERE status = 'NEW'"),
        'orders_total' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_order')),
        'low_stock' => (int) value('SELECT COUNT(*) FROM ' . t('commerce_product') . " WHERE status = 'PUBLISHED' AND stock <= 5"),
    ];

    $lowRows = all(
        'SELECT slug, title, stock FROM ' . t('commerce_product')
        . " WHERE status = 'PUBLISHED' AND stock <= 5"
        . ' ORDER BY stock, title LIMIT 10'
    );
    $recentOrders = all(
        'SELECT id, number, customer_name, total, currency, status, created_at'
        . ' FROM ' . t('commerce_order') . ' ORDER BY created_at DESC, number DESC LIMIT 5'
    );

    render('commerce/admin/summary', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'counts' => $counts,
        'lowStock' => $lowRows,
        'recentOrders' => $recentOrders,
    ], commerceAdminAlert()), ['title' => 'Магазин — обзор', 'noindex' => true]);
}

/** @param array<string, string> $params */
function commerceAdminProducts(array $params = []): void
{
    $query = trim((string) queryString('q', ''));
    $categoryId = trim((string) queryString('cat', ''));
    $statusRaw = strtoupper(trim((string) queryString('status', '')));
    $status = in_array($statusRaw, COMMERCE_PRODUCT_STATUSES, true) ? $statusRaw : '';
    $page = pageOf((string) queryString('page', '1'));
    $perPage = 20;

    $result = commerceProductList([
        'q' => $query,
        'category' => $categoryId,
        'status' => $status,
        'page' => $page,
        'per_page' => $perPage,
    ]);

    $pages = max(1, (int) ceil($result['total'] / $perPage));
    render('commerce/admin/products', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'query' => $query,
        'category' => $categoryId,
        'status' => $status,
        'listing' => [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'pages' => $pages,
            'current' => min($page, $pages),
            'perPage' => $perPage,
        ],
        'categories' => commerceCategories(),
    ], commerceAdminAlert()), ['title' => 'Товары — магазин', 'noindex' => true]);
}

/** @param array<string, string> $params */
function commerceProductNew(array $params = []): void
{
    render('commerce/admin/product-form', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'mode' => 'new',
        'product' => null,
        'form' => [],
        'errors' => [],
        'values' => [],
        'categories' => commerceCategories(),
        'chosen' => [],
        'attributes' => [],
        'heading' => 'Новый товар',
        'notice' => '',
    ], commerceAdminAlert()), [
        'title' => 'Новый товар — магазин',
        'noindex' => true,
    ]);
}

/** @param array<string, string> $params */
function commerceProductEdit(array $params = []): void
{
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $product = commerceProductById($id);

    if ($product === null) {
        finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

        return;
    }

    $chosenCategories = array_map('strval', array_column(commerceProductCategories($id), 'id'));

    render('commerce/admin/product-form', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'mode' => 'edit',
        'product' => $product,
        'form' => [],
        'errors' => [],
        'values' => [],
        'categories' => commerceCategories(),
        'chosen' => $chosenCategories,
        'attributes' => commerceAttributeFields($id, $chosenCategories, []),
        'heading' => 'Правка товара',
        'notice' => '',
    ], commerceAdminAlert()), [
        'title' => 'Правка товара — магазин',
        'noindex' => true,
    ]);
}

/**
 * Общий обработчик формы товара: создание и правка отличаются наличием параметра адреса.
 *
 * @param array<string, string> $params
 */
function commerceProductSaveAction(array $params = []): void
{
    csrfCheck();
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $product = null;

    if ($id !== '') {
        $product = commerceProductById($id);
        if ($product === null) {
            finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

            return;
        }
    }

    $previousSlug = $product === null ? '' : (string) $product['slug'];
    $check = commerceValidateProduct(
        $_POST,
        $previousSlug,
        commerceCategories(),
        static fn (string $candidate): bool => commerceProductSlugTaken($candidate)
    );

    if ($check['errors'] !== []) {
        $input = formValues($_POST, ['title', 'slug', 'sku', 'lead_text', 'brand', 'barcode', 'currency', 'status', 'stock', 'seo_title', 'seo_description']);

        render('commerce/admin/product-form', array_merge([
            'nav' => commerceAdminNav(requestPath()),
            'mode' => $product === null ? 'new' : 'edit',
            'product' => $product,
            'form' => $input,
            'formBody' => commercePostedBody($_POST),
            'errors' => $check['errors'],
            'values' => $check['values'],
            'categories' => commerceCategories(),
            'chosen' => $check['values']['categories'],
            'attributes' => commerceAttributeFields(
                (string) ($product['id'] ?? ''),
                $check['values']['categories'],
                $_POST
            ),
            'heading' => $product === null ? 'Новый товар' : 'Правка товара',
        ], commerceAdminAlert()), [
            'title' => 'Форма товара — магазин',
            'noindex' => true,
        ]);

        return;
    }

    if ($product === null) {
        $saved = commerceCreateProduct($check['values']);
    } else {
        $saved = commerceUpdateProduct($id, $check['values'], $previousSlug);
    }

    if ((bool) ($saved['ok'] ?? false)) {
        // Характеристики пишутся товаром отдельно от товарной транзакции: значение,
        // не разобравшееся числом, отбрасывается с журналом, а не роняет правку товара.
        commerceSaveProductAttributes(
            (string) ($saved['id'] ?? $id),
            $check['values']['categories'],
            $_POST
        );
    }

    $notice = (string) ($saved['notice'] ?? '');
    finishAppAction($saved, '/commerce/admin/products', $notice);
}

/** @param array<string, string> $params */
function commerceProductAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);

    $product = commerceProductById($id);
    if ($product === null) {
        finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

        return;
    }

    $result = match ($op) {
        'publish' => commerceSetProductStatus($id, 'PUBLISHED'),
        'hide' => commerceSetProductStatus($id, 'DRAFT'),
        'delete' => commerceDeleteProduct($id),
        default => ['ok' => false, 'error' => 'Неизвестное действие с товаром.', 'notice' => ''],
    };

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/commerce/admin/products', $notice);
}

// ---------------------------------------------------------------------------
// Рубрики
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceAdminCategories(array $params = []): void
{
    render('commerce/admin/categories', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'rows' => commerceCategoriesWithCounters(),
    ], commerceAdminAlert()), ['title' => 'Рубрики — магазин', 'noindex' => true]);
}

/**
 * Рубрики с числом товаров. Счётчик приходит одним GROUP BY-запросом: экран не тратит
 * по обращению на строку.
 *
 * @return list<array{id: string, slug: string, title: string, order_index: int, count: int}>
 */
function commerceCategoriesWithCounters(): array
{
    $counts = [];
    foreach (all('SELECT category_id, COUNT(*) AS n FROM ' . t('commerce_product_category') . ' GROUP BY category_id') as $row) {
        $counts[(string) $row['category_id']] = (int) $row['n'];
    }

    $out = [];
    foreach (commerceCategories() as $category) {
        $category['count'] = $counts[(string) $category['id']] ?? 0;
        $out[] = $category;
    }

    return $out;
}

/**
 * @return array{id: string, slug: string, title: string, order_index: int}|null
 */
function commerceCategoryById(string $id): ?array
{
    return one(
        'SELECT id, slug, title, order_index FROM ' . t('commerce_category') . ' WHERE id = ?',
        [$id]
    );
}

/** @param array<string, string> $params */
function commerceCategoriesAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, 26);

    $result = ['ok' => false, 'error' => 'Неизвестное действие с рубриками.', 'notice' => ''];

    if ($op === 'create') {
        $index = count(commerceCategories()) + 1;
        $check = commerceValidateCategory(
            $_POST,
            '',
            static fn (string $candidate): bool => commerceCategorySlugTaken($candidate),
            $index
        );

        $result = $check['errors'] !== []
            ? ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => '']
            : commerceCreateCategory($check['values']);
    }

    if ($op === 'rename') {
        $current = commerceCategoryById($id);

        if ($current === null) {
            $result = ['ok' => false, 'error' => 'Рубрики уже нет — обновите список.', 'notice' => ''];
        } else {
            $check = commerceValidateCategory(
                $_POST,
                (string) $current['slug'],
                static fn (string $candidate): bool => commerceCategorySlugTaken($candidate, $id),
                (int) $current['order_index']
            );

            $result = $check['errors'] !== []
                ? ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => '']
                : commerceUpdateCategory($id, $check['values']);
        }
    }

    if ($op === 'up' || $op === 'down') {
        $rows = commerceCategories();
        $index = null;
        foreach ($rows as $i => $row) {
            if ((string) $row['id'] === $id) {
                $index = $i;

                break;
            }
        }

        if ($index === null) {
            $result = ['ok' => false, 'error' => 'Рубрики уже нет — обновите список.', 'notice' => ''];
        } else {
            $neighbour = $op === 'up' ? $index - 1 : $index + 1;

            if (!isset($rows[$neighbour])) {
                // Ехать некуда: строка уже на краю списка. Тишина — так честнее, чем «сохранено».
                $result = ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
            } else {
                $result = commerceSwapCategories($id, (string) $rows[$neighbour]['id']);
            }
        }
    }

    if ($op === 'delete') {
        $result = commerceDeleteCategory($id);
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/commerce/admin/categories', $notice);
}

// ---------------------------------------------------------------------------
// Варианты товара
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceVariantsScreen(array $params = []): void
{
    $id = routeId26($params);
    $product = commerceProductById($id);

    if ($product === null) {
        finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

        return;
    }

    render('commerce/admin/variants', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'product' => $product,
        'variants' => commerceVariants($id),
        'mode' => 'new',
        'variant' => null,
        'form' => [],
        'errors' => [],
    ], commerceAdminAlert()), [
        'title' => 'Варианты товара — магазин',
        'noindex' => true,
    ]);
}

/**
 * Общий обработчик действий с вариантами: форма отказа переоткрывает экран с
 * набранным, успех верит ключу уведомления из lib-функции.
 *
 * @param array<string, string> $params
 */
function commerceVariantAction(array $params = []): void
{
    csrfCheck();
    $productId = routeId26($params);
    $variantId = mb_substr(trim((string) ($params['variantId'] ?? '')), 0, 26);
    $op = postString('op');
    $address = '/commerce/admin/products/' . rawurlencode($productId) . '/variants';

    $product = commerceProductById($productId);
    if ($product === null) {
        finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

        return;
    }

    if ($op === 'create') {
        $check = commerceValidateVariant($productId, $_POST);
        if ($check['errors'] !== []) {
            commerceVariantsRenderFailure($productId, 'new', null, $check['values'], $check['errors']);

            return;
        }

        $result = commerceCreateVariant($productId, $check['values']);

        commerceFinish($result, $address);

        return;
    }

    $variant = commerceVariantById($variantId);
    $mine = $variant !== null && (string) $variant['product_id'] === $productId;

    if ($op === 'save') {
        if (!$mine) {
            finishAppAction(['ok' => false, 'error' => 'Варианта уже нет — обновите список.'], $address, '');

            return;
        }

        $check = commerceValidateVariant($productId, $_POST);
        if ($check['errors'] !== []) {
            commerceVariantsRenderFailure($productId, 'edit', $variant, $check['values'], $check['errors']);

            return;
        }

        $result = commerceUpdateVariant($variantId, $check['values']);

        commerceFinish($result, $address);

        return;
    }

    if ($op === 'delete') {
        $result = $mine
            ? commerceDeleteVariant($variantId)
            : ['ok' => false, 'error' => 'Варианта уже нет — обновите список.', 'notice' => ''];

        commerceFinish($result, $address);

        return;
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие с вариантом.'], $address, '');
}

/**
 * Форма варианта с отказом: набор печатается, причина — в статусе экрана.
 *
 * @param array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}|null $variant
 * @param array{title: string, sku: string, price_retail: string, price_promo: string, stock: string} $form
 * @param array<string, string> $errors
 */
function commerceVariantsRenderFailure(string $productId, string $mode, ?array $variant, array $form, array $errors): void
{
    $product = commerceProductById($productId);

    if ($product === null) {
        finishAppAction(['ok' => false, 'error' => 'Товара уже нет — обновите список.'], '/commerce/admin/products', '');

        return;
    }

    render('commerce/admin/variants', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'product' => $product,
        'variants' => commerceVariants($productId),
        'mode' => $mode,
        'variant' => $variant,
        'form' => $form,
        'errors' => $errors,
    ], commerceAdminAlert()), [
        'title' => 'Варианты товара — магазин',
        'noindex' => true,
    ]);
}

/**
 * Завершение действий variants/attributes: успех и отказ расходятся по flash ядра.
 *
 * @param array{ok: bool, error: string, notice: string} $result
 */
function commerceFinish(array $result, string $address): void
{
    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, $address, $notice);
}

/** @param array<string, string> $params */
function routeId26(array $params): string
{
    return mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
}

// ---------------------------------------------------------------------------
// Характеристики рубрик
// ---------------------------------------------------------------------------

/**
 * Строки атрибутов с их справочник значений — экран рубрики печатает набор целиком.
 *
 * @return list<array{id: string, slug: string, label: string, type: string, unit: ?string, order_index: int, choices: list<string>}>
 */
function commerceAttributeRows(string $categoryId): array
{
    $out = [];

    foreach (commerceAttributes($categoryId) as $attribute) {
        $attribute['choices'] = array_map(
            static fn (array $row): string => (string) $row['value'],
            commerceAttributeChoices((string) $attribute['id'])
        );
        $out[] = $attribute;
    }

    return $out;
}

/** @param array<string, string> $params */
function commerceCategoryAttributes(array $params = []): void
{
    $id = routeId26($params);
    $category = commerceCategoryById($id);

    if ($category === null) {
        finishAppAction(['ok' => false, 'error' => 'Рубрики уже нет — обновите список.'], '/commerce/admin/categories', '');

        return;
    }

    render('commerce/admin/category-attributes', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'category' => $category,
        'attributes' => commerceAttributeRows($id),
        'form' => [],
        'errors' => [],
    ], commerceAdminAlert()), [
        'title' => 'Характеристики рубрики — магазин',
        'noindex' => true,
    ]);
}

/** @param array<string, string> $params */
function commerceAttributeAction(array $params = []): void
{
    csrfCheck();
    $categoryId = routeId26($params);
    $attributeId = mb_substr(trim((string) ($params['attributeId'] ?? '')), 0, 26);
    $op = postString('op');
    $address = '/commerce/admin/categories/' . rawurlencode($categoryId);

    $category = commerceCategoryById($categoryId);
    if ($category === null) {
        finishAppAction(['ok' => false, 'error' => 'Рубрики уже нет — обновите список.'], '/commerce/admin/categories', '');

        return;
    }

    if ($op === 'create') {
        $check = commerceValidateAttribute($_POST, '');
        if ($check['errors'] !== []) {
            commerceAttributesRenderFailure($categoryId, $category, $check['values'], $check['errors']);

            return;
        }

        $result = commerceCreateAttribute($categoryId, $check['values']);

        commerceFinish($result, $address);

        return;
    }

    if ($op === 'delete') {
        $attribute = commerceAttributeById($attributeId);
        $mine = $attribute !== null && (string) $attribute['category_id'] === $categoryId;

        $result = $mine
            ? commerceDeleteAttribute($attributeId)
            : ['ok' => false, 'error' => 'Характеристики уже нет — обновите список.', 'notice' => ''];

        commerceFinish($result, $address);

        return;
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие с характеристикой.'], $address, '');
}

/**
 * Экран характеристик с отказом формы: набранное возвращается, причина видна.
 *
 * @param array{id: string, slug: string, title: string, order_index: int} $category
 * @param array{label: string, slug: string, type: string, unit: string} $form
 * @param array<string, string> $errors
 */
function commerceAttributesRenderFailure(string $categoryId, array $category, array $form, array $errors): void
{
    render('commerce/admin/category-attributes', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'category' => $category,
        'attributes' => commerceAttributeRows($categoryId),
        'form' => $form,
        'errors' => $errors,
    ], commerceAdminAlert()), [
        'title' => 'Характеристики рубрики — магазин',
        'noindex' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Купоны
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceAdminCoupons(array $params = []): void
{
    $id = routeId26($params);
    $coupon = $id !== '' ? commerceCouponById($id) : null;

    if ($id !== '' && $coupon === null) {
        finishAppAction(['ok' => false, 'error' => 'Купона уже нет — обновите список.'], '/commerce/admin/coupons', '');

        return;
    }

    render('commerce/admin/coupons', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'coupons' => commerceCouponsAll(),
        'mode' => $coupon !== null ? 'edit' : 'new',
        'coupon' => $coupon,
        'form' => [],
        'errors' => [],
    ], commerceAdminAlert()), ['title' => 'Купоны — магазин', 'noindex' => true]);
}

/**
 * Действия с купонами: форма отказа переоткрывает экран с набранным, успех верит
 * ключу уведомления из lib-функции.
 *
 * @param array<string, string> $params
 */
function commerceCouponAction(array $params = []): void
{
    csrfCheck();
    $id = routeId26($params);
    $op = postString('op');
    $address = '/commerce/admin/coupons';

    if ($op === 'create') {
        $check = commerceValidateCoupon($_POST, static fn (string $candidate): bool => commerceCouponCodeTaken($candidate));

        if ($check['errors'] !== []) {
            commerceCouponsRenderFailure(null, $check['values'], $check['errors']);

            return;
        }

        commerceFinish(commerceCreateCoupon($check['values']), $address);

        return;
    }

    $coupon = commerceCouponById($id);

    if ($op === 'save') {
        if ($coupon === null) {
            finishAppAction(['ok' => false, 'error' => 'Купона уже нет — обновите список.'], $address, '');

            return;
        }

        $check = commerceValidateCoupon(
            $_POST,
            static fn (string $candidate): bool => commerceCouponCodeTaken($candidate, $id)
        );

        if ($check['errors'] !== []) {
            commerceCouponsRenderFailure($coupon, $check['values'], $check['errors']);

            return;
        }

        commerceFinish(commerceUpdateCoupon($id, $check['values']), $address);

        return;
    }

    if ($op === 'delete') {
        commerceFinish(
            $coupon !== null ? commerceDeleteCoupon($id) : ['ok' => false, 'error' => 'Купона уже нет — обновите список.', 'notice' => ''],
            $address
        );

        return;
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие с купоном.'], $address, '');
}

/**
 * Экран купонов с отказом формы: набранное возвращается строками (суммы — как в поле формы),
 * причина видна.
 *
 * @param array{id: string, code: string, kind: string, value: float, min_total: ?float, expires_at: ?string, max_uses: ?int, used_count: int, active: bool}|null $coupon
 * @param array{code: string, kind: string, value: float, min_total: ?float, expires: string, max_uses: ?int, active: int} $values
 * @param array<string, string> $errors
 */
function commerceCouponsRenderFailure(?array $coupon, array $values, array $errors): void
{
    $form = [
        'code' => (string) $values['code'],
        'kind' => (string) $values['kind'],
        'value' => $values['value'] > 0 ? number_format((float) $values['value'], 2, ',', ' ') : '',
        'min_total' => $values['min_total'] !== null ? number_format((float) $values['min_total'], 2, ',', ' ') : '',
        'expires' => (string) $values['expires'],
        'max_uses' => $values['max_uses'] !== null ? (string) $values['max_uses'] : '',
        'active' => (int) $values['active'],
    ];

    render('commerce/admin/coupons', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'coupons' => commerceCouponsAll(),
        'mode' => $coupon !== null ? 'edit' : 'new',
        'coupon' => $coupon,
        'form' => $form,
        'errors' => $errors,
    ], commerceAdminAlert()), ['title' => 'Купоны — магазин', 'noindex' => true]);
}

// ---------------------------------------------------------------------------
// Промо-правила (автоскидка корзины)
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceAdminPromos(array $params = []): void
{
    $id = routeId26($params);
    $promo = $id !== '' ? commercePromoById($id) : null;

    if ($id !== '' && $promo === null) {
        finishAppAction(['ok' => false, 'error' => 'Правила уже нет — обновите список.'], '/commerce/admin/promos', '');

        return;
    }

    render('commerce/admin/promos', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'promos' => commercePromosAll(),
        'mode' => $promo !== null ? 'edit' : 'new',
        'promo' => $promo,
        'form' => [],
        'errors' => [],
    ], commerceAdminAlert()), ['title' => 'Промо-правила — магазин', 'noindex' => true]);
}

/** @param array<string, string> $params */
function commercePromoAction(array $params = []): void
{
    csrfCheck();
    $id = routeId26($params);
    $op = postString('op');
    $address = '/commerce/admin/promos';

    if ($op === 'create') {
        $check = commerceValidatePromo($_POST);

        if ($check['errors'] !== []) {
            commercePromosRenderFailure(null, $check['values'], $check['errors']);

            return;
        }

        commerceFinish(commerceCreatePromo($check['values']), $address);

        return;
    }

    $promo = commercePromoById($id);

    if ($op === 'save') {
        if ($promo === null) {
            finishAppAction(['ok' => false, 'error' => 'Правила уже нет — обновите список.'], $address, '');

            return;
        }

        $check = commerceValidatePromo($_POST);

        if ($check['errors'] !== []) {
            commercePromosRenderFailure($promo, $check['values'], $check['errors']);

            return;
        }

        commerceFinish(commerceUpdatePromo($id, $check['values']), $address);

        return;
    }

    if ($op === 'delete') {
        commerceFinish(
            $promo !== null ? commerceDeletePromo($id) : ['ok' => false, 'error' => 'Правила уже нет — обновите список.', 'notice' => ''],
            $address
        );

        return;
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие с правилом промо.'], $address, '');
}

/**
 * Экран промо-правил с отказом формы: набранное возвращается строками, причина видна.
 *
 * @param array{id: string, title: string, kind: string, value: float, min_total: ?float, starts_at: ?string, ends_at: ?string, active: bool}|null $promo
 * @param array{title: string, kind: string, value: float, min_total: ?float, starts: string, ends: string, active: int} $values
 * @param array<string, string> $errors
 */
function commercePromosRenderFailure(?array $promo, array $values, array $errors): void
{
    $form = [
        'title' => (string) $values['title'],
        'kind' => (string) $values['kind'],
        'value' => $values['value'] > 0 ? number_format((float) $values['value'], 2, ',', ' ') : '',
        'min_total' => $values['min_total'] !== null ? number_format((float) $values['min_total'], 2, ',', ' ') : '',
        'starts' => (string) $values['starts'],
        'ends' => (string) $values['ends'],
        'active' => (int) $values['active'],
    ];

    render('commerce/admin/promos', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => '',
        'promos' => commercePromosAll(),
        'mode' => $promo !== null ? 'edit' : 'new',
        'promo' => $promo,
        'form' => $form,
        'errors' => $errors,
    ], commerceAdminAlert()), ['title' => 'Промо-правила — магазин', 'noindex' => true]);
}

// ---------------------------------------------------------------------------
// Заказы
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceAdminOrders(array $params = []): void
{
    $statusRaw = strtoupper(trim((string) queryString('status', '')));
    $status = in_array($statusRaw, COMMERCE_ORDER_STATUSES, true) ? $statusRaw : '';
    $page = pageOf((string) queryString('page', '1'));
    $perPage = 20;

    $result = commerceOrdersList($status, $page, $perPage);
    $pages = max(1, (int) ceil($result['total'] / $perPage));

    $counts = [];
    foreach (COMMERCE_ORDER_STATUSES as $known) {
        $counts[$known] = (int) value(
            'SELECT COUNT(*) FROM ' . t('commerce_order') . ' WHERE status = ?',
            [$known]
        );
    }

    render('commerce/admin/orders', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'notice' => commerceAdminNotice(),
        'status' => $status,
        'counts' => $counts,
        'listing' => [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'pages' => $pages,
            'current' => min($page, $pages),
            'perPage' => $perPage,
        ],
    ], commerceAdminAlert()), ['title' => 'Заказы — магазин', 'noindex' => true]);
}

/** @param array<string, string> $params */
function commerceOrdersAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, 26);

    $result = match ($op) {
        'paid' => commerceSetOrderStatus($id, 'PAID'),
        'ship' => commerceSetOrderStatus($id, 'SHIPPED'),
        'deliver' => commerceSetOrderStatus($id, 'DONE'),
        'cancel' => commerceSetOrderStatus($id, 'CANCELLED'),
        default => ['ok' => false, 'error' => 'Неизвестное действие с заказом.', 'notice' => ''],
    };

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/commerce/admin/orders', $notice);
}


/**
 * Абзацы тела при отказе формы: значения читаются из сырого POST здесь, а не в шаблоне —
 * контракт «шаблон не читает $_POST» держится и для возврата набранного текста.
 *
 * @param array<string, mixed> $raw
 * @return list<array{title: string, text: string}>
 */
function commercePostedBody(array $raw): array
{
    $out = [];
    $titles = array_slice((array) ($raw['body_title'] ?? []), 0, 64);
    $texts = array_slice((array) ($raw['body_text'] ?? []), 0, 64);

    foreach ($texts as $i => $text) {
        $out[] = [
            'title' => (string) ($titles[$i] ?? ''),
            'text' => (string) $text,
        ];
    }

    return $out;
}
