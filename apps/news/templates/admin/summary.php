<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array<string, int> $counts
 * @var int $onSite
 * @var int $pinned
 * @var int $waiting
 * @var int $categories
 * @var int $tags
 */
$itemForms = ['материал', 'материала', 'материалов'];

$lines = '';
foreach ([
    ['всего материалов', $counts['all']],
    ['черновиков', $counts['DRAFT']],
    ['ждут срока', $counts['scheduled']],
    // «на сайте» — число читателя, а не вкладки: материал скрытой рубрики опубликован,
    // но в ленте его нет, и счётчик обязан показать ленту.
    ['на сайте', $onSite],
    ['закреплено из ' . $counts['all'] . ' ' . plural($counts['all'], $itemForms), $pinned],
] as [$label, $value]) {
    $lines .= '<div class="flex items-baseline justify-between gap-2 border-b border-ink/10 py-1 last:border-0">'
        . '<dt class="text-sm text-ink/60">' . e($label) . '</dt>'
        . '<dd class="font-medium tabular-nums">' . e((string) $value) . '</dd></div>';
}

$panel = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
    . e(NEWS_ADMIN_ITEMS_PATH) . '">Материалы</a></h2>'
    . '<dl class="mt-2">' . $lines . '</dl></section>';

// Панели справочников. Числа посчитаны контроллером: экран их только печатает.
$catalogPanels = '';
foreach ([
    ['Рубрики', NEWS_ADMIN_CATEGORIES_PATH, $categories, ['рубрика', 'рубрики', 'рубрик']],
    ['Теги', NEWS_ADMIN_TAGS_PATH, $tags, ['тег', 'тега', 'тегов']],
] as [$heading, $href, $count, $forms]) {
    $catalogPanels .= '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e($href) . '">' . e($heading) . '</a></h2>'
        . '<p class="mt-2 text-sm text-ink/60">' . e((string) $count) . ' ' . e(plural($count, $forms)) . '</p>'
        . '</section>';
}

$waitingText = $waiting === 0
    ? 'Материалов, которые ждут своего срока, нет.'
    : sprintf('По сроку выйдут сами: %1$d %2$s.', $waiting, plural($waiting, $itemForms));

$alert = newsPinnedSkewAlert($pinned);

$foot = '<p class="text-sm text-ink/60">Выход по сроку работает без задания: строка уже опубликована, '
    . 'а лента, блоки и карта сайтов показывают её, когда наступит срок. Проверить её можно по ссылке '
    . '«на сайте» в списке.</p>';

$content = $nav . '<p class="text-sm text-ink/70">' . e($waitingText) . '</p>'
    . $alert
    . '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">' . $panel . $catalogPanels . '</div>'
    . '<p class="flex flex-wrap items-center gap-3"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm text-cream hover:opacity-90" '
    . 'href="' . e(NEWS_ITEM_NEW_PATH) . '">Новый материал</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm text-cream hover:opacity-90" '
    . 'href="' . e(NEWS_ADMIN_ITEMS_PATH) . '">Открыть список</a>'
    . '<a class="inline-flex min-h-[44px] items-center text-sm text-ink/70 underline underline-offset-4 hover:text-ink" href="/news">Посмотреть ленту</a></p>'
    . $foot;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Новости', $content, $notice);
