<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $query */

// Журнал согласий: доказательство того, что человек принял обработку персональных данных.
// Записи не удаляются вместе с заявками, а выгрузка CSV отдаётся с запретом индексирования.
$pageHref = static function (int $target) use ($query): string {
    $parts = [];
    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }
    $parts[] = 'page=' . $target;

    return '/admin/consents?' . implode('&', $parts);
};

$exportHref = $query !== ''
    ? '/admin/consents/export?q=' . rawurlencode($query)
    : '/admin/consents/export';

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="/admin/consents">'
    . '<label class="block text-sm"><span class="font-medium">Поиск по согласиям</span>'
    . '<input class="mt-1.5 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" type="search" name="q"'
    . ' value="' . e($query) . '" placeholder="Контакт, источник, вид"></label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Найти</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/consents">Сбросить</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" href="' . e($exportHref) . '">Скачать CSV</a>'
    . '</form>';

$rowsHtml = '';

foreach ($rows as $row) {
    $subject = (string) $row['subject'];
    $source = trim((string) $row['source']);
    $agent = trim((string) $row['user_agent']);
    $rowsHtml .= '<tr class="border-b border-ink/10 align-top">'
        . '<td class="py-3 pr-3 pl-4 whitespace-nowrap text-sm text-ink/70">' . e(formatRuDateTime(fromDb((string) $row['created_at']))) . '</td>'
        . '<td class="py-3 pr-3"><p class="break-all text-sm font-medium">' . e($subject) . '</p>'
        . ($source !== '' ? '<p class="break-all font-mono text-xs text-ink/50">' . e($source) . '</p>' : '')
        . '</td>'
        . '<td class="py-3 pr-3 text-sm">' . e((string) $row['kind']) . '</td>'
        . '<td class="py-3 pr-3 whitespace-nowrap font-mono text-xs text-ink/60">' . e((string) $row['text_version']) . '</td>'
        . '<td class="py-3 pr-3 font-mono text-xs text-ink/50">' . e((string) $row['ip']) . '</td>'
        . '<td class="py-3 pr-4"><p class="max-w-[22rem] break-words text-xs text-ink/50">' . e($agent !== '' ? $agent : '—') . '</p></td>'
        . '</tr>';
}

$table = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">'
        . ($query !== ''
            ? 'Ни одно согласие не подходит под запрос «' . e($query) . '».'
            : 'Согласий пока нет: запись появится после первой заявки с публичной формы.')
        . '</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[56rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Когда</th><th class="py-2 pr-3 font-medium">Контакт</th>'
        . '<th class="py-2 pr-3 font-medium">Вид</th><th class="py-2 pr-3 font-medium">Версия текста</th>'
        . '<th class="py-2 pr-3 font-medium">IP</th><th class="py-2 pr-4 font-medium">Браузер</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

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

$header = '<p class="text-sm text-ink/70">Каждая запись фиксирует контакт, источник формы, версию текста согласия, адрес и браузер. Строки живут отдельно от заявок: удаление заявки не стирает доказательство согласия.</p>';

/* @safe html */ echo adminShell('Согласия на обработку данных', $header . $search . $table . $pager, null);
