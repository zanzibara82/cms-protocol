<?php

declare(strict_types=1);

/** @var array<string, mixed> $type */
/** @var string $status */
/** @var list<array<string, mixed>> $rows */
/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';
$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$target = '/admin/content/types/' . rawurlencode((string) $type['id']) . '/entries';

$statusOptions = '<option value="">Все статусы</option>';
foreach (CONTENT_STATUSES as $value) {
    $selected = $status === $value ? ' selected' : '';
    $statusOptions .= '<option value="' . e($value) . '"' . $selected . '>' . e(CONTENT_STATUS_LABELS[$value]) . '</option>';
}

$rowHtml = '';
foreach ($rows as $row) {
    $rowStatus = (string) $row['status'];
    $href = '/' . (string) $type['slug'] . '/' . (string) $row['slug'];
    $rowHtml .= '<tr class="border-b border-ink/10">'
        . '<td class="py-2 pr-2 pl-4"><label class="flex min-h-[44px] items-center justify-center">'
        . '<input class="h-5 w-5 min-h-[44px] shrink-0" type="checkbox" name="ids[]" value="' . e((string) $row['id']) . '" form="bulk-form"'
        . ' aria-label="Выбрать запись ' . e((string) $row['title']) . '"></label></td>'
        . '<td class="py-2 pr-2"><a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="/admin/content/entries/'
        . e(rawurlencode((string) $row['id'])) . '">' . e((string) $row['title']) . '</a>'
        . '<span class="mt-0.5 block text-xs text-ink/50">/' . e((string) $row['slug']) . '</span></td>'
        . '<td class="py-2 pr-2 text-sm"><span class="rounded-full bg-ink/5 px-2 py-1 text-xs">' . e(CONTENT_STATUS_LABELS[$rowStatus] ?? $rowStatus) . '</span></td>'
        . '<td class="py-2 pr-2 text-sm text-ink/70">' . e(formatRuDateTime(fromDb((string) $row['updated_at']))) . '</td>'
        . '<td class="py-2 pr-2">';

    if ($rowStatus === CONTENT_STATUS_PUBLISHED) {
        $rowHtml .= '<a class="inline-flex min-h-[44px] items-center text-sm hover:underline" href="' . e($href) . '"'
            . ' target="_blank" rel="noopener noreferrer">Открыть</a>';
    }

    $rowHtml .= '</td></tr>';
}

$table = '<section class="overflow-hidden rounded-lg border border-ink/10 bg-white">'
    . '<div class="overflow-x-auto"><table class="w-full min-w-[40rem] text-left">'
    . '<thead class="text-xs uppercase tracking-wide text-ink/50"><tr>'
    . '<th class="px-2 py-2 pl-4"><span class="sr-only">Выбор</span></th>'
    . '<th class="px-2 py-2">Запись</th><th class="px-2 py-2">Статус</th>'
    . '<th class="px-2 py-2">Обновлена</th><th class="px-2 py-2"></th>'
    . '</tr></thead><tbody>' . $rowHtml . '</tbody></table></div>';

if ($rows === []) {
    $table .= '<p class="p-4 text-sm text-ink/60">Записей с таким статусом нет.</p>';
}
$table .= '</section>';

$bulk = '<form id="bulk-form" class="flex flex-wrap items-end gap-3 rounded-lg border border-ink/10 bg-white p-4" method="post" action="' . e($target) . '">'
    . csrfField()
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">С отмеченными записями</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="bulk_op">'
    . '<option value="bulk-archive">Убрать в архив</option>'
    . '<option value="bulk-restore">Вернуть в черновик</option>'
    . '</select></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Применить</button></form>';

$filter = '<form class="flex flex-wrap items-end gap-3" method="get" action="' . e($target) . '">'
    . '<label class="block min-w-0 text-sm"><span class="block font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Показать</button></form>';

$head = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<div class="flex flex-wrap items-center justify-between gap-3">'
    . '<div><h2 class="font-semibold">' . e((string) $type['name']) . '</h2>'
    . '<p class="mt-1 text-sm text-ink/60">Записи раздела доступны по адресу /' . e((string) $type['slug']) . '.</p></div>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" href="' . e($target . '/new') . '">Новая запись</a>'
    . '</div></section>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Записи', $alert . $head . $filter . $table . $bulk, $notice);
