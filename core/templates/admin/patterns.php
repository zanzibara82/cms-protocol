<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{key: string, name: string, category: string, categoryName: string, description: string, sections: int}> $patterns */
/** @var array<string, string> $categories */
/** @var string $category */
/** @var list<array{id: string, title: string, slug: string, sections: int}> $pages */

// Библиотека паттернов: короткие наборы блоков для вставки в уже собранную страницу.
//
// Паттерн не заменяет содержимое: он добавляет один законченный кусок — три карточки, сетку
// отзывов, блок вопросов. После вставки человек попадает в обычный редактор страницы.

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

// Фильтр по категориям — ссылки, состояние живёт в адресе и ссылку можно сохранить.
$filterLinks = '<a class="inline-flex min-h-[44px] items-center rounded-full border px-3 text-sm '
    . ($category === '' ? 'border-ink bg-ink text-cream' : 'border-ink/20 hover:bg-ink/5') . '" href="/admin/patterns">Все</a>';

foreach ($categories as $key => $label) {
    $active = $category === $key;
    $filterLinks .= '<a class="inline-flex min-h-[44px] items-center rounded-full border px-3 text-sm '
        . ($active ? 'border-ink bg-ink text-cream' : 'border-ink/20 hover:bg-ink/5')
        . '" href="/admin/patterns?category=' . e(rawurlencode($key)) . '">' . e($label) . '</a>';
}

$cards = '';
foreach ($patterns as $pattern) {
    $cards .= '<article class="flex flex-col rounded-lg border border-ink/10 bg-white p-4">'
        . '<p class="text-xs font-medium uppercase tracking-wide text-ink/40">' . e($pattern['categoryName']) . '</p>'
        . '<h2 class="mt-1 text-base font-semibold">' . e($pattern['name']) . '</h2>'
        . '<p class="mt-1 flex-1 text-sm text-ink/70">' . e($pattern['description']) . '</p>'
        . '<p class="mt-3 text-xs text-ink/50">Блоков: ' . e((string) $pattern['sections'])
        . ' · ключ <code>' . e($pattern['key']) . '</code></p>'
        . '</article>';
}

if ($cards === '') {
    $cards = '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/70">'
        . 'В этой категории паттернов нет. Выберите другую или нажмите «Все».</p>';
}

$pageOptions = '';
foreach ($pages as $page) {
    $pageOptions .= '<option value="' . e($page['id']) . '">' . e($page['title']) . ' (/' . e($page['slug'])
        . ', блоков ' . e((string) $page['sections']) . ')</option>';
}

$patternOptions = '';
foreach ($patterns as $pattern) {
    $patternOptions .= '<option value="' . e($pattern['key']) . '">' . e($pattern['name']) . '</option>';
}

$insertBlock = '';
if ($pageOptions !== '' && $patternOptions !== '') {
    $insertBlock = '<form class="space-y-3 rounded-lg border border-ink/10 bg-white p-4" method="post" action="/admin/patterns">'
        . csrfField()
        . '<input type="hidden" name="op" value="insert">'
        . '<h2 class="text-base font-semibold">Вставить паттерн</h2>'
        . '<p class="text-sm text-ink/70">Блоки добавляются к существующим: прежнее содержимое страницы не трогается. '
        . 'После вставки страница открывается в обычном редакторе.</p>'
        . '<label class="block text-sm" for="pattern-page"><span class="block font-medium">Страница</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" id="pattern-page" name="page" required>'
        . $pageOptions . '</select></label>'
        . '<label class="block text-sm" for="pattern-key"><span class="block font-medium">Паттерн</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" id="pattern-key" name="pattern" required>'
        . $patternOptions . '</select></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 text-sm font-medium text-cream hover:opacity-90" type="submit">Вставить паттерн</button>'
        . '</form>';
}

$noteBlock = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Как это работает</h2>'
    . '<ul class="mt-2 space-y-1 text-sm text-ink/70">'
    . '<li>· Паттерн — один-три блока конструктора: заголовок, карточки, вопросы или форма.</li>'
    . '<li>· Вставка добавляет блоки в конец страницы и ничего не заменяет.</li>'
    . '<li>· Якоря паттернов не пересекаются: на одну страницу можно поставить несколько паттернов.</li>'
    . '<li>· Предел — ' . e((string) PAGES_SECTIONS_LIMIT) . ' блоков на страницу; превышение отклоняется до вставки.</li>'
    . '</ul></section>';

$filterBlock = '<nav class="flex flex-wrap gap-2" aria-label="Категории паттернов">' . $filterLinks . '</nav>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

$body = $alert
    . $filterBlock
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $cards . '</div>'
    . $insertBlock
    . $noteBlock;

/* @safe html */ echo adminShell('Библиотека паттернов', $body, $notice);
