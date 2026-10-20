<?php

declare(strict_types=1);

/**
 * Жалобы на объявления: новые сверху, закрытые остаются историей.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $rows
 * @var array<string, string> $reasons
 */
$items = '';

foreach ($rows as $row) {
    $reason = $reasons[(string) $row['reason']] ?? 'Другое';
    $new = (string) $row['status'] === 'NEW';
    $created = formatRuDateTime(fromDb((string) $row['created_at']));
    $resolved = formatRuDateTime(fromDb((string) ($row['resolved_at'] ?? '')));
    $comment = (string) ($row['comment'] ?? '');

    $resolve = $new
        ? '<form class="inline-flex" method="post" action="/classifieds/admin/reports">'
            . csrfField()
            . '<input type="hidden" name="id" value="' . e((string) $row['id']) . '">'
            . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" name="op" value="resolve">Закрыть жалобу</button>'
            . '</form>'
        : '<p class="text-sm text-ink/60">Закрыта' . ($resolved !== '' ? ' ' . e($resolved) : '')
            . ((string) ($row['resolver_name'] ?? '') !== '' ? ' · ' . e((string) $row['resolver_name']) : '') . '</p>';

    $items .= '<li class="space-y-2 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="flex flex-wrap items-start justify-between gap-3">'
        . '<div class="min-w-0 space-y-1">'
        . '<p class="font-medium"><a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e('/classifieds/' . rawurlencode((string) $row['listing_slug'])) . '">'
        . e((string) $row['listing_title']) . '</a></p>'
        . '<p class="text-sm text-ink/60">' . e($reason) . ($created !== '' ? ' · ' . e($created) : '')
        . ($new ? '' : ' · <span class="rounded bg-ink/10 px-2 py-0.5 text-xs">закрыта</span>') . '</p>'
        . '<p class="text-sm text-ink/60">Заявитель: ' . e((string) $row['reporter_name']) . ' · ' . e((string) $row['reporter_email']) . '</p>'
        . ($comment !== '' ? '<p class="text-sm text-ink/80">' . e($comment) . '</p>' : '')
        . '</div>'
        . $resolve
        . '</div></li>';
}

$content = $nav
    . '<h1 class="text-2xl font-bold">Жалобы</h1>'
    . ($items === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Жалоб пока нет.</p>'
        : '<ul class="space-y-3">' . $items . '</ul>');

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Жалобы', $content, $notice);
