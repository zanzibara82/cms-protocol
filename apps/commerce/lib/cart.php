<?php

declare(strict_types=1);

// Корзина живёт в сессии: «идентификатор позиции → количество». Позиция — товар без
// вариантов или строка «товар:вариант»; таблицы корзины нет — гостевой заказ рождается
// целиком при оформлении, а до этого цена и остаток читаются заново из базы, чтобы
// витрина не обещала проданное или снятое с продажи.
//
// Денежные суммы считаются в копейках (int): сложение float частей копеек ловит многократно
// описанную ошибку 0.1+0.2, и копейки копятся и печатаются один раз.
//
// Функции корзины сами вызывают startSession(): публичный обработчик не обязан помнить,
// что сессия может ещё не начаться.

/** Ключ позиции корзины: товар или строка «товар:вариант». */
function commerceCartKey(string $productId, string $variantId = ''): string
{
    return $variantId === '' ? $productId : $productId . ':' . $variantId;
}

/**
 * Разбор ключа позиции: [товар, вариант]. Ключ без разделителя — позиция товара.
 *
 * @return array{id: string, variant_id: string}
 */
function commerceCartKeyParts(string $key): array
{
    if (!str_contains($key, ':')) {
        return ['id' => $key, 'variant_id' => ''];
    }

    [$productId, $variantId] = explode(':', $key, 2);

    return ['id' => $productId, 'variant_id' => $variantId];
}

/** @return array<string, int> */
function commerceCartItemsMap(): array
{
    startSession();
    $cart = (array) ($_SESSION[COMMERCE_SESSION_CART] ?? []);
    $out = [];

    foreach ($cart as $id => $qty) {
        if (!is_string($id) || $id === '' || !is_int($qty) || $qty <= 0) {
            unset($_SESSION[COMMERCE_SESSION_CART][(string) $id]);

            continue;
        }
        $out[$id] = $qty;
    }

    return $out;
}

/**
 * Позиции корзины со свежими данными товара и варианта. Неопубликованные и раскупленные
 * позиции молча исчезают из списка: оформление при нехватке остатка даст человеческую
 * причину отказа, а не дежурное «заказ не принят».
 *
 * @return list<array{id: string, product_id: string, variant_id: string, slug: string, title: string, variant_title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}>
 */
function commerceCartLines(): array
{
    $cart = commerceCartItemsMap();
    if ($cart === []) {
        return [];
    }

    $products = [];
    $variants = [];

    foreach ($cart as $key => $qty) {
        $parts = commerceCartKeyParts($key);
        $products[$parts['id']] = true;

        if ($parts['variant_id'] !== '') {
            $variants[$parts['variant_id']] = true;
        }
    }

    $ids = array_keys($products);
    $placeholders = inPlaceholders(count($ids));
    $rows = all(
        'SELECT id, slug, title, sku, price_retail, price_promo, currency, stock'
        . ' FROM ' . t('commerce_product')
        . " WHERE id IN ($placeholders) AND status = 'PUBLISHED'",
        $ids
    );

    $byProduct = [];
    foreach ($rows as $row) {
        $byProduct[(string) $row['id']] = $row;
    }

    $byVariant = [];

    if ($variants !== []) {
        $variantsIds = array_keys($variants);
        $variantPlaceholders = inPlaceholders(count($variantsIds));
        $variantRows = all(
            sprintf(
                'SELECT id, product_id, title, sku, price_retail, price_promo, stock'
                . ' FROM %s WHERE id IN (%s)',
                t('commerce_variant'),
                $variantPlaceholders
            ),
            $variantsIds
        );

        foreach ($variantRows as $row) {
            $byVariant[(string) $row['id']] = $row;
        }
    }

    $lines = [];

    foreach ($cart as $key => $qty) {
        $parts = commerceCartKeyParts($key);
        $product = $byProduct[$parts['id']] ?? null;

        if ($product === null) {
            continue;
        }

        if ($parts['variant_id'] === '') {
            $qty = min($qty, (int) $product['stock']);
            $price = $product['price_promo'] !== null ? (float) $product['price_promo'] : (float) $product['price_retail'];

            $lines[] = [
                'id' => $key,
                'product_id' => $parts['id'],
                'variant_id' => '',
                'slug' => (string) $product['slug'],
                'title' => (string) $product['title'],
                'variant_title' => '',
                'sku' => (string) $product['sku'],
                'price_cents' => commerceCents($price),
                'currency' => (string) $product['currency'],
                'qty' => $qty,
                'stock' => (int) $product['stock'],
                'line_cents' => commerceCents($price) * $qty,
            ];

            continue;
        }

        $variant = $byVariant[$parts['variant_id']] ?? null;

        if ($variant === null || (string) $variant['product_id'] !== $parts['id']) {
            continue;
        }

        $qty = min($qty, (int) $variant['stock']);
        $price = $variant['price_promo'] !== null ? (float) $variant['price_promo'] : (float) $variant['price_retail'];

        $lines[] = [
            'id' => $key,
            'product_id' => $parts['id'],
            'variant_id' => $parts['variant_id'],
            'slug' => (string) $product['slug'],
            'title' => (string) $product['title'],
            'variant_title' => (string) $variant['title'],
            'sku' => (string) $variant['sku'],
            'price_cents' => commerceCents($price),
            'currency' => (string) $product['currency'],
            'qty' => $qty,
            'stock' => (int) $variant['stock'],
            'line_cents' => commerceCents($price) * $qty,
        ];
    }

    return $lines;
}

