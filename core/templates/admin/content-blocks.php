<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $blocks */
/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */

$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';

$rows = '';
foreach ($blocks as $block) {
    $rows .= '<tr class="border-b border-ink/10">'
        . '<td class="py-2 pr-2 pl-4"><a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="/admin/content/blocks/'
        . e(rawurlencode((string) $block['id'])) . '">' . e((string) $block['title']) . '</a>'
        . '<span class="mt-0.5 block text-xs text-ink/50">/' . e((string) $block['slug']) . '</span></td>'
        . '<td class="py-2 pr-2 text-sm text-ink/70">' . e((string) (GLOBAL_BLOCK_LAYOUTS[(string) $block['layout']] ?? $block['layout'])) . '</td>'
        . '<td class="py-2 pr-2 text-sm">' . ((int) $block['active'] === 1 ? 'Включён' : 'Архив') . '</td>'
        . '</tr>';
}

$table = '<section class="overflow-hidden rounded-lg border border-ink/10 bg-white">';

if ($rows === '') {
    $table .= '<p class="p-4 text-sm text-ink/60">Глобальных блоков пока нет.</p>';
} else {
    $table .= '<div class="overflow-x-auto"><table class="w-full min-w-[34rem] text-left">'
        . '<thead class="text-xs uppercase tracking-wide text-ink/50"><tr>'
        . '<th class="px-4 py-2 pl-4">Блок</th><th class="px-2 py-2">Вёрстка</th><th class="px-2 py-2">Состояние</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
}
$table .= '</section>';

$head = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<div class="flex flex-wrap items-center justify-between gap-3">'
    . '<div><h2 class="font-semibold">Переиспользуемые фрагменты</h2>'
    . '<p class="mt-1 text-sm text-ink/60">Блок вставляется в страницу видом «Глобальный блок» по своему адресу.</p></div>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" href="/admin/content/blocks/new">Новый блок</a>'
    . '</div></section>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Глобальные блоки', $alert . $head . $table, $notice);
