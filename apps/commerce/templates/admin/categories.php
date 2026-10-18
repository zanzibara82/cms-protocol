<?php

declare(strict_types=1);

/**
 * Справочник рубрик магазина.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, slug: string, title: string, order_index: int, count: int}> $rows
 */
$create = '<form class="rounded-xl border border-ink/10 bg-white p-4" method="post" action="/commerce/admin/categories">'
    . csrfField()
    . '<input type="hidden" name="op" value="create">'
    . '<div class="flex flex-wrap items-end gap-3">'
    . '<label class="block" for="title-new"><span class="text-sm text-ink/60">Название</span>'
    . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="title-new" name="title" maxlength="120" value="" placeholder="Техника"></label>'
    . '<label class="block" for="slug-new"><span class="text-sm text-ink/60">Адрес (пусто — из названия)</span>'
    . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="slug-new" name="slug" maxlength="60" value=""></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Добавить</button>'
    . '</div></form>';

/**
 * Строка справочника: правка и удаление отдельными формами — имя кнопки не доходит до
 * сервера, если форму отправили клавишей Enter.
 *
 * @param array<string, mixed> $row
 */
$rowHtml = static function (array $row): string {
    $id = (string) $row['id'];
    $orderNav = '<form class="inline-flex gap-1" method="post" action="/commerce/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="up">Выше</button>'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="down">Ниже</button>'
        . '</form>';

    $rename = '<form class="inline-flex flex-wrap items-end gap-2" method="post" action="/commerce/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="op" value="rename">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<input class="min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" name="title" maxlength="120" value="' . e((string) $row['title']) . '">'
        . '<input class="min-h-[44px] w-36 rounded-lg border border-ink/20 px-3 py-2" name="slug" maxlength="60" value="' . e((string) $row['slug']) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Сохранить</button>'
        . '</form>';

    $delete = '<form class="inline-flex" method="post" action="/commerce/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Удалить</button>'
        . '</form>';

    return '<li class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="min-w-0"><p class="font-medium">' . e((string) $row['title']) . '</p>'
        . '<p class="text-xs text-ink/50">' . e((string) $row['slug']) . ' · товаров: ' . e((string) $row['count']) . '</p></div>'
        . '<div class="flex flex-wrap items-end gap-2">' . $orderNav . $rename . $delete . '</div></li>';
};

$listing = '';
foreach ((array) $rows as $row) {
    $listing .= $rowHtml((array) $row);
}

$empty = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Рубрик пока нет.</p>'
    : '<ul class="space-y-3">' . $listing . '</ul>';

$content = $nav
    . '<h1 class="text-2xl font-bold">Рубрики</h1>'
    . $create
    . $empty;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Рубрики', $content, $notice);
