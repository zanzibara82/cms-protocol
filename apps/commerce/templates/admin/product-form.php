<?php

declare(strict_types=1);

/**
 * Форма товара: создание и правка. Отказ сохранения возвращает набранное.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $mode
 * @var array<string, mixed>|null $product
 * @var array<string, string> $form
 * @var array<string, string> $errors
 * @var array<string, mixed> $values
 * @var list<array{id: string, slug: string, title: string, order_index: int}> $categories
 * @var list<string> $chosen
 * @var list<array{id: string, slug: string, label: string, type: string, unit: ?string, choices: list<string>, value: string}> $attributes
 * @var list<array{title: string, text: string}> $formBody
 * @var string $heading
 */
$isEdit = $mode === 'edit';
$action = $isEdit
    ? '/commerce/admin/products/' . rawurlencode((string) ($product['id'] ?? ''))
    : '/commerce/admin/products/new';
$cancel = $isEdit ? $action : '/commerce/admin/products';

/**
 * Значение поля: при отказе — введённое, при открытии — из строки базы.
 *
 * @param string $field
 */
$value = static function (string $field) use ($isEdit, $product, $form): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $product !== null && array_key_exists($field, $product)) {
        $raw = $product[$field];

        return $raw === null ? '' : (string) $raw;
    }

    if (!$isEdit && $field === 'currency') {
        return COMMERCE_CURRENCY_DEFAULT;
    }

    if (!$isEdit && $field === 'status') {
        return 'DRAFT';
    }

    return '';
};

/** Поле цены: пустое значение в строке базы показывается пустым полем. */
$priceValue = static function (string $field) use ($isEdit, $product, $form): string {
    if ((string) ($form[$field] ?? '') !== '') {
        return (string) $form[$field];
    }

    if ($isEdit && $product !== null) {
        $raw = $product[$field] ?? null;

        return $raw === null ? '' : number_format((float) $raw, 2, ',', ' ');
    }

    return '';
};

/** Класс поля ввода: красная рамка на поле с причиной отказа. */
$fieldClass = static function (string $field) use ($errors): string {
    return (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';
};

/** Причина отказа под полем. */
$fieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');

    return $message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>';
};

