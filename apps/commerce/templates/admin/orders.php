<?php

declare(strict_types=1);

/**
 * Список заказов с фильтром статуса и оборудованием строки.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $status
 * @var array<string, int> $counts
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int, perPage: int} $listing
 */
$CHIP_ON = 'bg-ink text-cream';
$CHIP_OFF = 'border border-ink/10 bg-cream hover:bg-ink/5';

$tabs = '';
foreach (['' => 'Все'] + COMMERCE_ORDER_STATUS_LABELS as $value => $label) {
    $active = $value === $status;
    $tabs .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
        . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e($value === '' ? '/commerce/admin/orders' : '/commerce/admin/orders?status=' . $value)
        . '"' . ($active ? ' aria-current="true"' : '') . '>'
        . e($label) . '<span class="ml-1.5 tabular-nums opacity-70">'
        . e((string) ($counts[$value] ?? '')) . '</span></a>';
}

$rowHtml = static function (array $row): string {
    $id = (string) $row['id'];
    $buttons = '';

    if ((string) $row['status'] === 'NEW') {
        $buttons .= '<form class="inline-flex" method="post" action="/commerce/admin/orders">'
            . csrfField()
            . '<input type="hidden" name="op" value="ship">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Обработан</button>'
            . '</form>'
            . '<form class="inline-flex" method="post" action="/commerce/admin/orders">'
            . csrfField()
            . '<input type="hidden" name="op" value="cancel">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Отменить</button>'
            . '</form>';
    }

    return '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">№' . e((string) $row['number']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . e((string) $row['customer_name'])
        . '<br><span class="text-xs text-ink/50">' . e((string) $row['customer_email']) . '</span></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">'
        . e(commerceFormatMoney(commerceCents((float) $row['total']), (string) $row['currency']))
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">'
        . e((string) (COMMERCE_ORDER_STATUS_LABELS[(string) $row['status']] ?? (string) $row['status']))
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . e((string) $row['created_at']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . $buttons . '</td>'
        . '</tr>';
};

$rows = '';
foreach ((array) $listing['rows'] as $row) {
    $rows .= $rowHtml((array) $row);
}

$pager = '';
if ((int) $listing['pages'] > 1) {
    for ($page = 1; $page <= (int) $listing['pages']; $page++) {
        $href = $page === 1
            ? ($status === '' ? '/commerce/admin/orders' : '/commerce/admin/orders?status=' . $status)
            : '/commerce/admin/orders?page=' . $page . ($status !== '' ? '&' . http_build_query(['status' => $status]) : '');
        $active = $page === (int) $listing['current'];
        $pager .= '<a class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg px-3 py-1.5 text-sm '
            . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="' . e($href) . '"'
            . ($active ? ' aria-current="true"' : '') . '>' . e((string) $page) . '</a>';
    }
}

$empty = $rows === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Заказов по фильтру нет.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Номер</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Покупатель</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Сумма</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Статус</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Создан</th>'
        . '<th class="px-2 py-2"></th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';

$content = $nav
    . '<h1 class="text-2xl font-bold">Заказы</h1>'
    . '<nav aria-label="Заказы по состоянию" class="mt-4 flex flex-wrap gap-1">' . $tabs . '</nav>'
    . $empty
    . ($pager !== '' ? '<nav aria-label="Страницы списка" class="mt-4 flex flex-wrap gap-1">' . $pager . '</nav>' : '');

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Заказы', $content, $notice);
