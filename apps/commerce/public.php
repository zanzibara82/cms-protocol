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
    $filters = commerceCategoryFilters((string) $category['id']);
    $list = commerceProductList([
        'category' => (string) $category['id'],
        'published_only' => true,
        'page' => $page,
        'per_page' => COMMERCE_SHOP_PER_PAGE,
        'attributes' => $filters['conditions'],
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
        'filters' => $filters,
        'cartCount' => commerceCartTotal()['count'],
    ], [
        'title' => (string) $category['title'] . ' — магазин',
        'noindex' => false,
    ]);
}

/**
 * Фильтры витрины рубрики: по характеристикам этой рубрики читаются параметры адреса.
 * Значение CHOICE идёт строкой, NUMBER — границами от/до; всё, что не разобралось
 * числом, фильтром не считается — список просто показывает без этой границы.
 *
 * @return array{rows: list<array{id: string, key: string, label: string, type: string, unit: ?string, choices: list<string>, raw: string, from: string, to: string}>, conditions: list<array{attribute_id: string, value: string, from: ?float, to: ?float}>, chips: list<array{label: string, value: string, query: string}>, query: string}
 */
function commerceCategoryFilters(string $categoryId): array
{
    $rows = [];
    $conditions = [];
    $kept = [];

    foreach (commerceAttributes($categoryId) as $attribute) {
        $type = (string) $attribute['type'];
        $key = 'attr_' . (string) $attribute['slug'];
        $label = (string) $attribute['label'];
        $raw = '';
        $from = '';
        $to = '';

        if ($type === 'CHOICE') {
            $raw = trim((string) queryString($key, ''));
            $raw = mb_substr($raw, 0, 300);

            if ($raw !== '') {
                $conditions[] = [
                    'attribute_id' => (string) $attribute['id'],
                    'value' => $raw,
                    'from' => null,
                    'to' => null,
                ];
                $kept[$key] = ['label' => $label, 'value' => $raw];
            }
        } else {
            $from = trim((string) queryString($key . '_from', ''));
            $to = trim((string) queryString($key . '_to', ''));
            $fromValue = commerceFilterNumber($from);
            $toValue = commerceFilterNumber($to);

            if ($fromValue !== null) {
                $kept[$key . '_from'] = ['label' => $label . ' (от)', 'value' => $from];
            }

            if ($toValue !== null) {
                $kept[$key . '_to'] = ['label' => $label . ' (до)', 'value' => $to];
            }

            if ($fromValue !== null || $toValue !== null) {
                $conditions[] = [
                    'attribute_id' => (string) $attribute['id'],
                    'value' => '',
                    'from' => $fromValue,
                    'to' => $toValue,
                ];
            }
        }

        $choices = [];

        if ($type === 'CHOICE') {
            $choices = array_map(
                static fn (array $row): string => (string) $row['value'],
                commerceAttributeChoices((string) $attribute['id'])
            );
        }

        $rows[] = [
            'id' => (string) $attribute['id'],
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'unit' => $attribute['unit'],
            'choices' => $choices,
            'raw' => $raw,
            'from' => $from,
            'to' => $to,
        ];
    }

    // Ссылка снятия фильтра: все остальные выбранные значения остаются в адресе.
    $chips = [];
    foreach ($kept as $key => $entry) {
        $others = $kept;
        unset($others[$key]);

        $otherParams = [];
        foreach ($others as $otherKey => $otherEntry) {
            $otherParams[$otherKey] = (string) $otherEntry['value'];
        }

        $chips[] = [
            'label' => (string) $entry['label'],
            'value' => (string) $entry['value'],
            'query' => http_build_query($otherParams),
        ];
    }

    $keptParams = [];
    foreach ($kept as $key => $entry) {
        $keptParams[$key] = (string) $entry['value'];
    }

    return [
        'rows' => $rows,
        'conditions' => $conditions,
        'chips' => $chips,
        'query' => http_build_query($keptParams),
    ];
}

