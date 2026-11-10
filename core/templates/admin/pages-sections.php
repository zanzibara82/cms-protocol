<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $sections */
/** @var string $target POST-адрес действий секции: у главной и у страницы он свой */
/** @var list<array{kind: string, name: string, fields: list<array{key: string, label: string, hint: string}>}> $kinds */

// Секции страницы в конструкторе: у каждой — форма правки полей (сохранение) и кнопки порядка,
// видимости и удаления (отдельные формы: формы нельзя вкладывать друг в друга). Повторные
// строки кнопок и items печатаются с явными индексами — PHP собирает их в массив по имени
// (образец — характеристики доски); пустая последняя строка — место для новой.
// Класс min-h-[44px] стоит литералом в каждом атрибуте class (страж TapTargetTest читает
// классы значения атрибута, `e($input)` даёт ему только имя переменной).
$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

// Имена и поля настроек видов: имена карточек и селекта читаются из одного перечня — вклад
// приложения печатается своей подписью, а не технической меткой.
$kindNames = [];
$kindFields = [];

foreach ($kinds as $option) {
    $kindNames[(string) $option['kind']] = (string) $option['name'];
    $kindFields[(string) $option['kind']] = $option['fields'];
}

/** Селект вида: текущий вид секции выбран, у новой формы — первый вид ядра. */
$kindSelect = static function (string $current) use ($kinds, $input): string {
    $options = '';
    foreach ($kinds as $option) {
        $selected = $option['kind'] === $current ? ' selected' : '';
        $options .= '<option value="' . e($option['kind']) . '"' . $selected . '>' . e($option['name']) . '</option>';
    }

    return '<select class="min-h-[44px] ' . e($input) . '" name="kind">' . $options . '</select>';
};

/**
 * Селект вёрстки: печатается только у видов, которые читают колонку layout (float и files).
 * Пустое значение — запасной вид по умолчанию; у остальных видов поля нет вовсе.
 */
$layoutSelect = static function (array $section) use ($input): string {
    $options = pagesLayoutOptionsOf((string) ($section['kind'] ?? ''));

    if ($options === []) {
        return '';
    }

    $current = (string) ($section['layout'] ?? '');
    $html = '';

    foreach ($options as $option) {
        $selected = $option['value'] === $current ? ' selected' : '';
        $html .= '<option value="' . e($option['value']) . '"' . $selected . '>' . e($option['label']) . '</option>';
    }

    return '<label class="block text-sm"><span class="font-medium">Вёрстка</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" name="layout">' . $html . '</select></label>';
};

/** Пара «скрытое поле» формы действия. */
$field = static function (string $name, string $value): string {
    return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
};

/** Кнопка-форма действия: свой op и свои скрытые поля, без вложенных форм. */
$actionForm = static function (string $op, array $hidden, string $label, string $classes) use ($target, $field): string {
    $fields = '';
    foreach ($hidden as $name => $value) {
        $fields .= $field($name, (string) $value);
    }

    return '<form class="inline" method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">' . $fields
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm ' . e($classes) . '" type="submit">' . e($label) . '</button></form>';
};