/**
 * Сумма корзины копейками. Одна валюта на корзину: разнородные валюты ловит оформление
 * и просит разделить заказ, а не считает «₽ + $».
 *
 * @return array{total_cents: int, count: int}
 */
function commerceCartTotal(): array
{
    $total = 0;
    $count = 0;

    foreach (commerceCartLines() as $line) {
        $total += $line['line_cents'];
        $count += $line['qty'];
    }

    return ['total_cents' => $total, 'count' => $count];
}

/**
 * Положить позицию в корзину: товар без вариантов или строку «товар:вариант».
 * Причина отказа печатается на карточке — недоступное позицию человек видит, а не
 * догадывается, почему кнопка «молчит».
 *
 * @return array{ok: bool, error: string, count: int}
 */
function commerceCartAdd(string $productId, int $qty, string $variantId = ''): array
{
    $cart = commerceCartItemsMap();
    $count = array_sum($cart);
    $key = commerceCartKey($productId, $variantId);

    if (count($cart) >= COMMERCE_CART_MAX_LINES && !isset($cart[$key])) {
        return [
            'ok' => false,
            'error' => 'В корзине уже максимум позиций (' . COMMERCE_CART_MAX_LINES . ').',
            'count' => $count,
        ];
    }

    $stock = 0;

    if ($variantId === '') {
        $product = one(
            'SELECT stock FROM ' . t('commerce_product') . " WHERE id = ? AND status = 'PUBLISHED'",
            [$productId]
        );

        if ($product === null) {
            return ['ok' => false, 'error' => 'Товар недоступен для покупки.', 'count' => $count];
        }

        $stock = (int) $product['stock'];
    } else {
        $variant = one(
            'SELECT stock FROM ' . t('commerce_variant') . ' WHERE id = ? AND product_id = ?',
            [$variantId, $productId]
        );

        if ($variant === null) {
            return ['ok' => false, 'error' => 'Такого варианта уже нет.', 'count' => $count];
        }

        $stock = (int) $variant['stock'];
    }

    $qty = max(1, min($qty, COMMERCE_CART_MAX_QTY));
    $wish = ($cart[$key] ?? 0) + $qty;

    if ($wish > COMMERCE_CART_MAX_QTY) {
        $wish = COMMERCE_CART_MAX_QTY;
    }

    if ($wish > $stock) {
        $wish = $stock;
    }

    if ($wish <= 0) {
        return ['ok' => false, 'error' => 'Товар распродан.', 'count' => $count];
    }

    startSession();

    if ($wish === ($cart[$key] ?? 0)) {
        return [
            'ok' => false,
            'error' => 'Больше столько не продаётся — в корзине уже весь остаток.',
            'count' => $count,
        ];
    }

    $_SESSION[COMMERCE_SESSION_CART][$key] = $wish;

    return [
        'ok' => true,
        'error' => '',
        'count' => array_sum($_SESSION[COMMERCE_SESSION_CART]),
    ];
}

