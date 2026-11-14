<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array<string, mixed>> $rows */
/** @var string $query */
/** @var string $usageId */
/** @var string $usageTitle */
/** @var list<array{label: string, href: string}> $usage */

// Медиатека: загрузка, подписи ALT и контроль использования. Файл хранится там же, где картинки
// конструктора, поэтому старая страница продолжает работать без переезда. Удаление разрешено
// только когда путь нигде не используется — сервер проверяет это сам, а экран показывает список
// ссылок, если файл занят.
$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

$uploadForm = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Загрузить файл</h2>'
    . '<p class="mt-1.5 text-sm text-ink/70">JPG, PNG или WebP до 5 МБ. Подпись ALT обязательна для картинок, которые несут смысл.</p>'
    . '<form class="mt-3 space-y-3" method="post" action="/admin/media" enctype="multipart/form-data">' . csrfField()
    . '<input type="hidden" name="op" value="upload">'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . partial('partials/file-picker', [
        'name' => '',
        'fileKey' => 'file',
        'label' => 'Файл',
        'hint' => '',
        'buttonTitle' => 'Выбрать файл на компьютере',
        'preview' => '',
        'required' => true,
    ])
    . '<label class="block text-sm"><span class="block font-medium">Название</span>'
    . '<input class="mt-1.5 min-h-[44px] ' . e($input) . '" type="text" name="title" maxlength="190"></label>'
    . '</div>'
    . '<label class="block text-sm"><span class="block font-medium">ALT — описание для незрячих и поиска</span>'
    . '<input class="mt-1.5 min-h-[44px] ' . e($input) . '" type="text" name="alt" maxlength="300"></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Загрузить</button>'
    . '</form></section>';

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/media">'
    . '<label class="block text-sm"><span class="block font-medium">Поиск по файлам</span>'
    . '<input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="search" name="q"'
    . ' value="' . e($query) . '" placeholder="Название, ALT или путь"></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Найти</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/media">Сбросить</a>'
    . '</form>';

$sync = '<form class="flex flex-wrap items-center gap-2" method="post" action="/admin/media">' . csrfField()
    . '<input type="hidden" name="op" value="sync">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Найти файлы, загруженные до медиатеки</button>'
    . '<span class="text-sm text-ink/60">Файлы из каталога загрузок появятся в списке без повторной загрузки.</span>'
    . '</form>';

$usagePanel = '';

if ($usageTitle !== '') {
    $usageRows = '';
    foreach ($usage as $item) {
        $usageRows .= '<li class="border-b border-ink/10 py-1"><a class="inline-flex min-h-[44px] items-center break-words text-sm text-accent underline-offset-4 hover:underline" href="' . e($item['href']) . '">' . e($item['label']) . '</a></li>';
    }

    if ($usageRows === '') {
        $usageRows = '<li class="py-1 text-sm text-good">Файл нигде не используется — удалять можно.</li>';
    }

    $usagePanel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">Где используется «' . e($usageTitle) . '»</h2>'
        . '<ul class="mt-2">' . $usageRows . '</ul>'
        . '<p class="mt-2 text-sm"><a class="inline-flex min-h-[44px] items-center text-ink/70 underline underline-offset-4 hover:text-ink" href="/admin/media">Вернуться ко всем файлам</a></p>'
        . '</section>';
}

$cards = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $path = (string) $row['path'];
    $title = (string) $row['title'];
    $alt = (string) $row['alt'];
    $caption = $title !== '' ? $title : basename($path);
    $dimensions = (int) $row['width'] > 0 && (int) $row['height'] > 0
        ? (string) $row['width'] . '×' . (string) $row['height']
        : 'размер неизвестен';
    $altNote = $alt === ''
        ? '<p class="mt-1 text-xs text-bad">ALT не заполнен — картинка без описания.</p>'
        : '<p class="mt-1 break-words text-xs text-ink/60">ALT: ' . e($alt) . '</p>';

    $cards .= '<article class="flex flex-col overflow-hidden rounded-lg border border-ink/10 bg-white">'
        . '<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center bg-cream p-2" href="' . e($path) . '" target="_blank" rel="noopener noreferrer">'
        . '<img class="max-h-40 w-auto max-w-full rounded object-contain" src="' . e($path) . '" alt="' . e($alt) . '" loading="lazy">'
        . '</a>'
        . '<div class="flex flex-1 flex-col gap-2 p-3">'
        . '<p class="break-words text-sm font-medium">' . e($caption) . '</p>'
        . '<p class="break-all font-mono text-xs text-ink/50">' . e($path) . '</p>'
        . '<p class="text-xs text-ink/60">' . e($dimensions) . ' · ' . e(mediaBytes((int) $row['bytes'])) . '</p>'
        . $altNote
        . '<form class="mt-auto space-y-2" method="post" action="/admin/media">' . csrfField()
        . '<input type="hidden" name="op" value="save">'
        . '<input type="hidden" name="media" value="' . e($id) . '">'
        . '<label class="block text-sm"><span class="block font-medium">Название</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e($title) . '" maxlength="190"></label>'
        . '<label class="block text-sm"><span class="block font-medium">ALT</span>'
        . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="alt" value="' . e($alt) . '" maxlength="300"></label>'
        . '<div class="flex flex-wrap items-center gap-2">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Сохранить подписи</button>'
        . '</div></form>'
        . '<div class="flex flex-wrap items-center gap-2">'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/media?usage=' . e(rawurlencode($id)) . '">Где используется</a>'
        . '<form class="inline" method="post" action="/admin/media">' . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<input type="hidden" name="media" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Удалить</button>'
        . '</form></div>'
        . '</div></article>';
}

if ($rows === []) {
    $cards = '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">'
        . ($query !== ''
            ? 'Ни один файл не подходит под запрос «' . e($query) . '».'
            : 'Файлов пока нет — загрузите первый формой выше.')
        . '</p>';
} else {
    $cards = '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">' . $cards . '</div>';
}

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Медиатека', $alert . $uploadForm . $search . $sync . $usagePanel . $cards, $notice);