/** Строки кнопок секции: заполненные по данным, пустая последняя — место для новой. */
$buttonsRows = static function (array $rows) use ($input): string {
    $html = '';
    foreach (array_merge($rows, [['label' => '', 'href' => '', 'blank' => false]]) as $index => $row) {
        $html .= '<div class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">'
            . '<label class="text-sm"><span class="font-medium">Подпись</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][' . e('label') . ']" value="' . e((string) ($row['label'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Адрес</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][' . e('href') . ']" value="' . e((string) ($row['href'] ?? '')) . '"></label>'
            . adminCheckbox('buttons[' . (string) $index . '][' . 'blank' . ']', 'Новая вкладка', '1', (bool) ($row['blank'] ?? false))
            . '</div>';
    }

    return $html;
};

/**
 * Строки items секции: состав и подписи полей приходят из вида — у шагов только «Шаг» и
 * «Описание», у карточек картинка, ссылка и подпись. Пустая последняя строка — место для новой;
 * плюс справа под строками добавляет ещё одну пустую строку без перезагрузки (admin-sections.js).
 *
 * @param list<array<string, mixed>> $rows
 * @param list<array{key: string, label: string}> $fields
 */
$itemsRows = static function (array $rows, array $fields) use ($input): string {
    $empty = [];
    foreach ($fields as $field) {
        $empty[$field['key']] = '';
    }

    $rowsHtml = '';
    foreach (array_merge($rows, [$empty]) as $index => $row) {
        $rowsHtml .= '<div class="grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-5" data-item-row="' . e((string) $index) . '">';
        foreach ($fields as $field) {
            $key = $field['key'];
            $rowsHtml .= '<label class="text-sm"><span class="font-medium">' . e($field['label']) . '</span>'
                . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][' . e($key) . ']" value="' . e((string) ($row[$key] ?? '')) . '"></label>';
        }
        $rowsHtml .= '</div>';
    }

    return '<div class="space-y-2" data-items-editor>'
        . '<div class="space-y-2" data-items-rows>' . $rowsHtml . '</div>'
        . '<div class="flex justify-end">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg border border-ink/20 bg-white text-ink hover:bg-ink/5" type="button"'
        . ' data-items-add aria-label="Добавить строку" title="Добавить строку" hidden>'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>'
        . '</button>'
        . '</div>'
        . '</div>';
};

/**
 * Форма одной секции: только поля её вида — шапка (якорь, подзаголовок, заголовок, текст) есть
 * у всех, примечание, картинка, кнопки и строки печатаются тогда, когда шаблон вида их читает.
 * Настройки вклада приложения рисуются из описания pages_block_kinds. Новая секция приходит
 * пустым массивом: op — create-section, вид выбирается селектом, а поля вида появятся после
 * создания и перезагрузки (JS не угадывает состав строк).
 */
$sectionForm = static function (array $section) use ($input, $target, $kindSelect, $kindFields, $buttonsRows, $itemsRows, $layoutSelect): string {
    $exists = (string) ($section['id'] ?? '') !== '';
    $id = $exists ? '<input type="hidden" name="section" value="' . e((string) $section['id']) . '">' : '';
    $op = $exists ? 'save-section' : 'create-section';
    $values = static fn (string $key): string => (string) ($section[$key] ?? '');
    $currentKind = $exists ? (string) ($section['kind'] ?? '') : '';
    $visible = $exists ? (int) ($section['visible'] ?? 1) === 1 : true;
    // У нового блока вид ещё не выбран: печатается только общая шапка, остальное появится
    // после создания. У существующего состав полей задаёт его вид.
    $allowed = $exists ? pagesKindFieldsOf($currentKind) : ['anchor', 'badge', 'title', 'lead_text'];
    $allows = static fn (string $key): bool => in_array($key, $allowed, true);

    // Настройки вклада рисуются только у существующей секции, чей вид объявил поля.
    $settingsBlock = '';
    $fields = $kindFields[$currentKind] ?? [];

    if ($exists && $fields !== []) {
        $settings = (array) ($section['settings'] ?? []);
        $settingsRows = '';
        foreach ($fields as $setting) {
            $key = (string) $setting['key'];
            $label = (string) $setting['label'];
            $hint = (string) $setting['hint'];
            $settingsRows .= '<label class="block text-sm"><span class="font-medium">' . e($label) . '</span>'
                . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="settings[' . e($key) . ']" value="' . e((string) ($settings[$key] ?? '')) . '">'
                . ($hint !== '' ? '<span class="mt-1.5 block text-ink/60">' . e($hint) . '</span>' : '')
                . '</label>';
        }

        $settingsBlock = '<div class="space-y-2"><h4 class="text-sm font-medium">Настройки вклада</h4>' . $settingsRows . '</div>';
    }

    // Общие примечание и картинка: только у видов, которые их читают (hero, split, prose, band,
    // pricing, gallery, table). У карточек картинка и подпись живут в каждой строке.
    $sectionMedia = '';

    if ($allows('image')) {
        $imageFieldId = 'section-image-' . ($exists ? (string) $section['id'] : 'new');
        $imagePreview = adminImagePreview($values('image'), $values('image_alt'));
        $imagePreviewBlock = $imagePreview === ''
            ? ''
            : '<div class="order-2 sm:col-start-2 sm:row-start-1">' . $imagePreview . '</div>';

        $sectionMedia = '<div class="flex flex-col gap-3 sm:grid sm:grid-cols-3">'
            . '<label class="order-1 block text-sm sm:col-start-1 sm:row-start-2"><span class="font-medium">Примечание</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="note" value="' . e($values('note')) . '"></label>'
            . $imagePreviewBlock
            . '<div class="order-3 text-sm sm:col-start-2 sm:row-start-2"><label class="font-medium" for="' . e($imageFieldId) . '">Картинка</label>'
            . '<div class="mt-1.5 flex items-start gap-2">'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="image" value="' . e($values('image')) . '">'
            . '<input class="sr-only" id="' . e($imageFieldId) . '" type="file" name="image_file" accept="image/jpeg,image/png,image/webp">'
            . '<label class="inline-flex min-h-[44px] min-w-[44px] shrink-0 cursor-pointer items-center justify-center rounded-lg border border-ink/20 bg-white hover:bg-ink/5" for="' . e($imageFieldId) . '" title="Выбрать картинку с компьютера">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'
            . '<span class="sr-only">Выбрать картинку с компьютера</span></label>'
            . '</div>'
            . '<span class="mt-1.5 block text-ink/60">Путь на сайте или файл: jpg, png, webp до 5 МБ.</span>'
            . '</div>'
            . '<label class="order-4 block text-sm sm:col-start-3 sm:row-start-2"><span class="font-medium">Описание картинки</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="image_alt" value="' . e($values('image_alt')) . '"></label>'
            . '</div>';
    } elseif ($allows('note')) {
        // Примечание без картинки: у band, prose, pricing, gallery и table оно есть, а общей
        // картинки нет — поле печатается отдельной строкой.
        $sectionMedia = '<label class="block text-sm"><span class="font-medium">Примечание</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="note" value="' . e($values('note')) . '"></label>';
    }

    $buttonsBlock = $allows('buttons')
        ? '<div class="space-y-2"><h4 class="text-sm font-medium">Кнопки</h4>' . $buttonsRows($exists ? pagesButtonsOf($section['buttons'] ?? null) : []) . '</div>'
        : '';
    $itemsBlock = $allows('items')
        ? '<div class="space-y-2"><h4 class="text-sm font-medium">Строки</h4>' . $itemsRows(
            $exists ? pagesItemsOf($section['items'] ?? null) : [],
            pagesKindItemFieldsOf($currentKind)
        ) . '</div>'
        : '';
    $layoutBlock = $allows('layout') ? $layoutSelect($section) : '';

    return '<form class="mt-3 space-y-3" method="post" action="' . e($target) . '" enctype="multipart/form-data" data-section-form>' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">' . $id
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<label class="block text-sm"><span class="font-medium">Вид</span>' . $kindSelect($exists ? (string) $section['kind'] : '') . '</label>'
        . '<label class="block text-sm"><span class="font-medium">Якорь</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="anchor" value="' . e($values('anchor')) . '">'
        . '<span class="mt-1.5 block text-ink/60">Ссылка на место секции: /страница#якорь. Пусто — ключ соберётся из заголовка.</span></label>'
        . '</div>'
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<label class="block text-sm"><span class="font-medium">Заголовок</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e($values('title')) . '"></label>'
        . '<label class="block text-sm"><span class="font-medium">Подзаголовок</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="badge" value="' . e($values('badge')) . '"></label>'
        . '</div>'
        . '<label class="block text-sm"><span class="font-medium">Текст</span>'
        . '<textarea class="min-h-[44px] ' . e($input) . '" name="lead_text" rows="3">' . e($values('lead_text')) . '</textarea>'
        . '<span class="mt-1.5 block text-ink/60">Абзацы разделяются пустой строкой.</span></label>'
        . $layoutBlock
        . $sectionMedia
        . $settingsBlock
        . $buttonsBlock
        . $itemsBlock
        . adminCheckbox('visible', 'Показывать на странице', '1', $visible)
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm font-medium text-cream hover:opacity-90" type="submit">Сохранить блок</button>'
        . '</form>';
};

$content = '';
foreach ($sections as $section) {
    $kindName = $kindNames[(string) $section['kind']] ?? (string) $section['kind'];
    $state = (int) $section['visible'] === 1 ? '' : '<span class="text-sm text-ink/50">— скрыта</span>';

    $actions = $actionForm('move-section', ['section' => (string) $section['id'], 'dir' => 'up'], 'Выше', 'border border-ink/20 hover:bg-ink/5')
        . ' ' . $actionForm('move-section', ['section' => (string) $section['id'], 'dir' => 'down'], 'Ниже', 'border border-ink/20 hover:bg-ink/5')
        . ' ' . $actionForm(
            'toggle-section',
            ['section' => (string) $section['id'], 'visible' => (int) $section['visible'] === 1 ? '0' : '1'],
            (int) $section['visible'] === 1 ? 'Скрыть' : 'Показать',
            'border border-ink/20 hover:bg-ink/5'
        )
        . ' ' . $actionForm('delete-section', ['section' => (string) $section['id']], 'Удалить', 'text-bad hover:bg-bad/10');

    $content .= '<details class="rounded-lg border border-ink/10 bg-white" data-section-id="' . e((string) $section['id']) . '" open>'
        . '<summary class="section-summary flex min-h-[44px] cursor-pointer items-center justify-between gap-3 px-4 py-3">'
        . '<h3 class="font-semibold">' . e($kindName) . $state . '</h3>'
        . '<svg class="section-chevron h-5 w-5 shrink-0 text-ink/60 transition-transform" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
        . '<path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</summary>'
        . '<div class="border-t border-ink/10 px-4 py-3">'
        . '<div class="flex flex-wrap justify-end gap-2">' . $actions . '</div>'
        . $sectionForm($section)
        . '</div></details>';
}

// Новая секция: та же форма пустой строкой. Вид выбирается селектом; общих полей картинки
// у новой формы нет — они появятся после создания и перезагрузки.
$content .= '<details class="rounded-lg border border-ink/10 bg-white" open>'
    . '<summary class="section-summary flex min-h-[44px] cursor-pointer items-center justify-between gap-3 px-4 py-3">'
    . '<h3 class="font-semibold">Новый блок</h3>'
    . '<svg class="section-chevron h-5 w-5 shrink-0 text-ink/60 transition-transform" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
    . '<path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    . '</summary>'
    . '<div class="border-t border-ink/10 px-4 py-3">'
    . $sectionForm([])
    . '</div></details>';

// Плавающая кнопка сохранения: одна на страницу, вне форм секций. Скрипт admin-sections.js
// включает её, когда хотя бы в одной форме есть изменения; без скрипта каждая секция
// сохраняется своей кнопкой. Обёртка не перехватывает нажатия мимо кнопки.
$saveBar = '<div class="pointer-events-none fixed bottom-4 right-4 z-40 max-w-xs md:bottom-auto md:right-6 md:top-24">'
    . '<div class="pointer-events-auto flex flex-col items-end gap-2 rounded-lg border border-ink/10 bg-white p-3 shadow-lg">'
    . '<p class="rounded-lg bg-bad/10 px-3 py-2 text-sm text-bad" data-save-error hidden></p>'
    . '<button class="admin-save-button inline-flex min-h-[44px] items-center rounded-lg bg-ink/20 px-4 py-2 text-sm font-medium text-ink/50 shadow-lg transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:bg-ink/20 disabled:text-ink/50" type="button" data-save-button data-dirty="0" disabled>Сохранить</button>'
    . '<span class="sr-only" data-save-status aria-live="polite">Все изменения сохранены</span>'
    . '</div></div>';

$content .= $saveBar;

/* @safe html */ echo '<div class="mt-3 space-y-3">' . $content . '</div>';
