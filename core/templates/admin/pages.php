<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{id: string, title: string, slug: string, status: string, noindex: string, updated: string}> $rows */

// Список страниц: правка и удаление живут в редакторе страницы, создание — форма с op.
// Кнопки — отдельные формы, намерение приходит полем op: имя кнопки не попадает в POST,
// если человек отправил форму клавишей Enter.
$rowsHtml = '';
foreach ($rows as $row) {
    $rowsHtml .= '<tr class="border-b border-ink/10">'
        . '<th class="py-2 pr-3 pl-4 text-left font-medium"><a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin/pages/' . e($row['id']) . '">' . e($row['title']) . '</a></th>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">/' . e($row['slug']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['status']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['noindex']) . '</td>'
        . '<td class="py-2 pr-4 text-sm text-ink/60">' . e($row['updated']) . '</td>'
        . '</tr>';
}

$table = $rows === []
    ? '<p class="text-sm text-ink/60">Страниц ещё нет — создайте первую формой ниже.</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Заголовок</th><th class="py-2 pr-3 font-medium">Адрес</th>'
        . '<th class="py-2 pr-3 font-medium">Статус</th><th class="py-2 pr-3 font-medium">Скрыта от поиска</th>'
        . '<th class="py-2 pr-4 font-medium">Обновлено</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

$home = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Блоки главной</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Главная «/» собирается секциями без строки страницы.</p>'
    . '<p class="mt-3"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" href="/admin/pages/home">Редактировать блоки главной</a></p>'
    . '</section>';

$input = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

$statusOptions = '';
foreach (PAGES_STATUSES as $status) {
    $label = $status === PAGES_STATUS_PUBLISHED ? 'Опубликовано' : 'Черновик';
    $statusOptions .= '<option value="' . e($status) . '">' . e($label) . '</option>';
}

$form = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Новая страница</h2>'
    . '<form class="mt-3 space-y-3" method="post" action="/admin/pages">' . csrfField()
    . '<input type="hidden" name="op" value="create-page">'
    . '<label class="block text-sm"><span class="font-medium">Заголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" required></label>'
    . '<label class="block text-sm"><span class="font-medium">Адрес</span>'
    . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" required>'
    . '<span class="mt-1 block text-ink/60">Латиницей, без «/» — из него строится ссылка /адрес.</span></label>'
    . '<label class="block text-sm"><span class="font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description"></label>'
    . '<label class="block text-sm"><span class="font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . '<label class="flex items-center gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="noindex" value="1">'
    . '<span>Скрыть страницу от поисковиков</span></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Создать</button>'
    . '</form></section>';

// Отказ: заголовок из словаря уведомлений, причина — из сессионного flash (admin/alert).
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Страницы', $alert . $home . $table . $form, $notice);
