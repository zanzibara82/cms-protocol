<?php

declare(strict_types=1);

// Корзина живёт в сессии: «id товара → количество». Таблицы корзины нет — гостевой заказ
// рождается целиком при оформлении, а до этого цена и остаток читаются заново из базы,
// чтобы витрина не обещала проданное или снятое с продажи.
//
// Денежные суммы считаются в копейках (int): сложение float частей копеек ловит многократно
// описанную ошибку 0.1+0.2, и копейки копятся и печатаются один раз.
//
// Функции корзины сами вызывают startSession(): публичный обработчик не обязан помнить,
// что сессия может ещё не начаться.

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
 * Позиции корзины со свежими данными товара. Неопубликованные и раскупленные позиции
 * молча не съедаются: их нет в списке, но оформление при нехватке остатка умрёт с
 * человеческой причиной отказа, а не с дежурным «заказ не принят».
 *
 * @return list<array{id: string, slug: string, title: string, sku: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}>
 */
function commerceCartLines(): array
{
    $cart = commerceCartItemsMap();
    if ($cart === []) {
        return [];
    }

    $ids = array_keys($cart);
    $placeholders = inPlaceholders(count($ids));
    $rows = all(
        'SELECT id, slug, title, sku, price_retail, price_promo, currency, stock'
        . ' FROM ' . t('commerce_product')
        . " WHERE id IN ($placeholders) AND status = 'PUBLISHED'",
        $ids
    );

    $lines = [];
    $byId = [];
    foreach ($rows as $row) {
        $byId[(string) $row['id']] = $row;
    }

    foreach ($cart as $id => $qty) {
        $row = $byId[$id] ?? null;
        if ($row === null) {
            continue;
        }

        $qty = min($qty, (int) $row['stock']);
        $price = $row['price_promo'] !== null ? (float) $row['price_promo'] : (float) $row['price_retail'];

        $lines[] = [
            'id' => $id,
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'sku' => (string) $row['sku'],
            'price_cents' => commerceCents($price),
            'currency' => (string) $row['currency'],
            'qty' => $qty,
            'stock' => (int) $row['stock'],
            'line_cents' => commerceCents($price) * $qty,
        ];
    }

    return $lines;
}

/**
 * Сумма корзины копейками. Одна валюта в M1: разнородные валюты ловит оформление и
 * просит разделить заказ, а не считает «₽ + $».
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
 * Положить товар в корзину. Причина отказа печатается на карточке: недоступный товар
 * человек видит, а не догадывается, почему кнопка «молчит».
 *
 * @return array{ok: bool, error: string, count: int}
 */
function commerceCartAdd(string $productId, int $qty): array
{
    $cart = commerceCartItemsMap();
    $count = array_sum($cart);

    if (count($cart) >= COMMERCE_CART_MAX_LINES && !isset($cart[$productId])) {
        return [
            'ok' => false,
            'error' => 'В корзине уже максимум позиций (' . COMMERCE_CART_MAX_LINES . ').',
            'count' => $count,
        ];
    }

    $row = one(
        'SELECT stock FROM ' . t('commerce_product') . " WHERE id = ? AND status = 'PUBLISHED'",
        [$productId]
    );

    if ($row === null) {
        return ['ok' => false, 'error' => 'Товар недоступен для покупки.', 'count' => $count];
    }

    $qty = max(1, min($qty, COMMERCE_CART_MAX_QTY));
    $wish = ($cart[$productId] ?? 0) + $qty;

    if ($wish > COMMERCE_CART_MAX_QTY) {
        $wish = COMMERCE_CART_MAX_QTY;
    }

    if ($wish > (int) $row['stock']) {
        $wish = (int) $row['stock'];
    }

    if ($wish <= 0) {
        return ['ok' => false, 'error' => 'Товар распродан.', 'count' => $count];
    }

    startSession();

    if ($wish === ($cart[$productId] ?? 0)) {
        return [
            'ok' => false,
            'error' => 'Больше столько не продаётся — в корзине уже весь остаток.',
            'count' => $count,
        ];
    }

    $_SESSION[COMMERCE_SESSION_CART][$productId] = $wish;

    return [
        'ok' => true,
        'error' => '',
        'count' => array_sum($_SESSION[COMMERCE_SESSION_CART]),
    ];
}

/** Задать количество позиции; ноль удаляет позицию. Остатка здесь не читают: экран
 * корзины печатает позиции вместе с остатком, а недоступное количество оформление
 * ловит по свежему остатку. */
function commerceCartSet(string $productId, int $qty): void
{
    $cart = commerceCartItemsMap();

    if (!isset($cart[$productId])) {
        return;
    }

    startSession();

    if ($qty <= 0) {
        unset($_SESSION[COMMERCE_SESSION_CART][$productId]);

        return;
    }

    $_SESSION[COMMERCE_SESSION_CART][$productId] = min($qty, COMMERCE_CART_MAX_QTY);
}

/** Опустошить корзину: после оформления и по кнопке экрана.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCartClear(): array
{
    startSession();
    $_SESSION[COMMERCE_SESSION_CART] = [];

    return ['ok' => true, 'error' => '', 'notice' => 'cart_cleared'];
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
