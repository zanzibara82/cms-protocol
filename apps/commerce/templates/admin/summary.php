<?php

declare(strict_types=1);

/**
 * Обзор магазина: счётчики, низкие остатки и свежие заказы.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var array<string, int> $counts
 * @var list<array<string, mixed>> $lowStock
 * @var list<array<string, mixed>> $recentOrders
 */
$productForms = ['товар', 'товара', 'товаров'];
$categoryForms = ['рубрика', 'рубрики', 'рубрик'];
$orderForms = ['заказ', 'заказа', 'заказов'];

$panel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="/commerce/admin/products">Товары</a></h2>'
    . '<dl class="mt-2">'
    . '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1">'
    . '<dt class="text-sm text-ink/60">всего</dt><dd class="font-medium tabular-nums">' . e((string) $counts['products']) . '</dd></div>'
    . '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1">'
    . '<dt class="text-sm text-ink/60">в продаже</dt><dd class="font-medium tabular-nums">' . e((string) $counts['published']) . '</dd></div>'
    . '<div class="flex items-baseline justify-between gap-2 py-1 border-b border-ink/10">'
    . '<dt class="text-sm text-ink/60">рубрик</dt><dd class="font-medium tabular-nums">'
    . e((string) $counts['categories']) . '</dd></div>'
    . '<div class="flex items-baseline justify-between gap-2 py-1">'
    . '<dt class="text-sm text-ink/60">малый остаток (≤5)</dt><dd class="font-medium tabular-nums">'
    . e((string) $counts['low_stock']) . '</dd></div>'
    . '</dl></section>';

$ordersPanel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="/commerce/admin/orders">Заказы</a></h2>'
    . '<dl class="mt-2">'
    . '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1">'
    . '<dt class="text-sm text-ink/60">новых</dt><dd class="font-medium tabular-nums">' . e((string) $counts['orders_new']) . '</dd></div>'
    . '<div class="flex items-baseline justify-between gap-2 py-1">'
    . '<dt class="text-sm text-ink/60">всего</dt><dd class="font-medium tabular-nums">' . e((string) $counts['orders_total']) . '</dd></div>'
    . '</dl></section>';

$lowRows = '';
foreach ((array) $lowStock as $row) {
    $lowRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-2">' . e((string) $row['title']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2 tabular-nums">' . e((string) $row['stock']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e('/commerce/admin/products/' . rawurlencode((string) $row['slug'] === '' ? (string) $row['slug'] : (string) $row['slug'])) . '">открыть</a></td>'
        . '</tr>';
}

$lowPanel = $lowRows === ''
    ? '<p class="text-sm text-ink/60">Товаров с малым остатком нет.</p>'
    : '<table class="w-full text-sm"><thead>'
        . '<tr><th class="px-2 py-2 text-left font-semibold">Товар</th><th class="px-2 py-2 text-left font-semibold">Остаток</th><th class="px-2 py-2"></th></tr>'
        . '</thead><tbody>' . $lowRows . '</tbody></table>';

$orderRows = '';
foreach ((array) $recentOrders as $row) {
    $orderRows .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-2 tabular-nums">№' . e((string) $row['number']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2">' . e((string) $row['customer_name']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2 tabular-nums">' . e(commerceFormatMoney(commerceCents((float) $row['total']), (string) $row['currency'])) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-2">' . e((string) (COMMERCE_ORDER_STATUS_LABELS[(string) $row['status']] ?? (string) $row['status'])) . '</td>'
        . '</tr>';
}

$recentPanel = $orderRows === ''
    ? '<p class="text-sm text-ink/60">Заказов пока нет.</p>'
    : '<table class="w-full text-sm"><thead>'
        . '<tr><th class="px-2 py-2 text-left font-semibold">Номер</th><th class="px-2 py-2 text-left font-semibold">Покупатель</th><th class="px-2 py-2 text-left font-semibold">Сумма</th><th class="px-2 py-2 text-left font-semibold">Статус</th></tr>'
        . '</thead><tbody>' . $orderRows . '</tbody></table>';

$content = $nav
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . $panel
    . $ordersPanel
    . '</div>'
    . '<h2 class="mt-6 font-semibold">Малый остаток</h2>'
    . $lowPanel
    . '<h2 class="mt-6 font-semibold">Свежие заказы</h2>'
    . $recentPanel;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Магазин', $content, $notice);
