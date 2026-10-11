<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var array{status: string, categoryId: string, query: string, page: int} $filters
 * @var array{items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>, perPage: int, href: string} $listing
 * @var array<string, int> $counts
 * @var int $onSite
 * @var int $pinned
 * @var list<array{id: string, title: string, visible: bool}> $categories
 */
$itemForms = ['материал', 'материала', 'материалов'];
// Зона нажатия min-h-[44px] с inline-flex стоит литералом в самой разметке (страж TapTargetTest
// читает классы атрибута, а `e($active ? …)` даёт ему ветки тернарника): переменными остаются
// только цвета состояния вкладки, где зоны нажатия нет.
$CHIP_ON = 'bg-ink text-cream';
$CHIP_OFF = 'border border-ink/10 bg-cream hover:bg-ink/5';

$tabs = '';
foreach (NEWS_FILTERS as $filter) {
    $active = $filter === $filters['status'];
    $tabs .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
        . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e(adminItemsHref(array_merge($filters, ['status' => $filter, 'page' => 1]))) . '"'
        . ($active ? ' aria-current="true"' : '') . '>' . e(NEWS_FILTER_LABEL[$filter])
        . '<span class="ml-1.5 tabular-nums opacity-70">' . e((string) ($counts[$filter] ?? 0)) . '</span></a>';
}

$chips = '';
if ($categories !== []) {
    $all = '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
        . e($filters['categoryId'] === '' ? $CHIP_ON : $CHIP_OFF) . '" href="'
        . e(adminItemsHref(array_merge($filters, ['categoryId' => '', 'page' => 1]))) . '">Все рубрики</a>';

    foreach ($categories as $category) {
        $active = (string) $category['id'] === $filters['categoryId'];
        $chips .= '<a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium '
            . e($active ? $CHIP_ON : $CHIP_OFF) . '" href="'
            . e(adminItemsHref(array_merge($filters, ['categoryId' => (string) $category['id'], 'page' => 1]))) . '">'
            . e((string) $category['title']) . ((bool) $category['visible'] === false ? ' (скрыта)' : '') . '</a>';
    }

    $chips = '<nav aria-label="Материалы по рубрике" class="flex flex-wrap gap-1">' . $all . $chips . '</nav>';
}

$search = '<form class="flex flex-wrap items-end gap-2" method="get" action="' . e(NEWS_ADMIN_ITEMS_PATH) . '">'
    . ($filters['status'] !== 'all'
        ? '<input type="hidden" name="status" value="' . e($filters['status']) . '">' : '')
    . ($filters['categoryId'] !== ''
        ? '<input type="hidden" name="cat" value="' . e($filters['categoryId']) . '">' : '')
    . '<label class="block min-w-0 flex-1 sm:max-w-xs" for="q">'
    . '<span class="text-sm text-ink/60">Поиск по заголовку, лиду или адресу</span>'
    . '<input class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" id="q" name="q" '
    . 'maxlength="' . e((string) SEARCH_MAX_LENGTH) . '" value="' . e($filters['query']) . '" placeholder="осенний бал">'
    . '</label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Найти</button>';

// Сброс ведёт на чистый адрес списка: ссылка, которая сохранила бы хотя бы один фильтр, была бы
// вторым способом сказать «показать всё» — и люди бы не нашли его.
if ($filters['query'] !== '' || $filters['categoryId'] !== '' || $filters['status'] !== 'all') {
    $search .= '<a class="inline-flex min-h-[44px] items-center px-2 py-2 text-sm text-ink/60 underline underline-offset-4 hover:text-ink" href="'
        . e(NEWS_ADMIN_ITEMS_PATH) . '">Сбросить</a>';
}

$search .= '</form>';
$filtersHtml = '<nav aria-label="Материалы по состоянию" class="flex flex-wrap gap-1">' . $tabs . '</nav>'
    . $chips . $search;

// Кнопка строки — своя форма с скрытыми `op`, `id` и `back`: имя кнопки не доходит до сервера,
// если человек отправил форму клавишей Enter, а `back` держит фильтр и страницу.
$opClass = static function (string $op): string {
    // Сплошная кнопка одна — «На печать»; снятие с сайта, закрепление и открепление остаются
    // контурными. Отдельная ветка на каждый контурный случай в точности повторила бы `default`,
    // и правка цвета размечалась бы в двух местах.
    return match ($op) {
        'publish' => 'bg-ink text-cream hover:opacity-90',
        default => 'border border-ink/20 hover:bg-ink/5',
    };
};

