<?php

declare(strict_types=1);

/**
 * Уведомления продавца: сообщения, проверка объявлений и ответы на жалобы.
 *
 * @var list<array<string, mixed>> $notifications
 * @var int $unread
 * @var string $notice
 */
$rows = '';

foreach ($notifications as $notification) {
    $kind = CLASSIFIEDS_NOTIFICATION_KINDS[(string) $notification['kind']] ?? 'Уведомление';
    $unreadMark = $notification['read_at'] === null
        ? '<span class="rounded bg-accent/15 px-2 py-0.5 text-xs font-medium">новое</span>'
        : '';
    $date = formatRuDateTime(fromDb((string) $notification['created_at']));
    $slug = (string) ($notification['listing_slug'] ?? '');
    $link = $slug !== ''
        ? '<a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-accent" href="'
            . e('/classifieds/' . rawurlencode($slug)) . '">Открыть объявление</a>'
        : '';

    $rows .= '<li class="rounded-xl border border-ink/10 bg-white p-4">'
        . '<p class="flex flex-wrap items-center gap-2 text-sm text-ink/60">' . e($kind) . $unreadMark
        . ($date !== '' ? ' · ' . e($date) : '') . '</p>'
        . '<p class="mt-1 text-ink/80">' . e((string) $notification['body']) . '</p>'
        . $link
        . '</li>';
}

$readForm = $unread > 0
    ? '<form method="post" action="/classifieds/cabinet/notifications">'
        . csrfField()
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" type="submit">Отметить все прочитанными</button>'
        . '</form>'
    : '';

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Уведомления</h1>'
    . '<p class="text-ink/70">Здесь решения проверки, новые сообщения и ответы на жалобы.</p>'
    . '<div class="flex flex-wrap gap-2">'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/classifieds/cabinet">В кабинет</a>'
    . $readForm
    . '</div>'
    . '</header>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . ($rows === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Уведомлений пока нет.</p>'
        : '<ul class="space-y-3">' . $rows . '</ul>')
    . '</section>';
?>
<?= $content /* @safe html */ ?>
