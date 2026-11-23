<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $type */
/** @var array<string, string> $values */
/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $types */
/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';
$isNew = $type === null;
$target = $isNew ? '/admin/content/types/new' : '/admin/content/types/' . rawurlencode((string) $type['id']);

// Макет раздела: пустое значение первым — «как на сайте». Иначе тип по умолчанию перебивал бы
// умолчание сайта, и лендинг на всём сайте стал бы невозможен.
$layoutOptions = '';
$layoutValue = (string) ($values['layout'] ?? '');

foreach (PAGE_LAYOUTS as $layoutKey) {
    $layoutOptions .= '<option value="' . e($layoutKey) . '"'
        . ($layoutKey === $layoutValue ? ' selected' : '') . '>'
        . e(PAGE_LAYOUT_NAMES[$layoutKey]) . '</option>';
}

$fieldRows = '';
foreach ($rows as $index => $row) {
    $kind = (string) ($row['kind'] ?? CONTENT_FIELD_TEXT);
    $kindSelect = '';

    foreach (CONTENT_FIELD_KIND_NAMES as $value => $label) {
        $selected = $value === $kind ? ' selected' : '';
        $kindSelect .= '<option value="' . e($value) . '"' . $selected . '>' . e($label) . '</option>';
    }

    $typeSelect = '';
    $selectedTypes = is_array($row['types'] ?? null) ? $row['types'] : [];

    foreach ($types as $other) {
        $otherId = (string) $other['id'];
        $selected = in_array($otherId, $selectedTypes, true) ? ' selected' : '';
        $typeSelect .= '<option value="' . e($otherId) . '"' . $selected . '>'
            . e((string) $other['name']) . ($other['archived_at'] !== null ? ' (архив)' : '') . '</option>';
    }

    $original = (string) ($row['original'] ?? '');
    $isExisting = $original !== '';

    $fieldRows .= '<li class="rounded-lg border border-ink/10 bg-cream/40 p-3">'
        . '<input type="hidden" name="field_original[]" value="' . e($original) . '">'
        . '<div class="grid gap-3 md:grid-cols-2">'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Подпись поля</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="field_label[]" value="' . e((string) ($row['label'] ?? '')) . '"></label>'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Ключ</span>'
        . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="field_key[]" value="' . e((string) ($row['key'] ?? '')) . '"'
        . ($isExisting ? ' readonly' : '') . '>'
        . '<span class="mt-1.5 block text-ink/60">' . ($isExisting
            ? 'Ключ существующего поля менять нельзя — по нему сохранены значения.'
            : 'Латинские буквы, цифры и подчёркивание; можно оставить пустым — соберём из подписи.') . '</span></label>'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Вид поля</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" name="field_kind[]">' . $kindSelect . '</select></label>'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Подсказка</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="field_hint[]" value="' . e((string) ($row['hint'] ?? '')) . '"></label>'
        . '</div>'
        . '<div class="mt-3 grid gap-3 md:grid-cols-2">'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Варианты выбора</span>'
        . '<textarea class="min-h-[44px] ' . e($input) . '" rows="4" name="field_options[]">'
        . e((string) ($row['options'] ?? '')) . '</textarea>'
        . '<span class="mt-1.5 block text-ink/60">По одному варианту на строку — только для поля «Выбор из списка».</span></label>'
        . '<label class="block min-w-0 text-sm"><span class="block font-medium">Типы для связи</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" multiple size="4" name="field_types[' . e((string) $index) . '][]">'
        . $typeSelect . '</select>'
        . '<span class="mt-1.5 block text-ink/60">Только для поля «Связь с записью». Без выбора — записи любого активного типа.</span></label>'
        . '</div>'
        . '<div class="mt-3 flex flex-wrap items-center gap-4">'
        . adminCheckbox('field_required[' . e((string) $index) . ']', 'Обязательное поле', '1', (bool) ($row['required'] ?? false))
        . adminCheckbox('field_delete[' . e((string) $index) . ']', 'Убрать поле в архив', '1', false)
        . '</div>'
        . '</li>';
}

$fieldsCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Поля типа</h2>'
    . '<p class="mt-1.5 text-sm text-ink/70">Пустая строка не создаёт поле. Поле, отмеченное на удаление, уходит в архив: значения и история остаются в базе.</p>'
    . '<ol class="mt-3 space-y-3">' . $fieldRows . '</ol>'
    . '</section>';

$form = '<form class="space-y-4" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="save-type">'
    . '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Описание типа</h2>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Название</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="name" value="' . e((string) $values['name']) . '" required></label>'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Адрес раздела</span>'
    . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" value="' . e((string) $values['slug']) . '">'
    . '<span class="mt-1.5 block text-ink/60">Например, «statyi» даст адрес /statyi. Служебные адреса разделов заняты.</span></label>'
    . '</div>'
    . '<label class="mt-3 block text-sm"><span class="block font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description" value="' . e((string) $values['description']) . '"></label>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Порядок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="number" name="sort" value="' . e((string) $values['sort']) . '"></label>'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Комментарий к версии</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="comment" value=""></label>'
    . '</div>'
    . '<label class="mt-3 block text-sm"><span class="block font-medium">Макет страниц раздела</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="layout">'
    . '<option value="">Как на сайте (по умолчанию)</option>'
    . $layoutOptions
    . '</select>'
    . '<span class="mt-1.5 block text-ink/60">Каркас архивной страницы и карточек записей. Пустое значение — обвязка сайта.</span></label>'
    . '</section>'
    . $fieldsCard
    . '<div><button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">' . ($isNew ? 'Создать тип' : 'Сохранить тип') . '</button></div>'
    . '</form>';

$links = '';
if (!$isNew && $type['archived_at'] === null) {
    $links = '<p><a class="inline-flex min-h-[44px] items-center hover:underline" href="/'
        . e((string) $type['slug']) . '" target="_blank" rel="noopener noreferrer">Открыть публичный раздел</a></p>';
}

$archiveForm = '';
if (!$isNew) {
    $archived = $type['archived_at'] !== null;
    $op = $archived ? 'restore-type' : 'archive-type';
    $label = $archived ? 'Восстановить тип' : 'Убрать тип в архив';
    $archiveForm = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<form method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">' . e($label) . '</button>'
        . '<p class="mt-1.5 text-sm text-ink/60">Архив обратим: записи и их история остаются на месте, публичный адрес закрывается.</p>'
        . '</form></section>';
}

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell($isNew ? 'Новый тип записей' : 'Тип записей', $alert . $links . $form . $archiveForm, $notice);