/** Разбор границы числа для фильтра: «1 250» и «1 250,5» понимаются, мусор — нет. */
function commerceFilterNumber(string $raw): ?float
{
    $cleaned = str_replace(' ', '', $raw);

    if ($cleaned === '' || preg_match('/^\d+([.,]\d+)?$/', $cleaned) !== 1) {
        return null;
    }

    return (float) str_replace(',', '.', $cleaned);
}

/** @param array<string, string> $params */
function commerceProductPage(array $params = []): void
{
    $slug = trim((string) ($params['slug'] ?? ''));
    $product = commerceProductBySlug($slug);

    if ($product === null) {
        notFound();
    }

    $currency = (string) $product['currency'];
    $variants = commerceVariantOptions((string) $product['id']);

    if ($variants === []) {
        $cents = $product['price_promo'] !== null
            ? commerceCents((float) $product['price_promo'])
            : commerceCents((float) $product['price_retail']);
    } else {
        // Товар с вариантами продаётся по ценам вариантов: «от — до» честнее, чем
        // устаревшая цена родителя.
        $prices = array_map(static fn (array $row): int => (int) $row['price_cents'], $variants);
        $cents = min($prices);
    }

    commerceProductHead($product, $cents, $variants);

    render('commerce/product', [
        'product' => $product,
        'categories' => commerceProductCategories((string) $product['id']),
        'priceHtml' => commerceFormatMoney($cents, $currency),
        'priceFrom' => $variants !== [],
        'inStock' => (int) $product['stock'] > 0,
        'variants' => $variants,
        'specs' => commerceProductAttributes((string) $product['id']),
        'cartCount' => commerceCartTotal()['count'],
    ], [
        'title' => (string) ($product['seo_title'] !== null ? $product['seo_title'] : $product['title']) . ' — магазин',
        'noindex' => (bool) $product['noindex'],
    ]);
}

/**
 * JSON-LD карточки товара из фактических данных базы: без выдуманных рейтингов, отзывов
 * и доставки — ТЗ магазина требует честной разметки. Товар с вариантами получает
 * AggregateOffer (границы цен variants видны роботу), товар без вариантов — обычный Offer.
 *
 * @param array<string, mixed> $product
 * @param list<array{id: string, sku: string, title: string, price_cents: int, price_retail_cents: int, currency: string, stock: int, in_stock: bool}> $variantOptions
 * @return array<string, mixed>
 */
