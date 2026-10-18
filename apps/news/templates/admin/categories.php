<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var list<array{id: string, slug: string, title: string, description: ?string, order: int, visible: bool, items: array{total: int, visible: int}}> $rows
 * @var int $titleMax
 * @var int $descriptionMax
 */
$itemForms = ['материал', 'материала', 'материалов'];

$input = 'mt-1 w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$primary = 'rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:opacity-90';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

$create = '<form class="space-y-3 rounded-lg border border-ink/10 bg-white p-4" method="post" action="'
    . e(NEWS_ADMIN_CATEGORIES_PATH) . '">' . csrfField()
    . '<input type="hidden" name="op" value="create">'
    . '<h2 class="font-semibold">Новая рубрика</h2>'
    . '<p class="text-sm text-ink/60">Адрес складывается из названия один раз и больше не меняется — '
    . 'ссылки, карточки и карта сайтов остаются целыми при любом переименовании.</p>'
    . '<label class="block text-sm">Название'
    . '<input class="min-h-[44px] ' . $input . '" type="text" name="title" required maxlength="' . e((string) $titleMax) . '">'
    . '</label>'
    . '<label class="block text-sm">Описание страницы рубрики (необязательно)'
    . '<textarea class="min-h-[44px] ' . $input . '" name="description" rows="2" maxlength="' . e((string) $descriptionMax) . '"></textarea>'
    . '</label>'
    . '<button class="min-h-[44px] ' . $primary . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['create']) . '</button>'
    . '</form>';

$rowsHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $visible = $row['visible'] === true;
    $total = (int) $row['items']['total'];
    $opened = (int) $row['items']['visible'];

    $state = $visible
        ? '<span class="text-sm text-good">видна</span>'
        : '<span class="text-sm text-bad">скрыта — её страница не открывается</span>';

    $counts = sprintf('%1$d %2$s, из них на сайте %3$d.', $total, plural($total, $itemForms), $opened);

    $buttons = '';
    foreach (newsCategoryRowActions($row) as $action) {
        $class = $action['op'] === 'delete' ? $danger : $button;
        $buttons .= '<form class="contents" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
            . csrfField()
            . '<input type="hidden" name="op" value="' . e($action['op']) . '">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="min-h-[44px] ' . $class . '" type="submit">' . e($action['label']) . '</button>'
            . '</form>';
    }

    $rowsHtml .= '<li class="space-y-3 rounded-lg border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e((string) $row['title']) . '</h3>'
        . '<p class="text-sm text-ink/60">адрес: <code class="rounded bg-ink/5 px-1.5 py-0.5">'
        . e((string) $row['slug']) . '</code></p></div>'
        . '<p class="text-sm">' . $state . ' · ' . e($counts) . '</p>'
        . '<p class="flex flex-wrap items-center gap-2">'
        // Порт подхода чипов: скрытая рубрика печатается без ссылки — как карточки и путь
        // материала на сайте, ведь её страница отвечает как несуществующая.
        . ($visible
            ? '<a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-accent" href="'
                . e(newsHref(['category' => (string) $row['slug']])) . '">Открыть на сайте</a>'
            : '<span class="text-sm text-ink/60">Страница закрыта</span>')
        . '</p>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="rename">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<label class="grow text-sm">Название<input class="min-h-[44px] ' . $input
        . '" type="text" name="title" value="' . e((string) $row['title']) . '" required maxlength="'
        . e((string) $titleMax) . '"></label>'
        . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['rename']) . '</button></form>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_CATEGORIES_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="describe">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        // Название едет скрытым полем: updateCategoryDescription() проверяет пару «название +
        // описание» целиком, и без него сохранение описания стало бы отказом «название слишком
        // короткое». Правит название форма переименования выше.
        . '<input type="hidden" name="title" value="' . e((string) $row['title']) . '">'
        . '<label class="grow text-sm">Описание страницы<textarea class="min-h-[44px] ' . $input . '" name="description" rows="2" maxlength="'
        . e((string) $descriptionMax) . '">' . e((string) ($row['description'] ?? '')) . '</textarea></label>'
        . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_CATEGORY_OP_LABEL['describe']) . '</button></form>'
        . '<p class="flex flex-wrap gap-2">' . $buttons . '</p>'
        . '</li>';
}

$empty = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/60">'
        . 'Рубрик нет. Три стартовые создаются вместе с разделом — если их удалили, добавьте нужные выше.</p>'
    : '';

$content = $nav
    . '<p class="text-sm text-ink/70">Порядок задают кнопки «Вверх» и «Вниз»: он видит и читатель, '
    . 'и плитки рубрик в ленте. Скрытая рубрика не открывается, а её материалы уходят из общей ленты — '
    . 'сами материалы при этом остаются у сайта.</p>'
    . $create . $empty
    . '<ul class="space-y-3">' . $rowsHtml . '</ul>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Рубрики', $content, $notice);
