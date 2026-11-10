<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{id: string, title: string, slug: string, status: string, noindex: string, updated: string}> $rows */

// Список страниц: правка — по заголовку, создание — форма с op, а иконки справа сразу
// открывают публичную страницу и удаляют её. Кнопки — отдельные формы, намерение
// приходит полем op: имя кнопки не попадает в POST, если человек отправил форму клавишей Enter.
$rowsHtml = '';
foreach ($rows as $row) {
    $rowsHtml .= '<tr class="border-b border-ink/10">'
        . '<th class="py-2 pr-3 pl-4 text-left font-medium"><a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin/pages/' . e($row['id']) . '">' . e($row['title']) . '</a></th>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">/' . e($row['slug']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['status']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['noindex']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/60">' . e($row['updated']) . '</td>'
        . '<td class="py-2 pr-4">'
        . '<div class="flex items-center justify-end gap-1">'
        . '<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-ink/60 hover:bg-ink/5 hover:text-ink"'
        . ' href="/' . e($row['slug']) . '" target="_blank" rel="noopener noreferrer"'
        . ' aria-label="Открыть страницу «' . e($row['title']) . '»" title="Открыть страницу">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
        . '<circle cx="12" cy="12" r="2.75" stroke="currentColor" stroke-width="1.8"/>'
        . '</svg>'
        . '</a>'
        . '<form method="post" action="/admin/pages/' . e($row['id']) . '">' . csrfField()
        . '<input type="hidden" name="op" value="delete-page">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-bad hover:bg-bad/10" type="submit"'
        . ' aria-label="Удалить страницу «' . e($row['title']) . '»" title="Удалить страницу">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '</button>'
        . '</form>'
        . '</div>'
        . '</td>'
        . '</tr>';
}

$table = $rows === []
    ? '<p class="text-sm text-ink/60">Страниц ещё нет — создайте первую формой ниже.</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Заголовок</th><th class="py-2 pr-3 font-medium">Адрес</th>'
        . '<th class="py-2 pr-3 font-medium">Статус</th><th class="py-2 pr-3 font-medium">Скрыта от поиска</th>'
        . '<th class="py-2 pr-3 font-medium">Обновлено</th>'
        . '<th class="py-2 pr-4 font-medium"><span class="sr-only">Действия</span></th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

$home = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Блоки главной</h2>'
    . '<p class="mt-1.5 text-sm text-ink/70">Главная «/» собирается секциями без строки страницы.</p>'
    . '<p class="mt-3"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" href="/admin/pages/home">Редактировать блоки главной</a></p>'
    . '</section>';

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

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
    . '<span class="mt-1.5 block text-ink/60">Латиницей, без «/» — из него строится ссылка /адрес.</span></label>'
    . '<label class="block text-sm"><span class="font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description"></label>'
    . '<label class="block text-sm"><span class="font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . adminCheckbox('noindex', 'Скрыть страницу от поисковиков')
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Создать</button>'
    . '</form></section>';

// Отказ: заголовок из словаря уведомлений, причина — из сессионного flash (admin/alert).
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Страницы', $alert . $home . $table . $form, $notice);
