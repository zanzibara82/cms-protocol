<?php

declare(strict_types=1);

/**
 * Экран вариантов товара (M2): список с кнопками и одна форма — создать или править
 * выбранный. Товарная форма уже набита полями, поэтому варианты живут на своей
 * странице и приходят туда со ссылками списка.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array<string, mixed> $product
 * @var list<array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}> $variants
 * @var string $mode
 * @var array{id: string, product_id: string, sku: string, title: string, price_retail: float, price_promo: ?float, stock: int, position: int}|null $variant
 * @var array{title: string, sku: string, price_retail: string, price_promo: string, stock: string} $form
 * @var array<string, string> $errors
 */
$isEdit = $mode === 'edit' && $variant !== null;
$variantsPath = '/commerce/admin/products/' . rawurlencode((string) $product['id']) . '/variants';
$productPath = '/commerce/admin/products/' . rawurlencode((string) $product['id']);
$action = $isEdit
    ? $variantsPath . '/' . rawurlencode((string) $variant['id'])
    : $variantsPath;
$op = $isEdit ? 'save' : 'create';
$currency = (string) $product['currency'];

/**
 * Строковые значения формы: отказ печатает набранное, открытие — из строки варианта.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form, $isEdit, $variant): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $variant !== null) {
        $raw = $variant[$field] ?? null;

        if ($field === 'price_promo' && $raw === null) {
            return '';
        }

        if (in_array($field, ['price_retail', 'price_promo'], true) && $raw !== null) {
            return number_format((float) $raw, 2, ',', ' ');
        }

        return (string) $raw;
    }

    return $field === 'stock' ? '0' : '';
};

/** Класс поля: красная рамка на поле с причиной отказа. */
$isError = static function (string $field) use ($errors): string {
    return (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';
};

/** Причина отказа под полем. */
$fieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');

    return $message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>';
};

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="' . e($op) . '">'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="variant_title">'
    . '<span class="text-sm text-ink/60">Название варианта</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('title') . ' px-3 py-2" id="variant_title"'
    . ' name="title" maxlength="' . e((string) COMMERCE_TITLE_MAX) . '" value="' . e($value('title')) . '">'
    . $fieldError('title')
    . '</label>'
    . '<label class="block" for="variant_sku">'
    . '<span class="text-sm text-ink/60">Артикул (SKU) варианта</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('sku') . ' px-3 py-2" id="variant_sku"'
    . ' name="sku" maxlength="' . e((string) COMMERCE_SKU_MAX) . '" value="' . e($value('sku')) . '">'
    . $fieldError('sku')
    . '</label>'
    . '<label class="block" for="variant_price">'
    . '<span class="text-sm text-ink/60">Цена</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('price_retail') . ' px-3 py-2"'
    . ' id="variant_price" name="price_retail" inputmode="decimal" value="' . e($value('price_retail')) . '">'
    . $fieldError('price_retail')
    . '</label>'
    . '<label class="block" for="variant_promo">'
    . '<span class="text-sm text-ink/60">Промо-цена (ниже обычной)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('price_promo') . ' px-3 py-2"'
    . ' id="variant_promo" name="price_promo" inputmode="decimal" value="' . e($value('price_promo')) . '">'
    . $fieldError('price_promo')
    . '</label>'
    . '<label class="block" for="variant_stock">'
    . '<span class="text-sm text-ink/60">Остаток</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('stock') . ' px-3 py-2"'
    . ' id="variant_stock" name="stock" type="number" min="0" max="' . e((string) COMMERCE_QTY_MAX) . '"'
    . ' value="' . e($value('stock')) . '">'
    . $fieldError('stock')
    . '</label></div>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . e($isEdit ? 'Сохранить вариант' : 'Добавить вариант') . '</button>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="' . e($variantsPath) . '">Отмена</a>'
        : '')
    . '</div></form>';

/**
 * Кнопка строки со своей формой: имя кнопки не доходит до сервера при отправке формы
 * клавишей Enter, поэтому действие держит скрытое поле.
 *
 * @param string $variantId
 */
$rowButton = static function (string $variantId) use ($productPath): string {
    return '<form method="post" action="' . e($productPath . '/variants/' . rawurlencode($variantId)) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($variants as $row) {
    $promo = $row['price_promo'] !== null;
    $price = $promo ? (float) $row['price_promo'] : (float) $row['price_retail'];

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
        . ' href="' . e($variantsPath . '/' . rawurlencode((string) $row['id'])) . '">'
        . e((string) $row['title']) . '</a>'
        . ($isEdit && $variant !== null && (string) $variant['id'] === (string) $row['id']
            ? ' <span class="text-xs text-ink/50">в форме ниже</span>' : '')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . e((string) $row['sku']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">'
        . ($promo
            ? '<s class="mr-2 text-sm font-normal text-ink/50">' . e(commerceFormatMoney(commerceCents((float) $row['price_retail']), $currency)) . '</s>'
            : '')
        . e(commerceFormatMoney(commerceCents($price), $currency))
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">' . e((string) $row['stock']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Вариантов пока нет — товар продаётся своим SKU.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Вариант</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Артикул</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Цена</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Остаток</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="flex flex-wrap items-center justify-between gap-3">'
    . '<h1 class="text-2xl font-bold">Варианты: ' . e((string) $product['title']) . '</h1>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="' . e($productPath) . '">Правка товара</a>'
    . '</header>'
    . ($isEdit ? '' : '<p class="text-sm text-ink/70">Вариант — отдельный SKU с собственной ценой и остатком: цвет, размер или комплектация.</p>')
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Варианты товара', $content, $notice);
