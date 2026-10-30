<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var array<string, mixed> $page */
/** @var list<array{kind: string, name: string}> $kinds */
/** @var list<array<string, mixed>> $sections */

// Редактор страницы: поля страницы (адрес, статус, noindex) и секции под ними. Удаление —
// отдельная форма: формы не вкладываются друг в друга.
$input = 'mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$target = '/admin/pages/' . (string) $page['id'];

$statusOptions = '';
foreach (PAGES_STATUSES as $status) {
    $label = $status === PAGES_STATUS_PUBLISHED ? 'Опубликовано' : 'Черновик';
    $selected = (string) $page['status'] === $status ? ' selected' : '';
    $statusOptions .= '<option value="' . e($status) . '"' . $selected . '>' . e($label) . '</option>';
}

$pageForm = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Поля страницы</h2>'
    . '<form class="mt-3 space-y-3" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="save-page">'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block text-sm"><span class="font-medium">Заголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e((string) $page['title']) . '" required></label>'
    . '<label class="block text-sm"><span class="font-medium">Адрес</span>'
    . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" value="' . e((string) $page['slug']) . '" required>'
    . '<span class="mt-1 block text-ink/60">При смене адреса прежний продолжит работать — переезд запишется автоматически.</span></label>'
    . '</div>'
    . '<label class="block text-sm"><span class="font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description" value="' . e((string) $page['description']) . '"></label>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block text-sm"><span class="font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . '<label class="flex items-end gap-2 text-sm"><input class="min-h-[44px] size-11 rounded border border-ink/20 bg-white" type="checkbox" name="noindex" value="1"'
    . ((int) $page['noindex'] === 1 ? ' checked' : '')
    . '><span>Скрыть страницу от поисковиков</span></label>'
    . '</div>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Сохранить страницу</button>'
    . '</form></section>';

$deleteForm = '<section class="rounded-lg border border-bad/30 bg-white p-4">'
    . '<form class="flex flex-wrap items-center justify-between gap-3" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="delete-page">'
    . '<p class="text-sm text-ink/70">Удаление уносит страницу, её блоки и историю адресов — действия нет.</p>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Удалить страницу</button>'
    . '</form></section>';

$sectionsCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Блоки страницы</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Порядок на странице — как в этом списке, сверху вниз.</p>'
    . '</section>'
    . partial('admin/pages-sections', ['sections' => $sections, 'target' => $target, 'kinds' => $kinds]);

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Страница «' . (string) $page['title'] . '»', $alert . $pageForm . $sectionsCard . $deleteForm, $notice);
