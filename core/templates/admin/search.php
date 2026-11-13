<?php

declare(strict_types=1);

/** @var string $query */
/** @var list<array{title: string, items: list<array{title: string, subtitle: string, href: string, date: string}>}> $groups */
/** @var int $total */
/** @var int $minimum */

// Сквозной поиск редакции: одна строка ищет по страницам, новостям, товарам, объявлениям,
// урокам, медиафайлам и заявкам. Заявки видит только администратор — сервер решает это сам.
$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/search">'
    . '<label class="block text-sm"><span class="font-medium">Что найти</span>'
    . '<input class="mt-1.5 min-h-[44px] min-w-[16rem] rounded-lg border border-ink/20 bg-white px-3 py-2" type="search" name="q"'
    . ' value="' . e($query) . '" placeholder="Заголовок, адрес, артикул, контакт" autofocus></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Найти</button>'
    . '</form>';

$groupsHtml = '';

foreach ($groups as $group) {
    $items = '';
    foreach ($group['items'] as $item) {
        $date = trim((string) $item['date']);
        $dateLabel = $date !== '' ? formatRuDateTime(fromDb($date)) : '';

        $items .= '<li class="border-b border-ink/10 py-2">'
            . '<a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="' . e($item['href']) . '">' . e($item['title']) . '</a>'
            . '<p class="break-words text-sm text-ink/60">' . e($item['subtitle']) . '</p>'
            . ($dateLabel !== '' ? '<p class="text-xs text-ink/40">' . e($dateLabel) . '</p>' : '')
            . '</li>';
    }

    $groupsHtml .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">' . e($group['title']) . '</h2>'
        . '<ul class="mt-2">' . $items . '</ul>'
        . '</section>';
}

if ($query === '') {
    $hint = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Введите хотя бы '
        . e((string) $minimum) . ' символа — поиск пройдёт по всем разделам сразу.</p>';
} elseif (mb_strlen($query) < $minimum) {
    $hint = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Запрос короче '
        . e((string) $minimum) . ' символов — уточните его, чтобы поиск не выдавал полслова.</p>';
} elseif ($groups === []) {
    $hint = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">По запросу «' . e($query) . '» ничего не нашлось.</p>';
} else {
    $hint = '<p class="text-sm text-ink/70">Найдено записей: ' . e((string) $total) . '.</p>';
}

/* @safe html */ echo adminShell('Поиск по сайту', $search . $hint . $groupsHtml, null);
