<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{id: string, kind: string, rawKind: string, title: string, entity_id: string, parent_id: string, deleted_at: string}> $rows */

// Корзина: удалённые страницы и секции лежат здесь, пока их не восстановят или не сотрут
// безвозвратно. Восстановление возвращает прежние блоки и историю адресов.
$rowsHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $kind = (string) $row['kind'];
    $deleted = formatRuDateTime(fromDb((string) $row['deleted_at']));

    $rowsHtml .= '<tr class="border-b border-ink/10 align-top">'
        . '<td class="py-3 pr-3 pl-4 text-sm text-ink/70">' . e($kind) . '</td>'
        . '<td class="py-3 pr-3"><p class="break-words font-medium">' . e((string) $row['title']) . '</p>'
        . '<p class="break-all font-mono text-xs text-ink/50">' . e((string) $row['entity_id']) . '</p></td>'
        . '<td class="py-3 pr-3 whitespace-nowrap text-sm text-ink/70">' . e($deleted) . '</td>'
        . '<td class="py-3 pr-4"><div class="flex flex-wrap items-center gap-2">'
        . '<form class="inline" method="post" action="/admin/trash">' . csrfField()
        . '<input type="hidden" name="op" value="restore">'
        . '<input type="hidden" name="item" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Восстановить</button></form>'
        . '<form class="inline" method="post" action="/admin/trash">' . csrfField()
        . '<input type="hidden" name="op" value="purge">'
        . '<input type="hidden" name="item" value="' . e($id) . '">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Стереть навсегда</button></form>'
        . '</div></td></tr>';
}

$table = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-6 text-sm text-ink/60">Корзина пуста. Удалённые страницы и блоки попадают сюда.</p>'
    : '<div class="overflow-x-auto rounded-lg border border-ink/10 bg-white">'
        . '<table class="w-full min-w-[40rem] text-sm"><thead><tr class="border-b border-ink/10 text-left text-ink/60">'
        . '<th class="py-2 pr-3 pl-4 font-medium">Что</th><th class="py-2 pr-3 font-medium">Название</th>'
        . '<th class="py-2 pr-3 font-medium">Удалено</th>'
        . '<th class="py-2 pr-4 font-medium"><span class="sr-only">Действия</span></th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';

$header = '<p class="text-sm text-ink/70">Удаление из конструктора убирает страницу с сайта, но хранит её здесь. Восстановление возвращает прежний адрес и блоки; повторное удаление в корзине окончательное.</p>';

$emptyForm = $rows === []
    ? ''
    : '<section class="rounded-lg border border-bad/30 bg-white p-4">'
        . '<form class="flex flex-wrap items-center justify-between gap-3" method="post" action="/admin/trash">' . csrfField()
        . '<input type="hidden" name="op" value="empty">'
        . '<p class="text-sm text-ink/70">Очистка корзины стирает все записи без возможности восстановления.</p>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Очистить корзину</button>'
        . '</form></section>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Корзина', $alert . $header . $table . $emptyForm, $notice);
