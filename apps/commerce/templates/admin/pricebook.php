<?php

declare(strict_types=1);

/**
 * Экран прайс-листа магазина (M5): экспорт CSV скачиванием и импорт файлом с отчётом.
 * Отчёт импорта рендерится на месте — список пропущенных строк читается без гонки
 * за уведомлением.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array{id: string, title: string, note: ?string, created_at: string, updated_at: string}> $suppliers
 * @var array{lines: int, applied: int, skipped: int, quiet: int, skips: list<array{sku: string, reason: string}>}|null $report
 * @var string $importError
 * @var string $supplier
 * @var list<array{sku: string, product_title: string, variant_title: string, price_retail: string, price_promo: string, stock: int}> $rows
 */

$supplierOptions = '<option value="">— все варианты магазина —</option>';
foreach ($suppliers as $supplierRow) {
    $selected = $supplier === (string) $supplierRow['id'] ? ' selected' : '';
    $supplierOptions .= '<option value="' . e((string) $supplierRow['id']) . '"' . $selected . '>' . e((string) $supplierRow['title']) . '</option>';
}

$importForm = '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post"'
    . ' action="/commerce/admin/pricebook" enctype="multipart/form-data">'
    . csrfField()
    . '<div class="grid gap-4 sm:grid-cols-2">'
    . '<label class="block" for="pricebook_supplier">'
    . '<span class="text-sm text-ink/60">Поставщик файла (необязательно)</span>'
    . '<select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 px-3 py-2"'
    . ' id="pricebook_supplier" name="supplier">' . $supplierOptions . '</select>'
    . '<span class="mt-1 block text-sm text-ink/50">Выбранный поставщик правит только свои артикулы.</span>'
    . '</label>'
    . '<label class="block" for="pricebook_file">'
    . '<span class="text-sm text-ink/60">Файл CSV (до 5 МБ)</span>'
    . '<input class="mt-1 block w-full min-h-[44px] rounded-lg border border-ink/20 file:mr-3 file:rounded-lg file:border-0 file:px-3 file:py-2 file:text-sm" '
    . ' id="pricebook_file" name="csvfile" type="file" accept=".csv,text/csv">'
    . '<span class="mt-1 block text-sm text-ink/50">Строки: sku;товар;вариант;цена;промо;остаток — или короче: sku;цена;промо;остаток. Пустая ячейка ничего не меняет, 0 в промо снимает его.</span>'
    . '</label>'
    . '</div>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Применить прайс-лист</button>'
    . '</form>';

$reportBlock = '';
if ($report !== null) {
    $skips = '';

    foreach ($report['skips'] as $skip) {
        $skips .= '<li>' . e($skip['reason'])
            . ($skip['sku'] !== '' ? ' (артикул ' . e($skip['sku']) . ')' : '')
            . '</li>';
    }

    $hidden = $report['skipped'] - count($report['skips']);
    if ($hidden > 0) {
        $skips .= '<li>и ещё ' . $hidden . ' строк с причинами — спишите файл частями.</li>';
    }

    if ($report['quiet'] > 0) {
        $skips .= '<li class="text-ink/50">Пустых строк файла пропущено: ' . $report['quiet'] . '.</li>';
    }

    $reportBlock = '<section class="rounded-xl border border-ink/10 bg-white p-6" aria-label="Отчёт импорта">'
        . '<h2 class="text-lg font-bold">Отчёт импорта</h2>'
        . '<p class="mt-2 text-sm">Применено строк: <strong>' . (int) $report['applied'] . '</strong>'
        . ' из ' . (int) $report['lines'] . '.'
        . '</p>'
        . ($report['skipped'] > 0
            ? '<p class="mt-1 text-sm text-ink/70">Пропущено: ' . (int) $report['skipped'] . '. Причины:</p>'
                . '<ul class="mt-2 list-disc pl-5 text-sm text-red-700">' . $skips . '</ul>'
            : '')
        . '</section>';
}

$errorBlock = (string) $importError !== ''
    ? '<p class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700">' . e($importError) . '</p>'
    : '';

$rowsHtml = '';
foreach ($rows as $row) {
    $promo = (string) $row['price_promo'] !== '';

    $rowsHtml .= '<tr>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . e($row['sku']) . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3">' . e($row['product_title'])
        . '<span class="ml-1 text-sm text-ink/50">· ' . e($row['variant_title']) . '</span></td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">'
        . ($promo
            ? '<s class="mr-2 text-sm font-normal text-ink/50">' . e($row['price_retail']) . '</s>' . e($row['price_promo'])
            : e($row['price_retail']))
        . '</td>'
        . '<td class="border-b border-ink/10 px-2 py-3 tabular-nums">' . e((string) $row['stock']) . '</td>'
        . '</tr>';
}

$table = $rowsHtml === ''
    ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Вариантов пока нет — прайс-лист пуст.</p>'
    : '<table class="w-full text-sm">'
        . '<thead><tr>'
        . '<th class="px-2 py-2 text-left font-semibold">Артикул</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Товар</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Цена</th>'
        . '<th class="px-2 py-2 text-left font-semibold">Остаток</th>'
        . '</tr></thead>'
        . '<tbody>' . $rowsHtml . '</tbody></table>';

$content = $nav
    . '<header class="flex flex-wrap items-center justify-between gap-3">'
    . '<h1 class="text-2xl font-bold">Прайс-лист</h1>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' href="/commerce/admin/pricebook/export">Скачать CSV</a>'
    . '</header>'
    . '<p class="text-sm text-ink/70">Обмен ценами и остатками файлом с точкой с запятой: экспорт открывается в Excel, импорт правит известные артикулы.</p>'
    . $importForm
    . $errorBlock
    . $reportBlock
    . $table;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Прайс-лист', $content, $notice);
