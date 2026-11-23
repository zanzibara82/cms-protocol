<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{key: string, name: string, category: string, categoryName: string, description: string, layout: string, sections: int}> $templates */
/** @var array<string, string> $categories */
/** @var string $category */
/** @var list<array{id: string, title: string, slug: string, sections: int}> $pages */

// Библиотека готовых шаблонов: каталог и форма применения.
//
// Шаблон ставится на выбранную страницу: блоки берутся те же, что у конструктора, поэтому после
// применения человек попадает в обычный редактор и правит текст там же, где всегда.
//
// Замена содержимого — отдельный флажок. По умолчанию шаблон **добавляет** блоки: собранную
// страницу нельзя терять от одного нажатия. Флажок подписан тем, что произойдёт, и предупреждает,
// что прежние блоки уйдут в корзину — оттуда их можно вернуть.

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';

// Фильтр по категориям: ссылки, а не форма — состояние живёт в адресе, и ссылку можно сохранить.
$filterLinks = '<a class="inline-flex min-h-[44px] items-center rounded-full border px-3 text-sm '
    . ($category === '' ? 'border-ink bg-ink text-cream' : 'border-ink/20 hover:bg-ink/5') . '" href="/admin/templates">Все</a>';

foreach ($categories as $key => $label) {
    $active = $category === $key;
    $filterLinks .= '<a class="inline-flex min-h-[44px] items-center rounded-full border px-3 text-sm '
        . ($active ? 'border-ink bg-ink text-cream' : 'border-ink/20 hover:bg-ink/5')
        . '" href="/admin/templates?category=' . e(rawurlencode($key)) . '">' . e($label) . '</a>';
}

$cards = '';
foreach ($templates as $template) {
    $cards .= '<article class="flex flex-col rounded-lg border border-ink/10 bg-white p-4">'
        . '<p class="text-xs font-medium uppercase tracking-wide text-ink/40">' . e($template['categoryName']) . '</p>'
        . '<h2 class="mt-1 text-base font-semibold">' . e($template['name']) . '</h2>'
        . '<p class="mt-1 flex-1 text-sm text-ink/70">' . e($template['description']) . '</p>'
        . '<p class="mt-3 text-xs text-ink/50">Блоков: ' . e((string) $template['sections'])
        . ' · макет «' . e($template['layout']) . '» · ключ <code>' . e($template['key']) . '</code></p>'
        . '</article>';
}

if ($cards === '') {
    $cards = '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/70">'
        . 'В этой категории шаблонов нет. Выберите другую или нажмите «Все».</p>';
}

$pageOptions = '';
foreach ($pages as $page) {
    $pageOptions .= '<option value="' . e($page['id']) . '">' . e($page['title']) . ' (/' . e($page['slug'])
        . ', блоков ' . e((string) $page['sections']) . ')</option>';
}

$templateOptions = '';
foreach ($templates as $template) {
    $templateOptions .= '<option value="' . e($template['key']) . '">' . e($template['name']) . '</option>';
}

$applyBlock = '';
if ($pageOptions !== '' && $templateOptions !== '') {
    $applyBlock = '<form class="space-y-3 rounded-lg border border-ink/10 bg-white p-4" method="post" action="/admin/templates">'
        . csrfField()
        . '<input type="hidden" name="op" value="apply">'
        . '<h2 class="text-base font-semibold">Применить шаблон</h2>'
        . '<p class="text-sm text-ink/70">Шаблон ставится на выбранную страницу. Блоки добавляются к существующим, '
        . 'а после применения страница открывается в обычном редакторе.</p>'
        . '<label class="block text-sm" for="template-page"><span class="block font-medium">Страница</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" id="template-page" name="page" required>'
        . $pageOptions . '</select></label>'
        . '<label class="block text-sm" for="template-key"><span class="block font-medium">Шаблон</span>'
        . '<select class="min-h-[44px] ' . e($input) . '" id="template-key" name="template" required>'
        . $templateOptions . '</select></label>'
        . '<label class="flex items-center gap-2 text-sm" for="template-replace">'
        . '<input class="min-h-[44px] h-5 w-5 shrink-0" type="checkbox" id="template-replace" name="replace" value="1">'
        . '<span>Заменить содержимое страницы. Прежние блоки уйдут в корзину — оттуда их можно вернуть.</span></label>'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 text-sm font-medium text-cream hover:opacity-90" type="submit">Применить шаблон</button>'
        . '</form>';
}

$noteBlock = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Как это работает</h2>'
    . '<ul class="mt-2 space-y-1 text-sm text-ink/70">'
    . '<li>· Шаблон — набор блоков конструктора: тот же вид, что при ручной сборке, поэтому текст правится в редакторе страницы.</li>'
    . '<li>· Блоки добавляются к существующим. Замена — отдельный флажок и только с подтверждением.</li>'
    . '<li>· Заменённые блоки попадают в корзину: их можно восстановить.</li>'
    . '<li>· Предел — ' . e((string) PAGES_SECTIONS_LIMIT) . ' блоков на страницу; превышение отклоняется до вставки.</li>'
    . '</ul></section>';

$filterBlock = '<nav class="flex flex-wrap gap-2" aria-label="Категории шаблонов">' . $filterLinks . '</nav>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

$body = $alert
    . $filterBlock
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $cards . '</div>'
    . $applyBlock
    . $noteBlock;

/* @safe html */ echo adminShell('Библиотека шаблонов', $body, $notice);
