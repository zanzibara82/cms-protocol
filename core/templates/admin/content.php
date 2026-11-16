<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{id: string, slug: string, name: string, description: string, archived: bool, entries: int}> $types */
/** @var list<array<string, mixed>> $blocks */

$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';

$typeRows = '';
foreach ($types as $type) {
    $archived = (bool) $type['archived'];
    $name = (string) $type['name'];
    $typeRows .= '<tr class="border-b border-ink/10">'
        . '<td class="py-2 pr-2 pl-4"><a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="/admin/content/types/'
        . e(rawurlencode((string) $type['id'])) . '">' . e($name) . '</a>'
        . ($archived ? '<span class="ml-2 text-xs text-ink/50">архив</span>' : '')
        . '<span class="mt-0.5 block text-xs text-ink/50">/' . e((string) $type['slug']) . '</span></td>'
        . '<td class="py-2 pr-2 text-sm text-ink/70">' . e((string) $type['description']) . '</td>'
        . '<td class="py-2 pr-2 text-sm">' . e((string) $type['entries']) . '</td>'
        . '<td class="py-2 pr-2"><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/content/types/'
        . e(rawurlencode((string) $type['id'])) . '/entries">Записи</a></td>'
        . '</tr>';
}

$typeTable = '<section class="overflow-hidden rounded-lg border border-ink/10 bg-white">'
    . '<div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink/10 p-4">'
    . '<div><h2 class="font-semibold">Типы записей</h2>'
    . '<p class="mt-1 text-sm text-ink/60">Свои разделы с настраиваемыми полями и публичными адресами.</p></div>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" href="/admin/content/types/new">Новый тип</a></div>';

if ($typeRows === '') {
    $typeTable .= '<p class="p-4 text-sm text-ink/60">Типов пока нет. Создайте первый — например, «Статьи» или «Сотрудники».</p>';
} else {
    $typeTable .= '<div class="overflow-x-auto"><table class="w-full min-w-[36rem] text-left">'
        . '<thead class="text-xs uppercase tracking-wide text-ink/50"><tr>'
        . '<th class="px-4 py-2 pl-4">Тип</th><th class="px-2 py-2">Описание</th>'
        . '<th class="px-2 py-2">Записей</th><th class="px-2 py-2"></th>'
        . '</tr></thead><tbody>' . $typeRows . '</tbody></table></div>';
}
$typeTable .= '</section>';

$blockTable = '';
if ($blocks !== []) {
    $blockRows = '';
    foreach ($blocks as $block) {
        $blockRows .= '<tr class="border-b border-ink/10">'
            . '<td class="py-2 pr-2 pl-4"><a class="inline-flex min-h-[44px] items-center font-medium hover:underline" href="/admin/content/blocks/'
            . e(rawurlencode((string) $block['id'])) . '">' . e((string) $block['title']) . '</a>'
            . '<span class="mt-0.5 block text-xs text-ink/50">/' . e((string) $block['slug']) . '</span></td>'
            . '<td class="py-2 pr-2 text-sm text-ink/70">' . e((string) (GLOBAL_BLOCK_LAYOUTS[(string) $block['layout']] ?? $block['layout'])) . '</td>'
            . '<td class="py-2 pr-2 text-sm">' . ((int) $block['active'] === 1 ? 'Включён' : 'Архив') . '</td>'
            . '</tr>';
    }

    $blockTable = '<section class="overflow-hidden rounded-lg border border-ink/10 bg-white">'
        . '<div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink/10 p-4">'
        . '<div><h2 class="font-semibold">Глобальные блоки</h2>'
        . '<p class="mt-1 text-sm text-ink/60">Переиспользуемые фрагменты для конструктора страниц.</p></div>'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" href="/admin/content/blocks">Все блоки</a></div>'
        . '<div class="overflow-x-auto"><table class="w-full min-w-[32rem] text-left">'
        . '<thead class="text-xs uppercase tracking-wide text-ink/50"><tr>'
        . '<th class="px-4 py-2 pl-4">Блок</th><th class="px-2 py-2">Вёрстка</th><th class="px-2 py-2">Состояние</th>'
        . '</tr></thead><tbody>' . $blockRows . '</tbody></table></div></section>';
}

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Контент', $alert . $typeTable . $blockTable, $notice);
