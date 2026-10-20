<?php

declare(strict_types=1);

/**
 * Характеристики категории: выбор ветки, собственные поля и унаследованные подсказкой.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool, depth: int}> $categories
 * @var array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool}|null $category
 * @var string $categoryId
 * @var list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $attributes
 * @var list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $inherited
 * @var array<string, string> $kinds
 */
$categoryOptions = '<option value="">— выберите категорию —</option>';

foreach ($categories as $item) {
    $categoryOptions .= '<option value="' . e((string) $item['id']) . '"'
        . ((string) $item['id'] === $categoryId ? ' selected' : '') . '>'
        . e(str_repeat('— ', (int) $item['depth']) . (string) $item['title']) . '</option>';
}

$kindOptions = static function (string $selected) use ($kinds): string {
    $out = '';

    foreach ($kinds as $value => $label) {
        $out .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }

    return $out;
};

$picker = '<form class="flex flex-wrap items-end gap-3 rounded-xl border border-ink/10 bg-white p-4" method="get" action="/classifieds/admin/attributes">'
    . '<label class="block" for="attribute-category"><span class="text-sm text-ink/60">Категория</span>'
    . '<select class="mt-1 min-h-[44px] min-w-64 rounded-lg border border-ink/20 bg-white px-3 py-2" id="attribute-category" name="category">'
    . $categoryOptions
    . '</select></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>'
    . '</form>';

$content = $nav
    . '<h1 class="text-2xl font-bold">Характеристики</h1>'
    . $picker;

if ($category === null) {
    $content .= '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">'
        . 'Выберите категорию — характеристики принадлежат ветке и наследуются её потомками.</p>';
} else {
    $create = '<form class="rounded-xl border border-ink/10 bg-white p-4" method="post" action="/classifieds/admin/attributes">'
        . csrfField()
        . '<input type="hidden" name="op" value="create">'
        . '<input type="hidden" name="category" value="' . e($categoryId) . '">'
        . '<div class="flex flex-wrap items-end gap-3">'
        . '<label class="block" for="title-new"><span class="text-sm text-ink/60">Название</span>'
        . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="title-new" name="title" maxlength="120" value="" placeholder="Год выпуска"></label>'
        . '<label class="block" for="key-new"><span class="text-sm text-ink/60">Код (пусто — из названия)</span>'
        . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2 font-mono" id="key-new" name="key" maxlength="40" value=""></label>'
        . '<label class="block" for="kind-new"><span class="text-sm text-ink/60">Тип</span>'
        . '<select class="mt-1 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="kind-new" name="kind">'
        . $kindOptions('text')
        . '</select></label>'
        . '<label class="block" for="unit-new"><span class="text-sm text-ink/60">Единица (для числа)</span>'
        . '<input class="mt-1 min-h-[44px] w-32 rounded-lg border border-ink/20 px-3 py-2" id="unit-new" name="unit" maxlength="20" value=""></label>'
        . '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
        . '<input class="min-h-[44px] size-11 rounded border border-ink/20" type="checkbox" name="required" value="1">'
        . '<span>Обязательное</span></label>'
        . '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
        . '<input class="min-h-[44px] size-11 rounded border border-ink/20" type="checkbox" name="filterable" value="1" checked>'
        . '<span>В фильтрах</span></label>'
        . '</div>'
        . '<label class="mt-3 block" for="options-new"><span class="text-sm text-ink/60">Варианты списка — по одному в строке</span>'
        . '<textarea class="mt-1 h-[80px] min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" id="options-new" name="options" maxlength="4000"></textarea></label>'
        . '<button class="mt-3 inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Добавить характеристику</button>'
        . '</form>';

    $rowHtml = static function (array $attribute) use ($categoryId, $kindOptions): string {
        $id = (string) $attribute['id'];

        return '<li class="rounded-xl border border-ink/10 bg-white p-4">'
            . '<form class="flex flex-wrap items-end gap-2" method="post" action="/classifieds/admin/attributes">'
            . csrfField()
            . '<input type="hidden" name="op" value="save">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="category" value="' . e($categoryId) . '">'
            . '<label class="block"><span class="text-xs text-ink/60">Название</span>'
            . '<input class="min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" name="title" maxlength="120" value="' . e((string) $attribute['title']) . '" aria-label="Название"></label>'
            . '<label class="block"><span class="text-xs text-ink/60">Код</span>'
            . '<input class="min-h-[44px] w-40 rounded-lg border border-ink/20 px-3 py-2 font-mono" name="key" maxlength="40" value="' . e((string) $attribute['key_slug']) . '" aria-label="Код"></label>'
            . '<label class="block"><span class="text-xs text-ink/60">Тип</span>'
            . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="kind" aria-label="Тип">'
            . $kindOptions((string) $attribute['kind'])
            . '</select></label>'
            . '<label class="block"><span class="text-xs text-ink/60">Единица</span>'
            . '<input class="min-h-[44px] w-28 rounded-lg border border-ink/20 px-3 py-2" name="unit" maxlength="20" value="' . e((string) $attribute['unit']) . '" aria-label="Единица"></label>'
            . '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
            . '<input class="min-h-[44px] size-11 rounded border border-ink/20" type="checkbox" name="required" value="1"' . ($attribute['required'] ? ' checked' : '') . '>'
            . '<span>Обязательное</span></label>'
            . '<label class="flex min-h-[44px] items-center gap-2 text-sm">'
            . '<input class="min-h-[44px] size-11 rounded border border-ink/20" type="checkbox" name="filterable" value="1"' . ($attribute['filterable'] ? ' checked' : '') . '>'
            . '<span>В фильтрах</span></label>'
            . '<label class="block w-full"><span class="text-xs text-ink/60">Варианты списка — по одному в строке</span>'
            . '<textarea class="h-[80px] min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" name="options" maxlength="4000" aria-label="Варианты списка">'
            . e(implode("\n", $attribute['options'])) . '</textarea></label>'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Сохранить</button>'
            . '</form>'
            . '<form class="mt-2" method="post" action="/classifieds/admin/attributes">'
            . csrfField()
            . '<input type="hidden" name="op" value="delete">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="category" value="' . e($categoryId) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Удалить характеристику</button>'
            . '</form>'
            . '</li>';
    };

    $rows = '';
    foreach ($attributes as $attribute) {
        $rows .= $rowHtml($attribute);
    }

    $content .= '<section class="space-y-3">'
        . '<h2 class="text-xl font-semibold">' . e((string) $category['title']) . '</h2>'
        . '<p class="text-sm text-ink/60">Поля этой категории. Наследуются всеми её потомками.</p>'
        . ($inherited !== []
            ? '<p class="rounded-lg border border-ink/10 bg-cream p-3 text-sm text-ink/70">Унаследовано от родителей: '
                . e(implode(', ', array_map(static fn (array $item): string => (string) $item['title'], $inherited))) . '.</p>'
            : '')
        . $create
        . ($rows === ''
            ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Собственных характеристик пока нет.</p>'
            : '<ul class="space-y-3">' . $rows . '</ul>')
        . '</section>';
}

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Характеристики', $content, $notice);
