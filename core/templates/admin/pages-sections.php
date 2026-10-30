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
$input = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

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
        $checked = ($row['blank'] ?? false) ? ' checked' : '';
        $html .= '<div class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">'
            . '<label class="text-sm"><span class="font-medium">Подпись</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][label]" value="' . e((string) ($row['label'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Адрес</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][href]" value="' . e((string) ($row['href'] ?? '')) . '"></label>'
            . '<label class="flex items-center gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="buttons[' . e((string) $index) . '][blank]" value="1"' . $checked . '><span>Новая вкладка</span></label>'
            . '</div>';
    }

    return $html;
};

/** Строки items секции: у карточек пользуются всеми полями, у шагов и вопросов — первыми двумя. */
$itemsRows = static function (array $rows) use ($input): string {
    $html = '';
    foreach (array_merge($rows, [['title' => '', 'text' => '', 'href' => '', 'image' => '', 'note' => '']]) as $index => $row) {
        $html .= '<div class="grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-5">'
            . '<label class="text-sm"><span class="font-medium">Заголовок</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][title]" value="' . e((string) ($row['title'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Текст</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][text]" value="' . e((string) ($row['text'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Адрес ссылки</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][href]" value="' . e((string) ($row['href'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Картинка</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][image]" value="' . e((string) ($row['image'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Подпись</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="items[' . e((string) $index) . '][note]" value="' . e((string) ($row['note'] ?? '')) . '"></label>'
            . '</div>';
    }

    return $html;
};

/**
 * Форма полей секции: у существующей — заполненная с op=save-section, у новой — пустая
 * с op=create-section и первой строкой кнопок и items.
 *
 * @param array<string, mixed>|null $section
 */
$sectionForm = static function (?array $section) use ($target, $kindSelect, $buttonsRows, $itemsRows, $kindFields, $input, $field): string {
    $exists = $section !== null;
    $values = static fn (string $name): string => (string) ($section[$name] ?? '');
    $visible = $exists ? (int) $section['visible'] === 1 : true;
    $checked = $visible ? ' checked' : '';
    $op = $exists ? 'save-section' : 'create-section';
    $submitLabel = $exists ? 'Сохранить секцию' : 'Добавить секцию';
    $id = $exists ? $field('section', (string) $section['id']) : '';

    // Поля настроек вклада: рисуются по описанию приложения (pages_block_kinds), значения едут
    // в JSON как есть. У новой формы вид ещё не выбран — блока нет; после создания и перезагрузки
    // поля появятся в форме самой секции.
    $settingsValues = $exists && is_array($section['settings'] ?? null) ? $section['settings'] : [];
    $settingsHtml = '';
    $currentKind = $exists ? (string) $section['kind'] : '';

    foreach ($kindFields[$currentKind] ?? [] as $setting) {
        $settingsHtml .= '<label class="block text-sm"><span class="font-medium">' . e($setting['label']) . '</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="settings[' . e($setting['key']) . ']" value="'
            . e((string) ($settingsValues[$setting['key']] ?? '')) . '">'
            . '<span class="mt-1 block text-ink/60">' . e($setting['hint']) . '</span></label>';
    }

    $settingsBlock = $settingsHtml === ''
        ? ''
        : '<div class="space-y-2"><h4 class="text-sm font-medium">Настройки вклада</h4><div class="grid gap-3 sm:grid-cols-3">' . $settingsHtml . '</div></div>';

    return '<form class="mt-3 space-y-3" method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">' . $id
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<label class="block text-sm"><span class="font-medium">Вид</span>' . $kindSelect($exists ? (string) $section['kind'] : '') . '</label>'
        . '<label class="block text-sm"><span class="font-medium">Якорь</span>'
        . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="anchor" value="' . e($values('anchor')) . '">'
        . '<span class="mt-1 block text-ink/60">Ссылка на место секции: /страница#якорь. Пусто — ключ соберётся из заголовка.</span></label>'
        . '</div>'
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<label class="block text-sm"><span class="font-medium">Надзаголовок</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="badge" value="' . e($values('badge')) . '"></label>'
        . '<label class="block text-sm"><span class="font-medium">Заголовок</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e($values('title')) . '"></label>'
        . '</div>'
        . '<label class="block text-sm"><span class="font-medium">Текст</span>'
        . '<textarea class="min-h-[44px] ' . e($input) . '" name="lead_text" rows="3">' . e($values('lead_text')) . '</textarea>'
        . '<span class="mt-1 block text-ink/60">Абзацы разделяются пустой строкой.</span></label>'
        . '<div class="grid gap-3 sm:grid-cols-3">'
        . '<label class="block text-sm"><span class="font-medium">Примечание</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="note" value="' . e($values('note')) . '"></label>'
        . '<label class="block text-sm"><span class="font-medium">Картинка</span>'
        . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="image" value="' . e($values('image')) . '"></label>'
        . '<label class="block text-sm"><span class="font-medium">Описание картинки</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="image_alt" value="' . e($values('image_alt')) . '"></label>'
        . '</div>'
        . $settingsBlock
        . '<div class="space-y-2"><h4 class="text-sm font-medium">Кнопки</h4>' . $buttonsRows($exists ? pagesButtonsOf($section['buttons'] ?? null) : []) . '</div>'
        . '<div class="space-y-2"><h4 class="text-sm font-medium">Строки (карточки, шаги, вопросы)</h4>' . $itemsRows($exists ? pagesItemsOf($section['items'] ?? null) : []) . '</div>'
        . '<label class="flex items-center gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="visible" value="1"' . $checked . '><span>Показывать на странице</span></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">' . e($submitLabel) . '</button>'
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

    $content .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<header class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e($kindName) . $state . '</h3>'
        . '<div class="flex flex-wrap justify-end gap-2">' . $actions . '</div>'
        . '</header>'
        . $sectionForm($section)
        . '</section>';
}

$addCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h3 class="font-semibold">Новая секция</h3>'
    . $sectionForm(null)
    . '</section>';

$content .= $sections === []
    ? '<p class="text-sm text-ink/60">Секций ещё нет — добавьте первую формой ниже.</p>' . $addCard
    : $addCard;

/* @safe html */ echo $content;