$rows = '';
foreach ($listing['items'] as $item) {
    // Кнопки пересобираются для каждой строки: переменная обнуляется здесь, и внешний ноль
    // перед циклом был бы мёртвой записью.
    $buttons = '';
    $id = (string) $item['id'];

    foreach (newsRowActions($item) as $action) {
        $buttons .= '<form class="inline" method="post" action="' . e(NEWS_ADMIN_ITEMS_PATH) . '">' . csrfField()
            . '<input type="hidden" name="op" value="' . e((string) $action['op']) . '">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<input type="hidden" name="back" value="' . e($listing['href']) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 py-1 text-sm '
            . e($opClass((string) $action['op'])) . '" type="submit">'
            . e((string) $action['label']) . '</button></form>';
    }

    if ($buttons === '') {
        $buttons = '<span class="text-sm text-ink/50">Действий нет</span>';
    }

    // Ссылка «Править» ведёт на форму задачи 18 и несёт обратный адрес: вернувшись из сохранения,
    // человек попадает в тот же список — с той же вкладкой, рубрикой и страницей. Она добавляется
    // уже после проверки пустоты: иначе сама заполнила бы $buttons, и «Действий нет» не показался
    // бы никогда.
    $buttons .= '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-2.5 py-1 text-sm hover:bg-ink/5" href="'
        . e(queryAppend((string) $item['editHref'], 'back', $listing['href'])) . '">Править</a>';

    // Адрес материала: невидимый материал открывается только по прямой ссылке с ?draft=1, и список
    // обязан вести именно туда — иначе редактор жалуется на 404 там, где всё работает.
    $publicHref = (string) $item['href'] . ($item['open'] === true ? '' : '?draft=1');
    $badge = $item['open'] === true
        ? 'rounded-lg bg-good/10 px-2 py-0.5 text-xs font-medium text-good'
        : ($item['scheduled'] === true
            ? 'rounded-lg bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent'
            : 'rounded-lg border border-ink/20 px-2 py-0.5 text-xs font-medium text-ink/70');

    $tags = (array) $item['tags'];
    $meta = '<span>срок: ' . e((string) $item['published']) . '</span>';

    if ($item['pinned'] === true) {
        $meta .= '<span class="font-medium text-ink">закреплена</span>';
    }

    $meta .= '<span>рубрика: ' . e((string) $item['categoryTitle'])
        . ($item['categoryVisible'] === true ? '' : ' (скрыта)') . '</span>';

    if ($tags !== []) {
        $meta .= '<span>теги: ' . e(implode(', ', array_map('strval', $tags))) . '</span>';
    }

    if ((string) $item['authorEmail'] !== '') {
        $meta .= '<span>печать: ' . e((string) $item['authorEmail']) . '</span>';
    }

    $rows .= '<li class="rounded-lg border border-ink/10 bg-white p-3">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg px-2 py-1 font-medium hover:underline" href="' . e($publicHref) . '">'
        . e((string) $item['title']) . '</a>'
        . '<span class="' . e($badge) . '">' . e((string) $item['statusLabel']) . '</span></div>'
        . '<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink/60">' . $meta . '</div>'
        . '<div class="mt-2 flex flex-wrap gap-2">' . $buttons . '</div></li>';
}

$pager = '';
if ($listing['pages'] > 1) {
    $numbers = '';

    foreach ($listing['window'] as $number) {
        $numbers .= '<li>' . ($number === $listing['current']
            ? '<span class="rounded-lg border border-accent bg-accent/10 px-2.5 py-1 text-sm font-medium tabular-nums" aria-current="page">'
                . e((string) $number) . '</span>'
            : '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/10 bg-cream px-2.5 py-1 text-sm tabular-nums hover:border-ink/20" href="'
                . e(adminItemsHref(array_merge($filters, ['page' => $number]))) . '">' . e((string) $number) . '</a>')
            . '</li>';
    }

    $pager = '<nav aria-label="Страницы материалов"><ul class="flex flex-wrap gap-2">' . $numbers . '</ul></nav>';
}

$empty = '<div class="rounded-lg border border-ink/10 bg-white py-10 text-center text-ink/60">'
    . '<p class="text-sm">Под эти условия материалов нет.</p>'
    . '<p class="mt-2"><a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-ink" href="'
    . e(NEWS_ADMIN_ITEMS_PATH) . '">Сбросить фильтры</a></p></div>';

$heading = '<div class="flex flex-wrap items-end justify-between gap-3"><div class="space-y-1">'
    . '<p class="text-sm text-ink/70">' . e((string) $listing['total']) . ' ' . e(plural((int) $listing['total'], $itemForms))
    // Число рядом со словом «на сайте» читается по правилу ленты: материал скрытой рубрики
    // опубликован, но посетитель его не видит.
    . ' · на сайте ' . e((string) $onSite) . ', ждут срока ' . e((string) $counts['scheduled'])
    . ', черновиков ' . e((string) $counts['DRAFT']) . '</p></div></div>';

$alert = newsPinnedSkewAlert($pinned);

$foot = '<p class="text-sm text-ink/60">На странице ' . e((string) ADMIN_NEWS_PER_PAGE) . ' материалов. '
    . 'Текст, рубрику и теги правят в форме материала; «На печать» выходит с тем сроком, что стоит в поле, '
    . 'а снятие оставляет строку в черновиках со сроком.</p>';

$content = $nav . $heading
    . $alert
    . $filtersHtml
    . ($rows === '' ? $empty : '<ul class="space-y-2">' . $rows . '</ul>')
    . $pager
    . $foot;

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Материалы', $content, $notice);
