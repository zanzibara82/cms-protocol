<?php

declare(strict_types=1);

/**
 * Кабинет продавца: свои объявления и действия над ними.
 *
 * @var list<array<string, mixed>> $listings
 * @var string $notice
 * @var string $name
 */
$rows = '';
foreach ($listings as $listing) {
    $id = (string) $listing['id'];
    $href = '/classifieds/' . rawurlencode((string) $listing['slug']);
    $published = (string) $listing['status'] === 'PUBLISHED';
    $price = classifiedsPriceLabel(
        $listing['price'] === null ? null : (float) $listing['price'],
        (int) $listing['negotiable'] === 1
    );
    $date = formatRuDate(fromDb((string) $listing['created_at']));
    $status = $published
        ? '<span class="rounded bg-accent/15 px-2 py-0.5 text-xs font-medium">Опубликовано</span>'
        : '<span class="rounded bg-ink/10 px-2 py-0.5 text-xs font-medium">Черновик</span>';

    $form = '<form class="inline-flex flex-wrap items-center gap-2" method="post" action="' . e('/classifieds/cabinet/' . rawurlencode($id) . '/action') . '">'
        . csrfField();

    if ($published) {
        $form .= '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="hide">Снять</button>';
    } else {
        $form .= '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream hover:bg-ink/90" name="op" value="publish">Опубликовать</button>';
    }

    $form .= '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" name="op" value="delete">Удалить</button>'
        . '</form>';

    $rows .= '<li class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="min-w-0 space-y-1">'
        . '<p class="flex flex-wrap items-center gap-2 font-medium">' . $status
        . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="' . e($href) . '">' . e((string) $listing['title']) . '</a></p>'
        . '<p class="text-sm text-ink/60 tabular-nums">' . e($price) . ($date !== '' ? ' · ' . e($date) : '') . '</p>'
        . '</div>'
        . '<div class="flex flex-wrap items-end gap-2">'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
        . ' href="' . e('/classifieds/cabinet/' . rawurlencode($id)) . '">Править</a>'
        . $form
        . '</div></li>';
}

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Мои объявления</h1>'
    . '<p class="text-ink/70">Здравствуйте, ' . e($name) . '. Здесь ваши черновики и публикации.</p>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"'
    . ' href="/classifieds/cabinet/new">Новое объявление</a>'
    . '</header>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . ($rows === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">'
            . 'Объявлений пока нет. Первое можно создать прямо сейчас.</p>'
        : '<ul class="space-y-3">' . $rows . '</ul>')
    . '</section>';
?>
<?= $content /* @safe html */ ?>
