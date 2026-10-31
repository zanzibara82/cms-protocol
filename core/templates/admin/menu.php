<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array<string, mixed>> $headerRows */
/** @var list<array<string, mixed>> $footerRows */

// Меню шапки и подвала: две карты, каждая со строками пунктов (правка полей) и inline-формами
// действий (формы нельзя вкладывать друг в друга, образец — секции конструктора). Внизу каждой
// карты — форма добавления пункта со скрытым слотом. Класс min-h-[44px] стоит литералом
// в каждом атрибуте class (страж TapTargetTest читает классы значения атрибута).
// У строки шапки поля «колонка» нет: раздел — подпись только подвала.
$input = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

/** Пара «скрытое поле» формы действия. */
$field = static function (string $name, string $value): string {
    return '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
};

/** Кнопка-форма действия: свой op и свои скрытые поля, без вложенных форм. */
$actionForm = static function (string $op, array $hidden, string $label, string $classes) use ($field): string {
    $fields = '';
    foreach ($hidden as $name => $value) {
        $fields .= $field($name, (string) $value);
    }

    return '<form class="inline" method="post" action="/admin/menu">' . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">' . $fields
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm ' . e($classes) . '" type="submit">' . e($label) . '</button></form>';
};

/**
 * Строка пункта: форма правки полей (op=save-item, скрытый идентификатор) плюс карточки-действия.
 *
 * @param array<string, mixed> $row
 */
$itemCard = static function (array $row) use ($input, $field, $actionForm): string {
    $id = (string) $row['id'];
    $visible = (int) $row['visible'] === 1;
    $state = $visible ? '' : '<span class="text-sm text-ink/50">— скрыт</span>';

    $actions = $actionForm('move-item', ['item' => $id, 'dir' => 'up'], 'Выше', 'border border-ink/20 hover:bg-ink/5')
        . ' ' . $actionForm('move-item', ['item' => $id, 'dir' => 'down'], 'Ниже', 'border border-ink/20 hover:bg-ink/5')
        . ' ' . $actionForm('toggle-item', ['item' => $id], $visible ? 'Скрыть' : 'Показать', 'border border-ink/20 hover:bg-ink/5')
        . ' ' . $actionForm('delete-item', ['item' => $id], 'Удалить', 'text-bad hover:bg-bad/10');

    return '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<header class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e((string) $row['label']) . $state . '</h3>'
        . '<div class="flex flex-wrap justify-end gap-2">' . $actions . '</div>'
        . '</header>'
        . '<form class="mt-3 space-y-3" method="post" action="/admin/menu">' . csrfField()
        . '<input type="hidden" name="op" value="save-item">' . $field('item', $id)
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<label class="block text-sm"><span class="font-medium">Подпись</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="label" value="' . e((string) $row['label']) . '"></label>'
        . '<label class="block text-sm"><span class="font-medium">Адрес</span>'
        . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="href" value="' . e((string) $row['href']) . '">'
        . '<span class="mt-1 block text-ink/60">Якорь (#место), раздел сайта (/news), внешняя ссылка, почта или телефон.</span></label>'
        . '</div>'
        . '<label class="flex items-center gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="blank" value="1"'
        . ((int) $row['blank'] === 1 ? ' checked' : '') . '><span>Открыть в новом окне</span></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Сохранить пункт</button>'
        . '</form>'
        . '</section>';
};

/**
 * Форма добавления пункта: слот приходит скрытым полем карты. У подвала есть поле колонки,
 * у шапки оно не рисуется — нормализатор раздел шапки всё равно уходит молча.
 */
$addForm = static function (string $slot) use ($input, $field): string {
    $slotName = MENU_SLOT_NAMES[$slot] ?? $slot;
    $sectionField = $slot === 'FOOTER'
        ? '<label class="block text-sm"><span class="font-medium">Колонка</span>'
            . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="section" value="">'
            . '<span class="mt-1 block text-ink/60">Подпись колонки подвала; пусто — запасная «Ссылки».</span></label>'
        : '';

    return '<form class="mt-3 space-y-3 border-t border-ink/10 pt-3" method="post" action="/admin/menu">' . csrfField()
        . '<input type="hidden" name="op" value="create-item">' . $field('slot', $slot)
        . '<div class="grid gap-3 sm:grid-cols-3">'
        . '<label class="block text-sm"><span class="font-medium">Подпись</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="label" required></label>'
        . '<label class="block text-sm"><span class="font-medium">Адрес</span>'
        . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="href" required>'
        . '<span class="mt-1 block text-ink/60">Якорь (#место), раздел сайта (/news), внешняя ссылка, почта или телефон.</span></label>'
        . $sectionField
        . '</div>'
        . '<label class="flex items-center gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="blank" value="1">'
        . '<span>Открыть в новом окне</span></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Добавить пункт</button>'
        . '<span class="text-sm text-ink/60">Новый пункт встанет в конец слота «' . e($slotName) . '».</span>'
        . '</form>';
};

$cardsHtml = '';
$cards = [
    ['slot' => 'HEADER', 'rows' => $headerRows, 'hint' => 'Строки шапки печатаются по порядку, следом — пункты включённых разделов.'],
    ['slot' => 'FOOTER', 'rows' => $footerRows, 'hint' => 'Пункты подвала группируются в колонки по подписи; пустая подпись — запасная «Ссылки».'],
];

foreach ($cards as $card) {
    $itemsHtml = '';
    foreach ($card['rows'] as $row) {
        $itemsHtml .= $itemCard($row);
    }

    $empty = $card['rows'] === [] ? '<p class="text-sm text-ink/60">Пунктов ещё нет — добавьте первый формой ниже.</p>' : '';

    $cardsHtml .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">' . e(MENU_SLOT_NAMES[$card['slot']]) . '</h2>'
        . '<p class="mt-1 text-sm text-ink/70">' . e($card['hint']) . '</p>'
        . $empty
        . $itemsHtml
        . $addForm($card['slot'])
        . '</section>';
}

// Отказ: заголовок из словаря уведомлений, причина — из сессионного flash (admin/alert).
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Меню', $alert . $cardsHtml, $notice);
