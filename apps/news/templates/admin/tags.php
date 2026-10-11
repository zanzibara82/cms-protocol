<?php

declare(strict_types=1);

/**
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var string $nav
 * @var list<array{id: string, slug: string, label: string, items: int}> $rows
 * @var int $labelMax
 */
$itemForms = ['материал', 'материала', 'материалов'];

$input = 'mt-1 w-full rounded-lg border border-ink/20 px-3 py-2 text-sm';
$button = 'rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5';
$danger = 'rounded-lg border border-bad/30 px-3 py-1.5 text-sm text-bad hover:bg-bad/10';

$rowsHtml = '';

foreach ($rows as $row) {
    $id = (string) $row['id'];
    $items = (int) $row['items'];

    // Кнопка удаления собирается циклом по newsTagRowActions(), как в справочнике рубрик: иначе
    // вспомогательная функция остаётся живой только в тестах, а подпись кнопки заводится вторым
    // источником.
    $delete = '';
    foreach (newsTagRowActions($row) as $action) {
        $delete .= '<form class="flex flex-wrap gap-2" method="post" action="' . e(NEWS_ADMIN_TAGS_PATH) . '">'
            . csrfField() . '<input type="hidden" name="op" value="' . e((string) $action['op']) . '">'
            . '<input type="hidden" name="id" value="' . e($id) . '">'
            . '<button class="min-h-[44px] ' . $danger . '" type="submit">' . e((string) $action['label']) . '</button></form>';
    }

    $rowsHtml .= '<li class="space-y-3 rounded-lg border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-baseline justify-between gap-2">'
        . '<h3 class="font-semibold">' . e((string) $row['label']) . '</h3>'
        . '<p class="text-sm text-ink/60">адрес: <code class="rounded bg-ink/5 px-1.5 py-0.5">'
        . e((string) $row['slug']) . '</code></p></div>'
        . '<p class="text-sm">' . e(sprintf('%1$d %2$s с этим тегом.', $items, plural($items, $itemForms))) . '</p>'
        . '<p><a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-accent" href="'
        . e(newsHref(['tag' => (string) $row['slug']])) . '">Открыть на сайте</a></p>'
        . '<form class="flex flex-wrap items-end gap-2" method="post" action="' . e(NEWS_ADMIN_TAGS_PATH) . '">'
        . csrfField() . '<input type="hidden" name="op" value="rename">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<label class="grow text-sm">Надпись<input class="min-h-[44px] ' . $input . '" type="text" name="label" value="'
        . e((string) $row['label']) . '" required maxlength="' . e((string) $labelMax) . '"></label>'
        . '<button class="min-h-[44px] ' . $button . '" type="submit">' . e(NEWS_TAG_OP_LABEL['rename']) . '</button></form>'
        . $delete
        . '</li>';
}

$empty = $rows === []
    ? '<p class="rounded-lg border border-ink/10 bg-white p-4 text-sm text-ink/60">'
        . 'Тегов пока нет: их создаёт форма материала, когда редактор вписывает их в поле «Теги».</p>'
    : '';

$content = $nav
    . '<p class="text-sm text-ink/70">Теги вручную не заводятся — их рождает форма материала. '
    . 'Здесь их можно переименовать (адрес тега останется прежним) и удалить пустой. Тег, '
    . 'привязанный хотя бы к одному материалу, справочник не отдаст: с ленты он не уйдёт.</p>'
    . $empty
    . '<ul class="space-y-3">' . $rowsHtml . '</ul>';

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Теги', $content, $notice);
