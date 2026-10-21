<?php

declare(strict_types=1);

/**
 * Покупки услуг продавца: что заказано, по какой цене и в каком состоянии заказ.
 * Услуга включается после оплаты заказа магазина, поэтому номер заказа — главное,
 * что продавцу нужно для оплаты.
 *
 * @var list<array<string, mixed>> $promotions
 * @var string $notice
 */
$items = '';

foreach ($promotions as $row) {
    $kind = (string) $row['kind'];
    $status = (string) $row['status'];
    $number = (int) $row['order_number'];
    $created = formatRuDateTime(fromDb((string) $row['created_at']));
    $applied = formatRuDateTime(fromDb((string) ($row['applied_at'] ?? '')));
    $amount = classifiedsPromotionAmountLabel(
        $row['amount'] === null ? null : (float) $row['amount'],
        (string) $row['currency']
    );
    $kindLabel = CLASSIFIEDS_PROMO_KINDS[$kind] ?? 'Услуга';
    $statusLabel = CLASSIFIEDS_PROMO_STATUSES[$status] ?? $status;
    $href = '/classifieds/' . rawurlencode((string) $row['listing_slug']);

    $items .= '<li class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">'
        . '<div class="min-w-0 space-y-1">'
        . '<p class="font-medium">' . e($kindLabel)
        . ' · <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent" href="'
        . e($href) . '">' . e((string) $row['listing_title']) . '</a></p>'
        . '<p class="text-sm text-ink/60">' . e($statusLabel)
        . ($amount !== '' ? ' · ' . e($amount) : '')
        . ($created !== '' ? ' · ' . e($created) : '')
        . ($applied !== '' ? ' · применено ' . e($applied) : '') . '</p>'
        . '<p class="text-sm text-ink/60">Заказ №' . e((string) $number) . '</p>'
        . '</div>'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"'
        . ' href="/shop/order">Состояние заказа</a>'
        . '</li>';
}

$content = '<section class="space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Услуги и заказы</h1>'
    . '<p class="text-ink/70">Здесь видно, какие услуги вы заказали. Оплата проходит в магазине: '
    . 'узнайте номер заказа и введите его вместе с e-mail на странице состояния заказа.</p>'
    . '<div class="flex flex-wrap gap-2">'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' href="/classifieds/cabinet">В кабинет</a>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"'
    . ' href="/shop/order">Состояние заказа</a>'
    . '</div>'
    . '</header>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . ($items === ''
        ? '<p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">'
            . 'Заказов услуг пока нет. Их можно купить кнопками в списке объявлений.</p>'
        : '<ul class="space-y-3">' . $items . '</ul>')
    . '</section>';
?>
<?= $content /* @safe html */ ?>