function commerceProductJsonLd(array $product, int $priceCents, array $variantOptions = []): array
{
    if ($variantOptions === []) {
        $offered = [
            '@type' => 'Offer',
            'priceCurrency' => (string) $product['currency'],
            'price' => number_format($priceCents / 100, 2, '.', ''),
        ];

        if (array_key_exists('stock', $product)) {
            $offered['availability'] = ((int) $product['stock'] > 0
                ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock');
        }
    } else {
        $prices = array_map(static fn (array $row): int => (int) $row['price_cents'], $variantOptions);
        $offered = [
            '@type' => 'AggregateOffer',
            'priceCurrency' => (string) $product['currency'],
            'lowPrice' => number_format(min($prices) / 100, 2, '.', ''),
            'highPrice' => number_format(max($prices) / 100, 2, '.', ''),
            'offerCount' => count($variantOptions),
        ];
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
 * @param list<array{id: string, sku: string, title: string, price_cents: int, price_retail_cents: int, currency: string, stock: int, in_stock: bool}> $variantOptions
 */
function commerceProductHead(array $product, int $priceCents, array $variantOptions = []): void
{
    addAction('page_head', static function () use ($product, $priceCents, $variantOptions): void {
        $head = commerceJsonLdTag(commerceProductJsonLd($product, $priceCents, $variantOptions));

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
    $variantId = mb_substr(trim((string) ($_POST['variant'] ?? '')), 0, 26);
    $qty = (int) ($_POST['qty'] ?? 1);
    $qty = max(1, min($qty, COMMERCE_CART_MAX_QTY));
    $back = isInternalNextPath((string) ($_POST['back'] ?? '')) ? (string) $_POST['back'] : '/shop';

    // Куда вернуться: если товар был передан с карточки — назад к нему; причина отказа
    // печатается зелёной/красной плашкой текущего раздела.
    $saved = commerceCartAdd($id, $qty, $variantId);

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

    // Ключ позиции вида «товар» или «товар:вариант»: длина правится символами
    // и форматом, а не обрезанием — собранный вручную ключ вслепую не принимается.
    $id = trim((string) ($_POST['id'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9]{1,26}(:[A-Za-z0-9]{1,26})?$/', $id)) {
        redirect('/shop/cart');
    }

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
// Состояние заказа для гостя (номер + e-mail)
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function commerceOrderLookupPage(array $params = []): void
{
    render('commerce/order-lookup', [
        'values' => ['number' => '', 'customer_email' => ''],
        'errors' => [],
        'notice' => '',
    ], ['title' => 'Состояние заказа — магазин']);
}

/**
 * Гостевой поиск заказа: совпали номер и e-mail — открываем подтверждение,
 * отметив номер за этой сессией. Пять провалов подряд замораживают форму на
 * десять минут: адрес не становится переборщиком чужих заказов.
 *
 * @param array<string, string> $params
 */
function commerceOrderLookupAction(array $params = []): void
{
    csrfCheck();
    startSession();
    $check = commerceValidateLookup($_POST);

    if ($check['errors'] !== []) {
        commerceLookupRender($check['values'], $check['errors']);

        return;
    }

    $values = $check['values'];
    $frozen = (int) ($_SESSION[COMMERCE_SESSION_LOOKUP_UNTIL] ?? 0);
    $moment = nowUtc()->getTimestamp();

    if ($frozen > $moment) {
        $minutes = (int) ceil(($frozen - $moment) / 60);
        commerceLookupRender($values, ['form' => 'Форма временно закрыта — повторите через ' . $minutes . ' мин.']);

        return;
    }

    $order = commerceOrderByNumber((int) $values['number']);
    $matches = $order !== null
        && mb_strtolower((string) $order['customer_email']) === mb_strtolower($values['customer_email']);

    if ($matches) {
        unset($_SESSION[COMMERCE_SESSION_LOOKUP_FAILS], $_SESSION[COMMERCE_SESSION_LOOKUP_UNTIL]);
        commerceOrderConfirmSessionMark((int) $values['number']);
        redirect('/shop/order/' . $values['number']);
    }

    $fails = (int) ($_SESSION[COMMERCE_SESSION_LOOKUP_FAILS] ?? 0) + 1;
    $_SESSION[COMMERCE_SESSION_LOOKUP_FAILS] = $fails;

    if ($fails >= COMMERCE_LOOKUP_MAX_FAILS) {
        $_SESSION[COMMERCE_SESSION_LOOKUP_UNTIL] = $moment + COMMERCE_LOOKUP_COOLDOWN_MIN * 60;
        commerceLookupRender($values, ['form' => 'Форма временно закрыта — повторите через ' . COMMERCE_LOOKUP_COOLDOWN_MIN . ' мин.']);

        return;
    }

    commerceLookupRender($values, ['form' => 'Такого сочетания номера и e-mail нет — сверьтесь с письмом о заказе.']);
}

/**
 * Рендер экрана с отказом и набранным текстом.
 * @param array{number: string, customer_email: string} $values
 * @param array<string, string> $errors
 */
function commerceLookupRender(array $values, array $errors): void
{
    render('commerce/order-lookup', [
        'values' => $values,
        'errors' => $errors,
        'notice' => '',
    ], ['title' => 'Состояние заказа — магазин']);
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
