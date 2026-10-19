<?php

declare(strict_types=1);

// Варианты товара (M2): свой SKU, цена и остаток при одном родительском товаре.
// Например «красная колонка 20 Вт» — тот же товар, что и «30 Вт», но продавец
// отдельно. Товар без вариантов продаётся сам: витрина читает его поля, как в M1.

/**
 * Список вариантов товара: порядок задаёт человек, новые приходят в конец.
 *
 * @return list<array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}>
 */
function commerceVariants(string $productId): array
{
    $rows = all(
        'SELECT id, product_id, sku, title, price_retail, price_promo, stock, position'
        . ' FROM ' . t('commerce_variant')
        . ' WHERE product_id = ? ORDER BY position, sku',
        [$productId]
    );

    /** @var list<array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}> */
    return $rows;
}

/** @return array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}|null */
function commerceVariantById(string $id): ?array
{
    $row = one(
        'SELECT id, product_id, sku, title, price_retail, price_promo, stock, position'
        . ' FROM ' . t('commerce_variant')
        . ' WHERE id = ?',
        [$id]
    );

    /** @var array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}|null */
    return $row;
}

/** Есть ли у товара варианты: витрина и админка ходят по этому признаку. */
function commerceHasVariants(string $productId): bool
{
    return value(
        'SELECT COUNT(*) FROM ' . t('commerce_variant') . ' WHERE product_id = ?',
        [$productId]
    ) > 0;
}

/**
 * Разбор POST формы варианта. Вина пишется русским текстом по человеческим полям,
 * а не инженерными кодами.
 *
 * @param string $productId
 * @param array<string, mixed> $post
 * @return array{errors: array<string, string>, values: array{title: string, sku: string, price_retail: string, price_promo: string, stock: string}}
 */