/** Абзацы тела: из базы при открытии, из отказа при правке. */
$paragraphs = (array) ($formBody ?? []);
if ($paragraphs === [] && $isEdit && $product !== null) {
    $decoded = json_decode((string) ($product['body'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $paragraphs[] = [
                'title' => (string) ($row['title'] ?? ''),
                'text' => (string) ($row['text'] ?? ''),
            ];
        }
    }
}
$paragraphs = array_slice($paragraphs, 0, 64);

/**
 * Пара «подпись + поле»: класс собирается строкой, чтобы страж касания видел литеральные
 * классы, а причина отказа печаталась под полем.
 *
 * @param string $max
 */
$textField = static function (string $id, string $label, string $name, string $max) use ($value, $errors): string {
    $message = (string) ($errors[$id] ?? '');

    return '<label class="block" for="' . e($id) . '">'
        . '<span class="text-sm text-ink/60">' . e($label) . '</span>'
        . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . ($message !== '' ? ' border-red-400' : ' border-ink/20')
        . ' px-3 py-2" id="' . e($id) . '" name="' . e($name) . '"'
        . ($max !== '' ? ' maxlength="' . e($max) . '"' : '')
        . ' value="' . e($value($id)) . '">'
        . ($message === '' ? '' : '<span class="mt-1 block text-sm text-red-700">' . e($message) . '</span>')
        . '</label>';
};

$isError = static fn (string $field): string => (string) ($errors[$field] ?? '') !== '' ? ' border-red-400' : ' border-ink/20';

$formHead = '<form class="space-y-6 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . $textField('title', 'Название', 'title', (string) COMMERCE_TITLE_MAX)
    . $textField('sku', 'Артикул (SKU)', 'sku', (string) COMMERCE_SKU_MAX)
    . $textField('slug', 'Адрес страницы (пусто — соберётся из названия)', 'slug', (string) COMMERCE_SLUG_MAX)
    . $textField('brand', 'Бренд (необязательно)', 'brand', '120')
    . $textField('barcode', 'Штрихкод (необязательно)', 'barcode', '64')
    . $textField('lead_text', 'Краткое описание (необязательно)', 'lead_text', (string) COMMERCE_LEAD_MAX)
    . '</div>';

$formPrices = '<div class="grid gap-4 sm:grid-cols-3">';
foreach (['price_retail' => 'Цена', 'price_promo' => 'Промо-цена (ниже обычной)', 'price_cost' => 'Себестоимость служебная'] as $name => $label) {
    $formPrices .= '<label class="block" for="' . e($name) . '">'
        . '<span class="text-sm text-ink/60">' . e($label) . '</span>'
        . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError($name)
        . ' px-3 py-2" id="' . e($name) . '" name="' . e($name) . '" inputmode="decimal"'
        . ' value="' . e($priceValue($name)) . '">'
        . $fieldError($name)
        . '</label>';
}

$formPrices .= '<label class="block" for="currency">'
    . '<span class="text-sm text-ink/60">Валюта</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('currency')
    . ' px-3 py-2" id="currency" name="currency">';
foreach (COMMERCE_CURRENCIES as $code) {
    $formPrices .= '<option value="' . e($code) . '"' . ($value('currency') === $code ? ' selected' : '') . '>'
        . e($code) . '</option>';
}
$formPrices .= '</select>' . $fieldError('currency') . '</label>';

$formPrices .= '<label class="block" for="stock">'
    . '<span class="text-sm text-ink/60">Остаток</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('stock')
    . ' px-3 py-2" id="stock" name="stock" type="number" min="0" max="'
    . e((string) COMMERCE_QTY_MAX) . '" value="'
    . e($value('stock') !== '' ? (string) $value('stock') : '0') . '">'
    . $fieldError('stock')
    . '</label>';

$formPrices .= '<label class="block" for="status">'
    . '<span class="text-sm text-ink/60">Состояние</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('status')
    . ' px-3 py-2" id="status" name="status">';
foreach (COMMERCE_PRODUCT_STATUSES as $key) {
    $formPrices .= '<option value="' . e($key) . '"' . ($value('status') === $key ? ' selected' : '') . '>'
        . e((string) COMMERCE_PRODUCT_STATUS_LABELS[$key]) . '</option>';
}
$formPrices .= '</select>' . $fieldError('status') . '</label>';

$formPrices .= '</div>';

$fieldset = '<fieldset class="space-y-2 mt-6">'
    . '<legend class="text-sm text-ink/60">Рубрики товара</legend>';
foreach ($categories as $row) {
    $fieldset .= '<label class="inline-flex min-h-[44px] items-center gap-2 text-sm">'
        . '<input class="size-11 min-h-[44px] rounded border border-ink/20 bg-white" type="checkbox" name="categories[]" value="'
        . e((string) $row['id']) . '"'
        . (in_array((string) $row['id'], (array) $chosen, true) ? ' checked' : '') . '>'
        . e((string) $row['title'])
        . '</label>';
}

$fieldset .= '</fieldset>';

$indexBlock = '<fieldset class="space-y-2 mt-6">'
    . '<legend class="text-sm text-ink/60">Индексация</legend>'
    . '<label class="inline-flex min-h-[44px] items-center gap-2 text-sm">'
    . '<input class="size-11 min-h-[44px] rounded border border-ink/20 bg-white" type="checkbox" name="noindex"'
    . ($value('noindex') === '1' ? ' checked' : '') . '>'
    . 'Не индексировать в поисковиках'
    . '</label>'
    . '</fieldset>';

// Характеристики рубрик: набор приходит из выбранных рубрик; значение CHOICE выбирается
// из справочника рубрики, NUMBER печатается числом без единицы (единица — подпись).
$attributesBlock = '';
if ($attributes !== []) {
    $attributesBlock .= '<fieldset class="space-y-3 mt-6">'
        . '<legend class="text-sm text-ink/60">Характеристики рубрик</legend>';

    foreach ($attributes as $attribute) {
        $attrId = (string) $attribute['id'];
        $name = 'attr_' . $attrId;
        $current = (string) $attribute['value'];

        if ((string) $attribute['type'] === 'CHOICE') {
            $choices = $attribute['choices'];

            if ($current !== '' && !in_array($current, $choices, true)) {
                // Значение из базы, которого ещё нет в справочнике: пользователь должен
                // увидеть то, что уже записано, а не «пусто».
                $choices[] = $current;
            }

            $options = '<option value="">— не указано</option>';
            foreach ($choices as $choice) {
                $options .= '<option value="' . e($choice) . '"' . ($current === $choice ? ' selected' : '')
                    . '>' . e($choice) . '</option>';
            }

            $attributesBlock .= '<label class="block" for="attr_' . $attrId . '">'
                . '<span class="text-sm text-ink/60">' . e((string) $attribute['label']) . '</span>'
                . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2"'
                . ' id="attr_' . $attrId . '" name="' . e($name) . '">'
                . $options
                . '</select></label>';
        } else {
            $unitSuffix = $attribute['unit'] !== null && (string) $attribute['unit'] !== ''
                ? ' (' . e((string) $attribute['unit']) . ')' : '';

            $attributesBlock .= '<label class="block" for="attr_' . $attrId . '">'
                . '<span class="text-sm text-ink/60">' . e((string) $attribute['label']) . $unitSuffix . '</span>'
                . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2"'
                . ' id="attr_' . $attrId . '" name="' . e($name) . '" inputmode="decimal"'
                . ' value="' . e($current) . '">'
                . '</label>';
        }
    }

    $attributesBlock .= '</fieldset>';
}

$seoBlock = '<fieldset class="space-y-2 mt-6">'
    . '<legend class="text-sm text-ink/60">SEO (необязательно)</legend>'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . $textField('seo_title', 'SEO-заголовок', 'seo_title', '120')
    . $textField('seo_description', 'SEO-описание', 'seo_description', '300')
    . '</div></fieldset>';

$bodyBlock = '<fieldset class="space-y-3 mt-6">'
    . '<legend class="text-sm text-ink/60">Описание (абзацы; **выделение** читается)</legend>';

$paragraphCount = max(3, count($paragraphs) + 1);
for ($i = 0; $i < $paragraphCount; $i++) {
    $paragraph = $paragraphs[$i] ?? null;
    $bodyBlock .= '<div class="rounded-lg border border-ink/10 p-3">'
        . '<input class="mb-2 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" name="body_title[]" maxlength="120"'
        . ' value="' . e((string) ($paragraph['title'] ?? '')) . '" placeholder="Заголовок абзаца (необязательно)">'
        . '<textarea class="w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" name="body_text[]" rows="3"'
        . ' placeholder="Текст абзаца">' . e((string) ($paragraph['text'] ?? '')) . '</textarea>'
        . '</div>';
}

$bodyMessage = (string) ($errors['body'] ?? '');
if ($bodyMessage !== '') {
    $bodyBlock .= '<p class="text-sm text-red-700">' . e($bodyMessage) . '</p>';
}

$bodyBlock .= '</fieldset>';

$content = $nav
    . '<h1 class="text-2xl font-bold">' . e($heading) . '</h1>'
    . ($isEdit
        ? '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="'
        . e($action . '/variants') . '">Варианты товара</a>'
        : '')
    . $formHead
    . $formPrices
    . $fieldset
    . $attributesBlock
    . $indexBlock
    . $seoBlock
    . $bodyBlock
    . '<div class="mt-6 flex flex-wrap gap-3">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Сохранить</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="'
    . e($cancel) . '">Отмена</a>'
    . '</div>'
    . '</form>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell($heading, $content, $notice);