/** Задать количество позиции; ноль удаляет позицию. Остатка здесь не читают: экран
 * корзины печатает позиции вместе с остатком, а недоступное количество оформление
 * ловит по свежему остатку. */
function commerceCartSet(string $key, int $qty): void
{
    $cart = commerceCartItemsMap();

    if (!isset($cart[$key])) {
        return;
    }

    startSession();

    if ($qty <= 0) {
        unset($_SESSION[COMMERCE_SESSION_CART][$key]);

        return;
    }

    $_SESSION[COMMERCE_SESSION_CART][$key] = min($qty, COMMERCE_CART_MAX_QTY);
}

/** Опустошить корзину: после оформления и по кнопке экрана. Скидка купона
 * живёт корзиной — пустую корзину код не отработает.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCartClear(): array
{
    startSession();
    $_SESSION[COMMERCE_SESSION_CART] = [];
    commerceSessionCouponDrop();

    return ['ok' => true, 'error' => '', 'notice' => 'cart_cleared'];
}

/**
 * Сводка корзины со скидками (M4): подытог по позициям, автоскидка лучшего работающего
 * промо-правила, скидка купона с сессии и итог к оплате. Купон проверяется на свежей
 * строке базы — снятый, истёкший или исчерпанный код причину отказа отдаёт словами,
 * и корзина печатает её рядом с формой. Скидки вместе не уводят корзину в минус.
 *
 * @return array{subtotal_cents: int, count: int, currency: string, coupon_code: string, coupon_error: string, coupon_discount_cents: int, promo_title: string, promo_discount_cents: int, total_cents: int}
 */
function commerceCartTotals(): array
{
    $lines = commerceCartLines();
    $subtotal = 0;
    $count = 0;
    $currency = COMMERCE_CURRENCY_DEFAULT;

    foreach ($lines as $line) {
        $subtotal += $line['line_cents'];
        $count += $line['qty'];
        $currency = (string) $line['currency'];
    }

    $couponCode = commerceSessionCouponCode();
    $couponDiscount = 0;
    $couponError = '';

    if ($couponCode !== '' && $subtotal > 0) {
        $coupon = commerceCouponByCode($couponCode);
        $usable = commerceCouponUsable($coupon, $subtotal, $currency);

        if ($usable['ok']) {
            $couponDiscount = (int) commerceCouponDiscountCents((array) $coupon, $subtotal);
        } else {
            $couponError = (string) $usable['error'];
        }
    }

    $promo = commerceBestPromoDiscount(commerceActivePromos(), $subtotal);
    $promoTitle = (string) ($promo['title'] ?? '');
    $promoDiscount = (int) ($promo['discount_cents'] ?? 0);
    $total = max(0, $subtotal - $couponDiscount - $promoDiscount);

    return [
        'subtotal_cents' => $subtotal,
        'count' => $count,
        'currency' => $currency,
        'coupon_code' => $couponCode,
        'coupon_error' => $couponError,
        'coupon_discount_cents' => $couponDiscount,
        'promo_title' => $promoTitle,
        'promo_discount_cents' => $promoDiscount,
        'total_cents' => $total,
    ];
}

/** Деньги в копейки: единственная точка округления, чтобы float не гулял по разделу. */
function commerceCents(float $amount): int
{
    return (int) round($amount * 100);
}

/** Копейки в человекочитаемую строку: «2 490,00 ₽». */
function commerceFormatMoney(int $cents, string $currency): string
{
    $symbol = COMMERCE_CURRENCY_SYMBOLS[$currency] ?? ' ' . $currency;
    $rub = intdiv($cents, 100);
    $kop = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

    return number_format($rub, 0, ' ', ' ') . ',' . $kop . $symbol;
}

/** @var array<string, string> */
const COMMERCE_CURRENCY_SYMBOLS = [
    'RUB' => ' ₽',
    'USD' => ' $',
    'EUR' => ' €',
];
