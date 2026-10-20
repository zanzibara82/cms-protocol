<?php

declare(strict_types=1);

/**
 * Справочник категорий доски: дерево с отступами, добавление, правка, порядок и видимость.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool, depth: int}> $rows
 */
$parentOptions = static function (string $exceptId, string $selected = '') use ($rows): string {
    $options = '<option value=""' . ($selected === '' ? ' selected' : '') . '>— корень —</option>';

    foreach ($rows as $row) {
        if ((string) $row['id'] === $exceptId) {
            continue;
        }

        $options .= '<option value="' . e((string) $row['id']) . '"'
            . ((string) $row['id'] === $selected ? ' selected' : '') . '>'
            . e(str_repeat('— ', (int) $row['depth']) . (string) $row['title']) . '</option>';
    }

    return $options;
};

$create = '<form class="rounded-xl border border-ink/10 bg-white p-4" method="post" action="/classifieds/admin/categories">'
    . csrfField()
    . '<input type="hidden" name="op" value="create">'
    . '<div class="flex flex-wrap items-end gap-3">'
    . '<label class="block" for="title-new"><span class="text-sm text-ink/60">Название</span>'
    . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="title-new" name="title" maxlength="120" value="" placeholder="Авто"></label>'
    . '<label class="block" for="slug-new"><span class="text-sm text-ink/60">Адрес (пусто — из названия)</span>'
    . '<input class="mt-1 min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" id="slug-new" name="slug" maxlength="60" value=""></label>'
    . '<label class="block" for="parent-new"><span class="text-sm text-ink/60">Родитель</span>'
    . '<select class="mt-1 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="parent-new" name="parent">'
    . $parentOptions('')
    . '</select></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Добавить</button>'
    . '</div></form>';

$rowHtml = static function (array $row) use ($parentOptions): string {
    $id = (string) $row['id'];
    $parent = (string) ($row['parent_id'] ?? '');
    $visible = (bool) $row['visible'];

    $save = '<form class="inline-flex flex-wrap items-end gap-2" method="post" action="/classifieds/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="op" value="save">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<input class="min-h-[44px] rounded-lg border border-ink/20 px-3 py-2" name="title" maxlength="120" value="' . e((string) $row['title']) . '" aria-label="Название">'
        . '<input class="min-h-[44px] w-36 rounded-lg border border-ink/20 px-3 py-2" name="slug" maxlength="60" value="' . e((string) $row['slug']) . '" aria-label="Адрес">'
        . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="parent" aria-label="Родитель">'
        . $parentOptions($id, $parent)
        . '</select>'
        . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" name="visible" aria-label="Видимость">'
        . '<option value="1"' . ($visible ? ' selected' : '') . '>Видна</option>'
        . '<option value="0"' . ($visible ? '' : ' selected') . '>Скрыта</option>'
        . '</select>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Сохранить</button>'
        . '</form>';

    $orderNav = '<form class="inline-flex gap-1" method="post" action="/classifieds/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="up">Выше</button>'
        . '<button class="inline-flex min-h-[44px] min-w-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="down">Ниже</button>'
        . '</form>';

    $delete = '<form class="inline-flex" method="post" action="/classifieds/admin/categories">'
        . csrfField()
        . '<input type="hidden" name="op" value="delete">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Удалить</button>'
        . '</form>';

    return '<li class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4" style="margin-left: '
        . e((string) ((int) $row['depth'] * 24)) . 'px">'
        . '<div class="min-w-0">'
        . '<p class="font-medium">' . e((string) $row['title'])
        . ((bool) $row['visible'] ? '' : ' <span class="rounded bg-ink/10 px-2 py-0.5 text-xs">скрыта</span>') . '</p>'
        . '<p class="text-xs text-ink/50">' . e((string) $row['slug']) . '</p>'
        . '</div>'
        . '<div class="flex flex-wrap items-end gap-2">' . $save . $orderNav . $delete . '</div></li>';
};

$listing = '';
foreach ($rows as $row) {
    $listing .= $rowHtml($row);
}

$tree = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Категорий пока нет.</p>'
    : '<ul class="space-y-3">' . $listing . '</ul>';

$content = $nav
    . '<h1 class="text-2xl font-bold">Категории</h1>'
    . $create
    . $tree;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Категории', $content, $notice);
