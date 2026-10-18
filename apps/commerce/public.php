<?php

declare(strict_types=1);

// Публичное чтение магазина: витрина, рубрика, товар с JSON-LD, корзина, оформление
// и подтверждение заказа. Каждый экран решает сам, запускается ли сессия — функции
// корзины запускают её лениво.

const COMMERCE_SHOP_PER_PAGE = 12;

/**
 * Уведомление от ключа адреса: словарь общий с админкой (одна таблица честных формулировок),
 * неизвестные ключи не печатаются.
 */
function commerceStoreNotice(): string
{
    return (string) (noticeOfRequest(COMMERCE_NOTICE_TEXT) ?? '');
}

/** @param array<string, string> $params */
function commerceShopPage(array $params = []): void
{
    $page = pageOf((string) queryString('page', '1'));
    $list = commerceProductList(['published_only' => true, 'page' => $page, 'per_page' => COMMERCE_SHOP_PER_PAGE]);
    $pages = max(1, (int) ceil($list['total'] / COMMERCE_SHOP_PER_PAGE));

    render('commerce/shop', [
        'heading' => 'Каталог',
        'intro' => 'Товары магазина к самовывозу и заказам.',
        'categories' => commerceCategories(true),
        'list' => [
            'rows' => $list['rows'],
            'total' => $list['total'],
            'pages' => $pages,
            'current' => min($page, $pages),
        ],
        'cartCount' => commerceCartTotal()['count'],
        'notice' => commerceStoreNotice(),
    ], ['title' => 'Каталог — магазин']);
}

/** @param array<string, string> $params */
function commerceCategoryPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $category = commerceCategory($slug);

    if ($category === null || !((int) $category['visible'] === 1)) {
        notFound();
    }

    $page = pageOf((string) queryString('page', '1'));
    $list = commerceProductList([
        'category' => (string) $category['id'],
        'published_only' => true,
        'page' => $page,
        'per_page' => COMMERCE_SHOP_PER_PAGE,
    ]);
    $pages = max(1, (int) ceil($list['total'] / COMMERCE_SHOP_PER_PAGE));

    render('commerce/category', [
        'category' => $category,
        'categories' => commerceCategories(true),
        'list' => [
            'rows' => $list['rows'],
            'total' => $list['total'],
            'pages' => $pages,
            'current' => min($page, $pages),
        ],
        'cartCount' => commerceCartTotal()['count'],
    ], [
        'title' => (string) $category['title'] . ' — магазин',
        'noindex' => false,
    ]);
}

/** @param array<string, string> $params */
function commerceProductPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $product = commerceProductBySlug($slug);

    if ($product === null) {
        notFound();
    }

    $cents = $product['price_promo'] !== null
        ? commerceCents((float) $product['price_promo'])
        : commerceCents((float) $product['price_retail']);
    $currency = (string) $product['currency'];

    commerceProductHead($product, $cents);

    render('commerce/product', [
        'product' => $product,
        'categories' => commerceProductCategories((string) $product['id']),
        'priceHtml' => commerceFormatMoney($cents, $currency),
        'inStock' => (int) $product['stock'] > 0,
        'cartCount' => commerceCartTotal()['count'],
    ], [
        'title' => (string) ($product['seo_title'] !== null ? $product['seo_title'] : $product['title']) . ' — магазин',
        'noindex' => (bool) $product['noindex'],
    ]);
}

/**
 * JSON-LD карточки товара из фактических данных базы: без выдуманных рейтингов, отзывов
 * и доставки — ТЗ магазина требует честной разметки. Цена — на момент запроса.
 *
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function commerceProductJsonLd(array $product, int $priceCents): array
{
    $offered = [
        '@type' => 'Offer',
        'priceCurrency' => (string) $product['currency'],
        'price' => number_format($priceCents / 100, 2, '.', ''),
    ];

    if (array_key_exists('stock', $product)) {
        $offered['availability'] = ((int) $product['stock'] > 0
            ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock');
    }

    $out = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => (string) $product['title'],
        'sku' => (string) $product['sku'],
        'offers' => $offered,
    ];

    if (isset($product['slug'])) {
        $out['url'] = absoluteUrl('/shop/' . rawurlencode((string) $product['slug']));
    }

    $brand = (string) ($product['brand'] ?? '');
    if ($brand !== '') {
        $out['brand'] = ['@type' => 'Brand', 'name' => $brand];
    }

    $lead = (string) ($product['lead_text'] ?? '');
    if ($lead !== '') {
        $out['description'] = $lead;
    }

    return $out;
}

/**
 * Тег JSON-LD: HEX_TAG/HEX_AMP не пускают «</script>» и «&amp;»-подобные последовательности
 * внутрь блока — заголовок товара не может разорвать структуру страницы.
 *
 * @param array<string, mixed> $ld
 */
