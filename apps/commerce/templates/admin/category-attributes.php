<?php

declare(strict_types=1);

/**
 * Характеристики рубрики (M2): набор атрибутов с типами и справочными значениями.
 * Значения списка дополняются при сохранении товара, поэтому пустой справочник —
 * не вина: хватает одного товара с новым значением.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array{id: string, slug: string, title: string, order_index: int} $category
 * @var list<array{id: string, slug: string, label: string, type: string, unit: ?string, order_index: int, choices: list<string>}> $attributes
 * @var array{label: string, slug: string, type: string, unit: string} $form
 * @var array<string, string> $errors
 */
$categoriesPath = '/commerce/admin/categories';
$action = $categoriesPath . '/' . rawurlencode((string) $category['id']) . '/attribute';

/**
 * Значение поля формы: отказ печатает набранное, открытие — пустое.
 *
 * @param string $field
 */
$value = static function (string $field) use ($form): string {
    return (string) ($form[$field] ?? '');
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

$typeOptions = '';
foreach (COMMERCE_ATTRIBUTE_TYPES as $typeCode) {
    $typeOptions .= '<option value="' . e($typeCode) . '"' . ($value('type') === $typeCode ? ' selected' : '')
        . '>' . e((string) COMMERCE_ATTRIBUTE_TYPE_LABELS[$typeCode]) . '</option>';
}

$formHtml = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="'
    . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="op" value="create">'
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="attribute_label">'
    . '<span class="text-sm text-ink/60">Название характеристики</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('label') . ' px-3 py-2" id="attribute_label"'
    . ' name="label" maxlength="120" value="' . e($value('label')) . '" placeholder="Мощность">'
    . $fieldError('label')
    . '</label>'
    . '<label class="block" for="attribute_slug">'
    . '<span class="text-sm text-ink/60">Ключ фильтра (пусто — соберётся из названия)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('slug') . ' px-3 py-2" id="attribute_slug"'
    . ' name="slug" maxlength="' . e((string) COMMERCE_SLUG_MAX) . '" value="' . e($value('slug')) . '" placeholder="moschnost">'
    . $fieldError('slug')
    . '</label>'
    . '<label class="block" for="attribute_type">'
    . '<span class="text-sm text-ink/60">Тип</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('type') . ' px-3 py-2" id="attribute_type"'
    . ' name="type">'
    . $typeOptions
    . '</select>'
    . $fieldError('type')
    . '</label>'
    . '<label class="block" for="attribute_unit">'
    . '<span class="text-sm text-ink/60">Единица измерения (необязательно)</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border' . $isError('unit') . ' px-3 py-2" id="attribute_unit"'
    . ' name="unit" maxlength="32" value="' . e($value('unit')) . '" placeholder="Вт">'
    . $fieldError('unit')
    . '</label>'
    . '</div>'
    . '<div class="mt-1 flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Добавить характеристику</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="' . e($categoriesPath) . '">К рубрикам</a>'
    . '</div></form>';

/**
 * Кнопка удаления характеристик строкой.
 *
 * @param string $attributeId
 */
$rowButton = static function (string $attributeId) use ($categoriesPath, $category): string {
    return '<form method="post" action="' . e($categoriesPath . '/' . rawurlencode((string) $category['id'])
        . '/attribute/' . rawurlencode($attributeId)) . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . 'Удалить</button></form>';
};

$rows = '';
foreach ($attributes as $row) {
    $choicesLine = $row['choices'] === []
        ? '<span class="text-xs text-ink/50">значения появятся из товаров</span>'
        : e(implode(', ', $row['choices']));

    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e($row['label'])
        . '<br><span class="text-xs text-ink/50">attr_' . e($row['slug']) . '</span></td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . e((string) COMMERCE_ATTRIBUTE_TYPE_LABELS[$row['type']])
        . ($row['unit'] !== null ? '<br><span class="text-xs text-ink/50">ед.: ' . e((string) $row['unit']) . '</span>' : '')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $choicesLine . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $rowButton((string) $row['id']) . '</td>'
        . '</tr>';
}

$table = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Характеристик пока нет — карточки товара печатаются без блока характеристик.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Характеристика</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Тип</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Справочник значений</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="flex flex-wrap items-center justify-between gap-3">'
    . '<h1 class="text-2xl font-bold">Характеристики: ' . e((string) $category['title']) . '</h1>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="'
    . e('/shop/c/' . rawurlencode((string) $category['slug'])) . '">Открыть витрину</a>'
    . '</header>'
    . $formHtml
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Характеристики рубрики', $content, $notice);
