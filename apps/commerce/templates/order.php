<?php

declare(strict_types=1);

/**
 * Подтверждение заказа: сводка только из своей сессии (см. confirm-гейт).
 *
 * @var array<string, mixed> $order
 * @var list<array{id: string, sku: string, title: string, currency: string, unit_price: float, qty: int}> $items
 */
$currency = (string) $order['currency'];
$couponDiscountCents = commerceCents((float) ($order['coupon_discount'] ?? 0));
$promoDiscountCents = commerceCents((float) ($order['promo_discount'] ?? 0));
$discounted = $couponDiscountCents > 0 || $promoDiscountCents > 0;
?>
<section class="space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Заказ №<?= e((string) $order['number']) ?> принят</h1>
        <p class="text-ink/70">Подтверждение ушло на <?= e((string) $order['customer_email']) ?> — мы напишем, когда отправим.</p>
    </header>

    <ul class="space-y-3">
        <?php foreach ($items as $item): ?>
            <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">
                <span class="font-medium"><?= e((string) $item['title']) ?> <span class="text-sm text-ink/50">· <?= e((string) $item['sku']) ?></span></span>
                <span class="text-sm text-ink/70"><?= e((string) $item['qty']) ?> × <?= e(commerceFormatMoney(commerceCents((float) $item['unit_price']), (string) $item['currency'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="text-xl font-bold">Итого<?= e($discounted ? ' к оплате: ' : ': ') ?><?= e(commerceFormatMoney(commerceCents((float) $order['total']), (string) $order['currency'])) ?></p>
    <?php if ($promoDiscountCents > 0): ?>
        <p class="text-sm text-ink/70">Скидка по правилу «<?= e((string) ($order['promo_title'] ?? '')) ?>»: −<?= e(commerceFormatMoney($promoDiscountCents, $currency)) ?></p>
    <?php endif; ?>
    <?php if ($couponDiscountCents > 0): ?>
        <p class="text-sm text-ink/70">Скидка по купону <?= e((string) ($order['coupon_code'] ?? '')) ?>: −<?= e(commerceFormatMoney($couponDiscountCents, $currency)) ?></p>
    <?php endif; ?>

    <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
        <dt class="font-medium text-ink/60">Получатель</dt>
        <dd><?= e((string) $order['customer_name']) ?></dd>
        <?php if ((string) ($order['customer_phone'] ?? '') !== ''): ?>
            <dt class="font-medium text-ink/60">Телефон</dt>
            <dd><?= e((string) $order['customer_phone']) ?></dd>
        <?php endif; ?>
        <?php if ((string) ($order['address'] ?? '') !== ''): ?>
            <dt class="font-medium text-ink/60">Адрес</dt>
            <dd><?= e((string) $order['address']) ?></dd>
        <?php endif; ?>
        <?php if ((string) ($order['comment'] ?? '') !== ''): ?>
            <dt class="font-medium text-ink/60">Комментарий</dt>
            <dd><?= e((string) $order['comment']) ?></dd>
        <?php endif; ?>
        <dt class="font-medium text-ink/60">Статус</dt>
        <dd><?= e((string) (COMMERCE_ORDER_STATUS_LABELS[(string) $order['status']] ?? $order['status'])) ?></dd>
    </dl>

    <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/shop">Вернуться в каталог</a>
    <p class="text-sm text-ink/60">
        Эта страница привязана к оформлению на этом устройстве. Позже заказ открывается
        по номеру и e-mail на экране
        <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="/shop/order">«Состояние заказа»</a>.
    </p>
    <?php region('order'); ?>
</section>