function commerceJsonLdTag(array $ld): string
{
    try {
        $json = json_encode(
            $ld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        logWarn('commerce_ld_broken', ['reason' => $error->getMessage()]);

        return '';
    }

    return '<script type="application/ld+json">' . $json . '</script>';
}

/** JSON-LD товара печатается в head через хук page_head: серверная разметка живёт в head, а шаблон не склеивает script-блок руками.
 * @param array<string, mixed> $product
 */
function commerceProductHead(array $product, int $priceCents): void
{
    addAction('page_head', static function () use ($product, $priceCents): void {
        $head = commerceJsonLdTag(commerceProductJsonLd($product, $priceCents));

        echo $head; /* @safe html */
    });
}

// ---------------------------------------------------------------------------
// Корзина и оформление
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceCartPage(array $params = []): void
{
    $total = commerceCartTotal();

    render('commerce/cart', [
        'lines' => commerceCartLines(),
        'totalCents' => $total['total_cents'],
        'cartCount' => $total['count'],
        'values' => [],
        'errors' => [],
        'notice' => commerceStoreNotice(),
        'cartError' => commerceCartErrorFlash(),
    ], ['title' => 'Корзина — магазин']);
}

/** @param array<string, string> $params */
function commerceCartAddAction(array $params = []): void
{
    csrfCheck();
    $id = mb_substr(trim((string) ($_POST['id'] ?? '')), 0, 26);
    $qty = (int) ($_POST['qty'] ?? 1);
    $qty = max(1, min($qty, COMMERCE_CART_MAX_QTY));
    $back = isInternalNextPath((string) ($_POST['back'] ?? '')) ? (string) $_POST['back'] : '/shop';

    // Куда вернуться: если товар был передан с карточки — назад к нему; причина отказа
    // печатается зелёной/красной плашкой текущего раздела.
    $saved = commerceCartAdd($id, $qty);

    if (!$saved['ok']) {
        startSession();
        // Причина отказа продаётся один раз: экран корзины читает и снимает flash.
        $_SESSION['commerce_cart_error'] = mb_substr((string) $saved['error'], 0, 200);
    }

    redirect('/shop/cart');
}

/** Причина отказа добавления в корзину: читается один раз и снимается с сессии. */
function commerceCartErrorFlash(): string
{
    startSession();
    $reason = is_string($_SESSION['commerce_cart_error'] ?? null) ? (string) $_SESSION['commerce_cart_error'] : '';
    unset($_SESSION['commerce_cart_error']);

    return $reason;
}

/** @param array<string, string> $params */
function commerceCartUpdateAction(array $params = []): void
{
    csrfCheck();
    $op = (string) ($_POST['op'] ?? '');
    $id = mb_substr(trim((string) ($_POST['id'] ?? '')), 0, 26);

    if ($op === 'set') {
        $qty = (int) ($_POST['qty'] ?? 0);
        commerceCartSet($id, max(0, min($qty, COMMERCE_CART_MAX_QTY)));
    } elseif ($op === 'remove') {
        commerceCartSet($id, 0);
    } elseif ($op === 'clear') {
        $cleared = commerceCartClear();
        $notice = (string) ($cleared['notice'] ?? '');

        redirectWithNotice('/shop/cart', $notice);
    }

    redirect('/shop/cart');
}

/**
 * Оформление: сборка заказа целостна (см. commerceOrderCreate). После успеха корзина
 * пуста, а номер заказа остаётся отмеченной строкой этой сессии — страницу подтверждения
 * открывают по нему.
 *
 * @param array<string, string> $params
 */
function commerceOrderAction(array $params = []): void
{
    csrfCheck();
    $check = commerceValidateCheckout($_POST);

    if ($check['errors'] !== []) {
        $total = commerceCartTotal();
        render('commerce/cart', [
            'lines' => commerceCartLines(),
            'totalCents' => $total['total_cents'],
            'cartCount' => $total['count'],
            'values' => $check['values'],
            'errors' => $check['errors'],
            'notice' => '',
            'cartError' => '',
        ], ['title' => 'Корзина — магазин']);

        return;
    }

    $order = commerceOrderCreate($check['values'], commerceCartLines());

    if ($order['ok']) {
        commerceOrderConfirmSessionMark($order['number']);
        redirect('/shop/order/' . $order['number']);
    }

    // Часть товара раскуплена во время заполнения: экран просит поправить количество.
    $total = commerceCartTotal();
    render('commerce/cart', [
        'lines' => commerceCartLines(),
        'totalCents' => $total['total_cents'],
        'cartCount' => $total['count'],
        'values' => $check['values'],
        'errors' => ['form' => $order['error']],
        'notice' => '',
        'cartError' => '',
    ], ['title' => 'Корзина — магазин']);
}

/**
 * Подтверждение заказа. Гостю показывается сводка созданного заказа: страница требует
 * отметки сессии (commerceOrderConfirmAllowed) — посторонние по прямому адресу номер
 * не увидят.
 *
 * @param array<string, string> $params
 */
function commerceOrderConfirmPage(array $params = []): void
{
    if (!ctype_digit((string) ($params['number'] ?? ''))) {
        notFound();
    }

    $number = (int) $params['number'];
    $order = commerceOrderByNumber($number);

    if ($order === null || !commerceOrderConfirmAllowed($number)) {
        notFound();
    }

    render('commerce/order', [
        'order' => $order,
        'items' => commerceOrderItems((string) $order['id']),
    ], ['title' => 'Заказ №' . $order['number'] . ' — магазин', 'noindex' => true]);
}

// ---------------------------------------------------------------------------
// Карта сайтов
// ---------------------------------------------------------------------------

/** @return list<array{loc: string, lastmod?: string}> */
function commerceSitemapUrls(): array
{
    $rows = all(
        'SELECT slug, updated_at FROM ' . t('commerce_product')
        . " WHERE status = 'PUBLISHED' AND noindex = 0"
        . ' ORDER BY updated_at DESC LIMIT 5000'
    );

    $out = [];
    foreach (array_filter($rows, static fn (array $row): bool => $row['slug'] !== '') as $row) {
        /** @var array{loc: string, lastmod?: string} $entry */
        $entry = ['loc' => '/shop/' . rawurlencode((string) $row['slug'])];
        $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

        if ($updated !== null) {
            $entry['lastmod'] = substr(moscowIso($updated), 0, 10);
        }

        $out[] = $entry;
    }

    return $out;
}
