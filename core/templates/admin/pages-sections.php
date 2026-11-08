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
// Поле картинки стоит в одной строке с кнопкой выбора файла: отступ задаёт обёртка, а ширину
// делят input и квадратная зона нажатия 44×44.
$imageInput = 'w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

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
        $html .= '<div class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">'
            . '<label class="text-sm"><span class="font-medium">Подпись</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][label]" value="' . e((string) ($row['label'] ?? '')) . '"></label>'
            . '<label class="text-sm"><span class="font-medium">Адрес</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="buttons[' . e((string) $index) . '][href]" value="' . e((string) ($row['href'] ?? '')) . '"></label>'
            . adminCheckbox('buttons[' . (string) $index . '][blank]', 'Новая вкладка', '1', (bool) ($row['blank'] ?? false))
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
$sectionForm = static function (?array $section) use ($target, $kindSelect, $buttonsRows, $itemsRows, $kindFields, $input, $imageInput, $field): string {
    $exists = $section !== null;
    $values = static fn (string $name): string => (string) ($section[$name] ?? '');
    $visible = $exists ? (int) $section['visible'] === 1 : true;
    $op = $exists ? 'save-section' : 'create-section';
    $submitLabel = $exists ? 'Сохранить секцию' : 'Добавить секцию';
    $id = $exists ? $field('section', (string) $section['id']) : '';
    $sectionKey = $exists ? (string) $section['id'] : 'new';
    $imageFieldId = 'section-image-' . $sectionKey;
    $imageFileId = 'section-image-file-' . $sectionKey;

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

    return '<form class="mt-3 space-y-3" method="post" action="' . e($target) . '" enctype="multipart/form-data" data-section-form>' . csrfField()
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
        . '<div class="text-sm"><label class="font-medium" for="' . e($imageFieldId) . '">Картинка</label>'
        . '<div class="mt-1 flex items-start gap-2">'
        . '<input class="min-h-[44px] ' . e($imageInput . ' min-w-0 flex-1') . ' font-mono" id="' . e($imageFieldId) . '" type="text" name="image" value="' . e($values('image')) . '">'
        . '<input class="sr-only" id="' . e($imageFileId) . '" type="file" name="image_file" accept="image/jpeg,image/png,image/webp">'
        . '<label class="inline-flex min-h-[44px] min-w-[44px] cursor-pointer items-center justify-center rounded-lg border border-ink/20 bg-white hover:bg-ink/5" for="' . e($imageFileId) . '" title="Выбрать картинку с компьютера">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>'
        . '<span class="sr-only">Выбрать картинку с компьютера</span></label>'
        . '</div>'
        . '<span class="mt-1 block text-ink/60">Путь на сайте или файл: jpg, png, webp до 5 МБ.</span></div>'
        . '<label class="block text-sm"><span class="font-medium">Описание картинки</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="image_alt" value="' . e($values('image_alt')) . '"></label>'
        . '</div>'
        . $settingsBlock
        . '<div class="space-y-2"><h4 class="text-sm font-medium">Кнопки</h4>' . $buttonsRows($exists ? pagesButtonsOf($section['buttons'] ?? null) : []) . '</div>'
        . '<div class="space-y-2"><h4 class="text-sm font-medium">Строки (карточки, шаги, вопросы)</h4>' . $itemsRows($exists ? pagesItemsOf($section['items'] ?? null) : []) . '</div>'
        . adminCheckbox('visible', 'Показывать на странице', '1', $visible)
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

    $content .= '<details class="rounded-lg border border-ink/10 bg-white" open>'
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

$addCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h3 class="font-semibold">Новая секция</h3>'
    . $sectionForm(null)
    . '</section>';

$content .= $sections === []
    ? '<p class="text-sm text-ink/60">Секций ещё нет — добавьте первую формой ниже.</p>' . $addCard
    : $addCard;

// Плавающая кнопка живёт вне форм: она собирает изменённые формы и отправляет их сама. Без
// скрипта остаётся неактивной, а каждая секция сохраняется своей кнопкой внутри формы.
$content .= '<div class="pointer-events-none fixed bottom-4 right-4 z-40 flex flex-col items-end gap-2 md:bottom-auto md:right-6 md:top-24">'
    . '<div class="pointer-events-auto max-w-xs rounded-lg border border-bad/30 bg-white px-4 py-3 text-sm text-bad shadow-lg" data-save-error role="alert" hidden></div>'
    . '<button class="admin-save-button pointer-events-auto inline-flex min-h-[44px] items-center rounded-lg bg-ink/20 px-4 py-2 text-sm font-medium text-ink/50 shadow-lg transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:bg-ink/20 disabled:text-ink/50" type="button" data-save-button data-dirty="0" disabled>Сохранить</button>'
    . '<span class="sr-only" data-save-status aria-live="polite">Все изменения сохранены</span>'
    . '</div>';

/* @safe html */ echo $content;
