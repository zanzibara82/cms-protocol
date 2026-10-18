<?php

declare(strict_types=1);

/**
 * Карточка товара в списках: карточка витрины и админ-листы используют один партиал.
 *
 * @var array<string, mixed> $product
 */
$promo = $product['price_promo'] !== null;
$priceCents = $promo ? commerceCents((float) $product['price_promo']) : commerceCents((float) $product['price_retail']);
$stock = (int) $product['stock'];
// Тон подписи об остатке: класс собран в переменную до разметки, чтобы страж стилей
// не видел в атрибуте ни литералов, ни арифметики.
$stockTone = $stock > 5 ? 'text-ink/60' : 'text-red-700';

?>
<article class="flex flex-col gap-3 rounded-xl border border-ink/10 bg-white p-4">
    <?php if ((string) ($product['cover'] ?? '') !== ''): ?>
        <img class="aspect-[4/3] w-full rounded-lg object-cover" src="<?= e((string) $product['cover']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <h3 class="text-lg font-semibold leading-snug">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e('/shop/' . rawurlencode((string) $product['slug'])) ?>"><?= e((string) $product['title']) ?></a>
    </h3>

    <?php if ((string) ($product['brand'] ?? '') !== ''): ?>
        <p class="text-sm text-ink/60"><?= e((string) $product['brand']) ?></p>
    <?php endif; ?>

    <p class="text-xl font-bold">
        <?php if ($promo): ?>
            <s class="mr-2 text-base font-normal text-ink/50"><?= e(commerceFormatMoney(commerceCents((float) $product['price_retail']), (string) $product['currency'])) ?></s>
        <?php endif; ?>
        <?= e(commerceFormatMoney($priceCents, (string) $product['currency'])) ?>
    </p>

    <p class="text-sm <?= e($stockTone) ?>">
        <?php if ($stock > 0): ?>
            В наличии: <?= e((string) $stock) ?><?= e($stock <= 5 ? ' — остаток мал' : '') ?>
        <?php else: ?>
            Нет в наличии
        <?php endif; ?>
    </p>

    <?php if ((string) ($product['status'] ?? '') === 'PUBLISHED'): ?>
        <form method="post" action="/shop/cart/add">
            <?= csrfField() /* @safe html */ ?>
            <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
            <input type="hidden" name="back" value="/shop">
            <button class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90 disabled:opacity-40" type="submit" <?= e($stock > 0 ? '' : 'disabled') ?>>
                В корзину
            </button>
        </form>
    <?php endif; ?>
</article>
