<?php

declare(strict_types=1);

/**
 * Список товаров: фильтры статуса и рубрики, поиск, страницы.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $query
 * @var string $category
 * @var string $status
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int, perPage: int} $listing
 * @var list<array{id: string, slug: string, title: string, order_index: int}> $categories
 */
$CHIP_ON = 'bg-ink text-cream';
$CHIP_OFF = 'border border-ink/10 bg-cream hover:bg-ink/5';

/**
 * Собрать адрес списка с теми же фильтрами, кроме изменяемого.
 *
 * @param string|null $status
 * @param string|null $category
 * @param int $page
 */
$hrefWith = static function ($status, $category, int $page = 1): string {
    $parts = [];
    if (is_string($status) && $status !== '') {
        $parts[] = 'status=' . rawurlencode($status);
    }
    if (is_string($category) && $category !== '') {
        $parts[] = 'cat=' . rawurlencode($category);
    }
    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $parts === [] ? '/commerce/admin/products' : '/commerce/admin/products?' . implode('&', $parts);
};

$tabs = '';
foreach (['' => 'Все', 'DRAFT' => 'Черновики', 'PUBLISHED' => 'В продаже'] as $value => $label) {
    $active = $value === $status;
    $tabs .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
        . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e($hrefWith($value, $category)) . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . e($label) . '</a>';
}

$chips = '';
if ($categories !== []) {
    $chips = '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
        . e($category === '' ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e($hrefWith($status, '')) . '">Все рубрики</a>';
    foreach ($categories as $row) {
        $active = (string) $row['id'] === $category;
        $chips .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
            . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
            . e($hrefWith($status, (string) $row['id'])) . '">'
            . e((string) $row['title']) . '</a>';
    }
}

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/commerce/admin/products">'
    . ($status !== '' ? '<input type="hidden" name="status" value="' . e($status) . '">' : '')
    . ($category !== '' ? '<input type="hidden" name="cat" value="' . e($category) . '">' : '')
    . '<label class="block min-w-0 flex-1 sm:max-w-xs" for="q">'
    . '<span class="text-sm text-ink/60">Поиск по названию или артикулу</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" id="q" name="q" '
    . 'value="' . e($query) . '" placeholder="Электроколонка">'
    . '</label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Найти</button>'
    . ((string) ($query ?? '') !== '' ? '<a class="inline-flex min-h-[44px] items-center px-2 py-2 text-sm text-ink/60 underline underline-offset-4 hover:text-ink" href="'
        . e($hrefWith($status, $category)) . '">Сбросить</a>' : '')
    . '</form>';

/**
 * Кнопка строки — контурная. Отдельная форма на строку держит `op` и `id`: имя кнопки
 * не доходит до сервера, если форму отправили клавишей Enter.
 */
$opButton = static function (string $op, string $id, string $label): string {
    return '<form method="post" action="' . e('/commerce/admin/products/' . rawurlencode($id) . '/delete') . '">'
        . csrfField()
        . '<input type="hidden" name="op" value="' . e($op) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">'
        . e($label) . '</button></form>';
};

$rows = '';
foreach ((array) $listing['rows'] as $row) {
    $published = (string) $row['status'] === 'PUBLISHED';
    $rows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 font-medium">' . e((string) $row['title'])
        . '<br><span class="text-xs text-ink/50">' . e((string) $row['sku']) . '</span></td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . ($published ? 'в продаже' : 'черновик') . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">'
        . e(commerceFormatMoney(commerceCents((float) ($row['price_promo'] !== null ? $row['price_promo'] : $row['price_retail'])), (string) $row['currency']))
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">' . e((string) $row['stock']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e('/commerce/admin/products/' . rawurlencode((string) $row['id'])) . '">Правка</a></td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . $opButton($published ? 'hide' : 'publish', (string) $row['id'], $published ? 'Снять' : 'Опубликовать')
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . $opButton('delete', (string) $row['id'], 'Удалить')
        . '</td></tr>';
}

$pager = '';
if ((int) $listing['pages'] > 1) {
    for ($page = 1; $page <= (int) $listing['pages']; $page++) {
        $active = $page === (int) $listing['current'];
        $pager .= '<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg px-3 py-1.5 text-sm '
            . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
            . e($hrefWith($status, $category, $page)) . '"' . ($active ? ' aria-current="true"' : '') . '>'
            . e((string) $page) . '</a>';
    }
}

$empty = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Товаров по фильтру нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Товар</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Состояние</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Цена</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Остаток</th>'
        . '<th class="px-2 py-2"></th><th class="px-2 py-2"></th><th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<header class="flex flex-wrap items-center justify-between gap-3">'
    . '<h1 class="text-2xl font-bold">Товары</h1>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" href="/commerce/admin/products/new">Новый товар</a>'
    . '</header>'
    . '<nav aria-label="Товары по состоянию" class="mt-4 flex flex-wrap gap-1">' . $tabs . '</nav>'
    . ($chips !== '' ? '<nav aria-label="Товары по рубрике" class="mt-2 flex flex-wrap gap-1">' . $chips . '</nav>' : '')
    . $search
    . $empty
    . ($pager !== '' ? '<nav aria-label="Страницы списка" class="mt-4 flex flex-wrap gap-1">' . $pager . '</nav>' : '');

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Товары', $content, $notice);
