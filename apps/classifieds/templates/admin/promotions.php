<?php

declare(strict_types=1);

/**
 * Реестр продвижений: кто заказал услугу, по какому объявлению и в каком состоянии
 * заказ. Экран ничего не меняет — оплату подтверждает админ магазина.
 *
 * @var string $nav
 * @var string $notice
 * @var string $alertTitle
 * @var string $alertReason
 * @var list<array<string, mixed>> $rows
 * @var array<string, string> $kinds
 * @var array<string, string> $statuses
 * @var array{NEW: int, ACTIVE: int, CANCELLED: int} $counts
 */
$items = '';

foreach ($rows as $row) {
    $kind = (string) $row['kind'];
    $status = (string) $row['status'];
    $created = formatRuDateTime(fromDb((string) $row['created_at']));
    $applied = formatRuDateTime(fromDb((string) ($row['applied_at'] ?? '')));
    $amount = classifiedsPromotionAmountLabel(
        $row['amount'] === null ? null : (float) $row['amount'],
        (string) $row['currency']
    );

    $items .= '<li class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="min-w-0 space-y-1">'
        . '<p class="font-medium">' . e($kinds[$kind] ?? 'Услуга')
        . ' · <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e('/classifieds/' . rawurlencode((string) $row['listing_slug'])) . '">'
        . e((string) $row['listing_title']) . '</a></p>'
        . '<p class="text-sm text-ink/60">' . e($statuses[$status] ?? $status)
        . ($amount !== '' ? ' · ' . e($amount) : '')
        . ($created !== '' ? ' · ' . e($created) : '')
        . ($applied !== '' ? ' · применено ' . e($applied) : '') . '</p>'
        . '<p class="text-sm text-ink/60">Продавец: ' . e((string) $row['seller_email'])
        . ' · заказ №' . e((string) $row['order_number']) . '</p>'
        . '</div></li>';
}

$content = $nav
    . '<h1 class="text-2xl font-bold">Продвижения</h1>'
    . '<p class="text-sm text-ink/60">Ждут оплаты: ' . e((string) $counts['NEW'])
    . ' · применено: ' . e((string) $counts['ACTIVE'])
    . ' · отменено: ' . e((string) $counts['CANCELLED']) . '</p>'
    . ($items === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">Продвижений пока нет.</p>'
        : '<ul class="space-y-3">' . $items . '</ul>');

$notice = $notice === '' ? null : $notice;
/* @safe html */ echo partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) . adminShell('Продвижения', $content, $notice);
