<?php

declare(strict_types=1);

// Экраны администратора магазина. Гейт маршрута ('permission' => 'login') пускает за
// клавиатуру, POST-действия проверяют токен (csrfCheck) и свой результат отдают
// finishAppAction(): отказ гасит список с причиной, успех — с ключом уведомления.

const COMMERCE_NAV_PATHS = [
    '/commerce/admin' => 'Обзор',
    '/commerce/admin/products' => 'Товары',
    '/commerce/admin/categories' => 'Рубрики',
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

    render('commerce/admin/product-form', array_merge([
        'nav' => commerceAdminNav(requestPath()),
        'mode' => 'edit',
        'product' => $product,
        'form' => [],
        'errors' => [],
        'values' => [],
        'categories' => commerceCategories(),
        'chosen' => array_map('strval', array_column(commerceProductCategories($id), 'id')),
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
        'ship' => commerceSetOrderStatus($id, 'SHIPPED'),
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