function commerceValidateVariant(string $productId, array $post): array
{
    $values = [
        'title' => trim((string) ($post['title'] ?? '')),
        'sku' => trim((string) ($post['sku'] ?? '')),
        'price_retail' => trim((string) ($post['price_retail'] ?? '')),
        'price_promo' => trim((string) ($post['price_promo'] ?? '')),
        'stock' => trim((string) ($post['stock'] ?? '0')),
    ];
    $errors = [];

    if ($values['title'] === '') {
        $errors['title'] = 'Опишите вариант — например цвет и размер.';
    } elseif (mb_strlen($values['title']) > COMMERCE_TITLE_MAX) {
        $errors['title'] = 'Название варианта не длиннее ' . COMMERCE_TITLE_MAX . ' символов.';
    }

    if ($values['sku'] === '') {
        $errors['sku'] = 'Укажите артикул варианта — по нему товар отгружают.';
    } elseif (mb_strlen($values['sku']) > COMMERCE_SKU_MAX) {
        $errors['sku'] = 'Артикул не длиннее ' . COMMERCE_SKU_MAX . ' символов.';
    } elseif ($values['sku'] === (string) value('SELECT sku FROM ' . t('commerce_product') . ' WHERE id = ?', [$productId])) {
        $errors['sku'] = 'Артикул варианта совпадает с артикулом товара — они должны различаться.';
    } elseif (value('SELECT COUNT(*) FROM ' . t('commerce_variant') . ' WHERE sku = ? AND product_id <> ?', [$values['sku'], $productId]) > 0
        || value('SELECT COUNT(*) FROM ' . t('commerce_product') . ' WHERE sku = ?', [$values['sku']]) > 0) {
        $errors['sku'] = 'Такой артикул уже занят товаром или другим вариантом.';
    }

    $retailRaw = str_replace(' ', '', $values['price_retail']);
    $retailOk = $retailRaw !== '' && preg_match('/^\d+([.,]\d{1,2})?$/', $retailRaw) === 1;
    $retailFloat = $retailOk ? (float) str_replace(',', '.', $retailRaw) : 0.0;

    if ($values['price_retail'] === '') {
        $errors['price_retail'] = 'Укажите цену варианта.';
    } elseif (!$retailOk) {
        $errors['price_retail'] = 'Цена — число, например 1290 или 1290,50.';
    } elseif ($retailFloat <= 0 || $retailFloat > COMMERCE_PRICE_MAX) {
        $errors['price_retail'] = 'Цена — от 0,01 до ' . number_format(COMMERCE_PRICE_MAX, 2, ',', ' ') . '.';
    }

    if ($values['price_promo'] !== '') {
        $promoRaw = str_replace(' ', '', $values['price_promo']);
        $promoOk = $promoRaw !== '' && preg_match('/^\d+([.,]\d{1,2})?$/', $promoRaw) === 1;
        $promoFloat = $promoOk ? (float) str_replace(',', '.', $promoRaw) : -1.0;

        if (!$promoOk) {
            $errors['price_promo'] = 'Промо-цена — число, например 990 или 990,50.';
        } elseif ($promoFloat <= 0 || $promoFloat >= $retailFloat || $promoFloat > COMMERCE_PRICE_MAX) {
            $errors['price_promo'] = 'Промо-цена обязана быть больше нуля и меньше обычной.';
        }
    }

    if (!preg_match('/^\d+$/', $values['stock']) || (int) $values['stock'] > COMMERCE_QTY_MAX) {
        $errors['stock'] = 'Остаток — целое число от 0 до ' . COMMERCE_QTY_MAX . '.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Создать вариант товара; позиция — в конец списка.
 *
 * @param array{title: string, sku: string, price_retail: string, price_promo: string, stock: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceCreateVariant(string $productId, array $values, ?DateTimeImmutable $now = null): array
{
    $productExists = value('SELECT COUNT(*) FROM ' . t('commerce_product') . ' WHERE id = ?', [$productId]) > 0;

    if (!$productExists) {
        return ['ok' => false, 'error' => 'Товара уже нет — обновите страницу.', 'notice' => ''];
    }

    $retail = (float) str_replace(',', '.', str_replace(' ', '', $values['price_retail']));
    $promoRaw = str_replace(' ', '', $values['price_promo']);
    $stock = (int) $values['stock'];
    $id = newId();
    $moment = $now ?? nowUtc();
    $position = (int) value('SELECT COALESCE(MAX(position), 0) FROM ' . t('commerce_variant') . ' WHERE product_id = ?', [$productId]) + 1;

    insert('commerce_variant', [
        'id' => $id,
        'product_id' => $productId,
        'sku' => $values['sku'],
        'title' => $values['title'],
        'price_retail' => $retail,
        'price_promo' => $promoRaw !== '' ? (float) str_replace(',', '.', $promoRaw) : null,
        'stock' => $stock,
        'position' => $position,
        'created_at' => utcStamp($moment),
        'updated_at' => utcStamp($moment),
    ]);

    logInfo('commerce.variant_created', ['variantId' => $id, 'productId' => $productId, 'sku' => $values['sku']]);

    return ['ok' => true, 'error' => '', 'notice' => 'variant_saved'];
}

/**
 * Правка варианта. Причинам отказа — нет товара, нет варианта, занятый артикул —
 * свои русские причины.
 *
 * @param array{title: string, sku: string, price_retail: string, price_promo: string, stock: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceUpdateVariant(string $id, array $values, ?DateTimeImmutable $now = null): array
{
    $variant = commerceVariantById($id);

    if ($variant === null) {
        return ['ok' => false, 'error' => 'Варианта уже нет — обновите страницу.', 'notice' => ''];
    }

    $retail = (float) str_replace(',', '.', str_replace(' ', '', $values['price_retail']));
    $promoRaw = str_replace(' ', '', $values['price_promo']);
    $moment = $now ?? nowUtc();

    run(
        'UPDATE ' . t('commerce_variant') . ' SET sku = ?, title = ?, price_retail = ?, price_promo = ?,'
        . ' stock = ?, updated_at = ? WHERE id = ?',
        [
            $values['sku'],
            $values['title'],
            $retail,
            $promoRaw !== '' ? (float) str_replace(',', '.', $promoRaw) : null,
            (int) $values['stock'],
            utcStamp($moment),
            $id,
        ]
    );

    logInfo('commerce.variant_updated', ['variantId' => $id, 'sku' => $values['sku']]);

    return ['ok' => true, 'error' => '', 'notice' => 'variant_saved'];
}

/**
 * Удалить вариант. Заказы держат вариант живым: строка заказа хранит ссылку на
 * вариант для возврата остатка при отмене, и снять «историю строки» торговец
 * не может.
 *
 * @return array{ok: bool, error: string, notice: string}
 */
function commerceDeleteVariant(string $id): array
{
    $variant = commerceVariantById($id);

    if ($variant === null) {
        return ['ok' => false, 'error' => 'Варианта уже нет.', 'notice' => ''];
    }

    if (value('SELECT COUNT(*) FROM ' . t('commerce_order_item') . ' WHERE variant_id = ?', [$id]) > 0) {
        return [
            'ok' => false,
            'error' => 'Вариант фигурирует в заказах — обнулите остаток, чтобы снять его с продажи.',
            'notice' => '',
        ];
    }

    run('DELETE FROM ' . t('commerce_variant') . ' WHERE id = ?', [$id]);
    logInfo('commerce.variant_deleted', ['variantId' => $id, 'sku' => $variant['sku']]);

    return ['ok' => true, 'error' => '', 'notice' => 'variant_deleted'];
}

/**
 * Варианты для карточки товара: опубликованный товар видит только свои варианты,
 * остаток и цена идут каждый в свою кнопку.
 *
 * @return list<array{id: string, sku: string, title: string, price_cents: int, price_retail_cents: int, currency: string, stock: int, in_stock: bool}>
 */
function commerceVariantOptions(string $productId): array
{
    // Валюта принадлежит товару: у варианта своей нет — карточка и кнопки корзины
    // печатают деньги в валюте родителя.
    $rows = all(
        'SELECT v.id, v.sku, v.title, v.price_retail, v.price_promo, p.currency, v.stock'
        . ' FROM ' . t('commerce_variant') . ' v'
        . ' JOIN ' . t('commerce_product') . ' p ON p.id = v.product_id'
        . ' WHERE v.product_id = ? ORDER BY v.position, v.sku',
        [$productId]
    );

    $options = [];
    foreach ($rows as $row) {
        $price = $row['price_promo'] !== null ? (float) $row['price_promo'] : (float) $row['price_retail'];
        $options[] = [
            'id' => (string) $row['id'],
            'sku' => (string) $row['sku'],
            'title' => (string) $row['title'],
            'price_cents' => commerceCents($price),
            'price_retail_cents' => $row['price_promo'] !== null
                ? commerceCents((float) $row['price_retail']) : 0,
            'currency' => (string) $row['currency'],
            'stock' => (int) $row['stock'],
            'in_stock' => (int) $row['stock'] > 0,
        ];
    }

    return $options;
}
