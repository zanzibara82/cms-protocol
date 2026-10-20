<?php

declare(strict_types=1);

/**
 * Список переписок кабинета: последнее сообщение и непрочитанные.
 *
 * @var list<array{listing_id: string, listing_slug: string, listing_title: string, other_id: string, other_name: string, last_body: string, last_at: string, unread: int}> $threads
 * @var string $notice
 */
$rows = '';

foreach ($threads as $thread) {
    $href = '/classifieds/cabinet/messages/' . rawurlencode($thread['listing_id'])
        . '?with=' . rawurlencode($thread['other_id']);
    $unread = $thread['unread'] > 0
        ? '<span class="rounded bg-accent/15 px-2 py-0.5 text-xs font-medium">' . e((string) $thread['unread']) . '</span>'
        : '';
    $date = formatRuDateTime(fromDb($thread['last_at']));

    $rows .= '<li class="rounded-xl border border-ink/10 bg-white p-4">'
        . '<a class="flex min-h-[44px] flex-wrap items-center gap-2 font-medium underline underline-offset-4 hover:text-accent" href="' . e($href) . '">'
        . e($thread['listing_title']) . $unread . '</a>'
        . '<p class="text-sm text-ink/60">Собеседник: ' . e($thread['other_name']) . ($date !== '' ? ' · ' . e($date) : '') . '</p>'
        . '<p class="mt-1 text-sm text-ink/80">' . e(excerpt($thread['last_body'], 160)) . '</p>'
        . '</li>';
}

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Сообщения</h1>'
    . '<p class="text-ink/70">Переписка по вашим объявлениям и покупкам.</p>'
    . '<div class="flex flex-wrap gap-2">'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/classifieds/cabinet">В кабинет</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/classifieds/cabinet/notifications">Уведомления</a>'
    . '</div>'
    . '</header>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . ($rows === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Переписок пока нет. Напишите продавцу со страницы объявления.</p>'
        : '<ul class="space-y-3">' . $rows . '</ul>')
    . '</section>';
?>
<?= $content /* @safe html */ ?>
