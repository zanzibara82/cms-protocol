<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{id: string, title: string, slug: string, status: string, noindex: string, updated: string}> $rows */

// Список страниц: правка — по заголовку, создание — форма с op, а иконки справа сразу
// открывают публичную страницу, дублируют её, создают ссылку предпросмотра и убирают в корзину.
// Кнопки — отдельные формы, намерение приходит полем op: имя кнопки не попадает в POST, если
// человек отправил форму клавишей Enter. Чекбоксы массовых операций связаны с формой
// `bulk-form` атрибутом form, поэтому не оказываются внутри форм строки.
$rowsHtml = '';
foreach ($rows as $row) {
    $title = (string) $row['title'];
    $rowsHtml .= '<tr class="border-b border-ink/10">'
        . '<td class="py-2 pr-2 pl-4">'
        . '<label class="flex min-h-[44px] items-center justify-center">'
        . '<input class="h-5 w-5 min-h-[44px] shrink-0" type="checkbox" name="ids[]" value="' . e($row['id']) . '"'
        . ' form="bulk-form" aria-label="Отметить страницу «' . e($title) . '»">'
        . '</label></td>'
        . '<th class="py-2 pr-3 text-left font-medium"><a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin/pages/' . e($row['id']) . '">' . e($title) . '</a></th>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">/' . e($row['slug']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['status']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/70">' . e($row['noindex']) . '</td>'
        . '<td class="py-2 pr-3 text-sm text-ink/60">' . e($row['updated']) . '</td>'
        . '<td class="py-2 pr-4">'
        . '<div class="flex items-center justify-end gap-1">'
        . '<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-ink/60 hover:bg-ink/5 hover:text-ink"'
        . ' href="/' . e($row['slug']) . '" target="_blank" rel="noopener noreferrer"'
        . ' aria-label="Открыть страницу «' . e($title) . '»" title="Открыть страницу">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
        . '<circle cx="12" cy="12" r="2.75" stroke="currentColor" stroke-width="1.8"/>'
        . '</svg>'
        . '</a>'
        . '<form method="post" action="/admin/pages/' . e($row['id']) . '">' . csrfField()
        . '<input type="hidden" name="op" value="preview-page">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-ink/60 hover:bg-ink/5 hover:text-ink" type="submit"'
        . ' aria-label="Ссылка предпросмотра страницы «' . e($title) . '»" title="Создать ссылку предпросмотра">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M10 13a5 5 0 0 0 7.07 0l2.12-2.12a5 5 0 0 0-7.07-7.07L11 4.93M14 11a5 5 0 0 0-7.07 0L4.81 13.12a5 5 0 0 0 7.07 7.07L13 19.07" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>'
        . '</button>'
        . '</form>'
        . '<form method="post" action="/admin/pages/' . e($row['id']) . '">' . csrfField()
        . '<input type="hidden" name="op" value="duplicate-page">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-ink/60 hover:bg-ink/5 hover:text-ink" type="submit"'
        . ' aria-label="Дублировать страницу «' . e($title) . '»" title="Дублировать страницу">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<rect x="8" y="8" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/>'
        . '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
        . '</svg>'
        . '</button>'
        . '</form>'
        . '<form method="post" action="/admin/pages/' . e($row['id']) . '">' . csrfField()
        . '<input type="hidden" name="op" value="delete-page">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg text-bad hover:bg-bad/10" type="submit"'
        . ' aria-label="Удалить страницу «' . e($title) . '»" title="Убрать в корзину">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
        . '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '</button>'
        . '</form>'
        . '</div>'
        . '</td>'
        . '</tr>';
}

// Форма массовых операций живёт отдельно от таблицы: строки содержат свои формы, а вложенные
// формы браузер разрывает. Чекбоксы и кнопки ссылаются на неё атрибутом form.
$bulkForm = '<form id="bulk-form" method="post" action="/admin/pages">' . csrfField() . '</form>';

$bulkBar = $rows === []
    ? ''
    : $bulkForm . '<div class="flex flex-wrap items-center gap-2 rounded-lg border border-ink/10 bg-white p-3">'
        . '<span class="text-sm text-ink/60">С отмеченными страницами:</span>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit" form="bulk-form" name="op" value="bulk-publish">Опубликовать</button>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit" form="bulk-form" name="op" value="bulk-unpublish">Снять с публикации</button>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit" form="bulk-form" name="op" value="bulk-trash">Убрать в корзину</button>'
        . '</div>';

$table = $rows === []
    ? '<p class="text-sm text-ink/60">Страниц ещё нет — создайте первую формой ниже.</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[44rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-2 pl-4 font-medium"><span class="sr-only">Выбрать</span></th>'
        . '<th class="py-2 pr-3 font-medium">Заголовок</th><th class="py-2 pr-3 font-medium">Адрес</th>'
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
    . '<label class="block text-sm"><span class="block font-medium">Заголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" required></label>'
    . '<label class="block text-sm"><span class="block font-medium">Адрес</span>'
    . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" required>'
    . '<span class="mt-1.5 block text-ink/60">Латиницей, без «/» — из него строится ссылка /адрес.</span></label>'
    . '<label class="block text-sm"><span class="block font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description"></label>'
    . '<label class="block text-sm"><span class="block font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . adminCheckbox('noindex', 'Скрыть страницу от поисковиков')
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Создать</button>'
    . '</form></section>';

// Отказ: заголовок из словаря уведомлений, причина — из сессионного flash (admin/alert).
$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Страницы', $alert . $home . $bulkBar . $table . $form, $notice);
