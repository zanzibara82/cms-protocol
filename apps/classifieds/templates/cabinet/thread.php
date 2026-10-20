<?php

declare(strict_types=1);

/**
 * Переписка по одному объявлению.
 *
 * @var array{listing: array<string, mixed>, other: array{id: string, name: string}, messages: list<array<string, mixed>>} $thread
 * @var string $notice
 * @var string $action
 */
$listing = $thread['listing'];
$other = $thread['other'];
$listingHref = '/classifieds/' . rawurlencode((string) $listing['slug']);
$rows = '';

foreach ($thread['messages'] as $message) {
    $incoming = (string) $message['sender_id'] === $other['id'];
    $author = $incoming ? $other['name'] : 'Вы';
    $date = formatRuDateTime(fromDb((string) $message['created_at']));
    $bubbleClass = $incoming
        ? 'rounded-xl border border-ink/10 bg-white p-4'
        : 'rounded-xl border border-ink/10 bg-accent/10 p-4';

    $rows .= '<li class="' . $bubbleClass . '">'
        . '<p class="text-sm text-ink/60">' . e($author) . ($date !== '' ? ' · ' . e($date) : '') . '</p>'
        . '<p class="mt-1 whitespace-pre-line text-ink/80">' . e((string) $message['body']) . '</p>'
        . '</li>';
}

$content = '<section class="space-y-6">'
    . '<nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">'
    . '<a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds/cabinet">Кабинет</a>'
    . '<span aria-hidden="true">/</span>'
    . '<a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds/cabinet/messages">Сообщения</a>'
    . '</nav>'
    . '<header class="space-y-1">'
    . '<h1 class="text-3xl font-bold">' . e((string) $listing['title']) . '</h1>'
    . '<p class="text-ink/70">Собеседник: ' . e($other['name']) . '</p>'
    . '<a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-accent" href="' . e($listingHref) . '">Открыть объявление</a>'
    . '</header>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . ($rows === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Сообщений пока нет.</p>'
        : '<ul class="space-y-3">' . $rows . '</ul>')
    . '<form class="space-y-2 rounded-xl border border-ink/10 bg-white p-4" method="post" action="' . e($action) . '">'
    . csrfField()
    . '<input type="hidden" name="with" value="' . e($other['id']) . '">'
    . '<label class="block" for="reply-body">'
    . '<span class="mb-1 block text-sm text-ink/70">Ответ</span>'
    . '<textarea class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="reply-body" name="body"'
    . ' maxlength="' . e((string) CLASSIFIEDS_MESSAGE_MAX) . '" required></textarea>'
    . '</label>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Отправить</button>'
    . '</form>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
