<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $query */

// Журнал действий: кто, что и когда сделал. Подробности — в раскрывающемся блоке, чтобы таблица
// оставалась читаемой. Поиск идёт по действию, сущности, сводке и почте редактора.
$pageHref = static function (int $target) use ($query): string {
    $parts = [];
    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }
    $parts[] = 'page=' . $target;

    return '/admin/audit?' . implode('&', $parts);
};

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/audit">'
    . '<label class="block text-sm"><span class="block font-medium">Поиск по журналу</span>'
    . '<input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="search" name="q"'
    . ' value="' . e($query) . '" placeholder="Действие, запись, почта"></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Найти</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/audit">Сбросить</a>'
    . '</form>';

$rowsHtml = '';

foreach ($rows as $row) {
    $details = auditDetailsOf($row['details'] ?? null);
    $detailsHtml = '';

    if ($details !== []) {
        $encoded = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detailsHtml = '<details class="mt-1"><summary class="inline-flex min-h-[44px] cursor-pointer list-none items-center text-xs text-accent underline-offset-4 hover:underline">Подробности</summary>'
            . '<pre class="mt-1 max-w-[28rem] overflow-x-auto rounded bg-cream p-2 text-xs whitespace-pre-wrap">' . e(is_string($encoded) ? $encoded : '') . '</pre></details>';
    }

    $entity = trim((string) $row['entity']);
    $entityId = trim((string) $row['entity_id']);
    $entityLabel = $entity === '' ? '—' : $entity . ($entityId !== '' ? ' · ' . $entityId : '');
    $email = trim((string) $row['user_email']);

    $rowsHtml .= '<tr class="border-b border-ink/10 align-top">'
        . '<td class="py-3 pr-3 pl-4 whitespace-nowrap text-sm text-ink/70">' . e(formatRuDateTime(fromDb((string) $row['created_at']))) . '</td>'
        . '<td class="py-3 pr-3 text-sm">' . e($email !== '' ? $email : 'система') . '</td>'
        . '<td class="py-3 pr-3"><p class="break-all font-mono text-xs">' . e((string) $row['action']) . '</p>'
        . '<p class="break-all text-xs text-ink/50">' . e($entityLabel) . '</p></td>'
        . '<td class="py-3 pr-3">' . ((string) $row['summary'] !== ''
            ? '<p class="max-w-[26rem] break-words text-sm">' . e((string) $row['summary']) . '</p>'
            : '<p class="text-sm text-ink/40">Без описания</p>')
        . $detailsHtml . '</td>'
        . '<td class="py-3 pr-4 whitespace-nowrap font-mono text-xs text-ink/50">' . e((string) $row['ip']) . '</td>'
        . '</tr>';
}

$table = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">'
        . ($query !== ''
            ? 'Ни одна запись не подходит под запрос «' . e($query) . '».'
            : 'Журнал пока пуст: действия появятся здесь после первых правок.')
        . '</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[56rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Когда</th><th class="py-2 pr-3 font-medium">Кто</th>'
        . '<th class="py-2 pr-3 font-medium">Действие</th><th class="py-2 pr-3 font-medium">Что произошло</th>'
        . '<th class="py-2 pr-4 font-medium">IP</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

$pager = '';

if ($pages > 1) {
    $pager = '<div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">'
        . '<span class="text-ink/60">Страница ' . e((string) $page) . ' из ' . e((string) $pages)
        . ' · всего ' . e((string) $total) . '</span>'
        . '<div class="flex gap-2">';

    if ($page > 1) {
        $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($pageHref($page - 1)) . '">← Назад</a>';
    }

    if ($page < $pages) {
        $pager .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 hover:bg-ink/5" href="' . e($pageHref($page + 1)) . '">Вперёд →</a>';
    }

    $pager .= '</div></div>';
}

$header = '<p class="text-sm text-ink/70">Журнал хранит вход и выход, правки страниц и секций, действия с приложениями, меню, настройками и заявками. Записи не редактируются.</p>';

/* @safe html */ echo adminShell('Журнал действий', $header . $search . $table . $pager, null);
