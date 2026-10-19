<?php

declare(strict_types=1);

/**
 * Карточка товара витрины с покупкой. Товар с вариантами продаёт варианты: у каждого
 * своя цена (промо перечёркивает обычную) и кнопка корзины; характеристики рубрик
 * печатаются таблицей ниже.
 *
 * @var array<string, mixed> $product
 * @var list<array{id: string, title: string, slug: string}> $categories
 * @var string $priceHtml
 * @var bool $priceFrom
 * @var bool $inStock
 * @var list<array{id: string, sku: string, title: string, price_cents: int, price_retail_cents: int, currency: string, stock: int, in_stock: bool}> $variants
 * @var list<array{slug: string, label: string, type: string, unit: ?string, value: string, value_num: ?float}> $specs
 * @var int $cartCount
 */
$paragraphs = (array) $product['body'];
?>
<article class="space-y-8">
    <nav aria-label="Путь до товара" class="flex flex-wrap gap-2 text-sm text-ink/70">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/">Главная</a>
        <span aria-hidden="true">→</span>
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/shop">Каталог</a>
        <?php foreach ($categories as $category): ?>
            <span aria-hidden="true">→</span>
            <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e('/shop/c/' . rawurlencode((string) $category['slug'])) ?>"><?= e((string) $category['title']) ?></a>
        <?php endforeach; ?>
    </nav>

    <header class="space-y-3">
        <h1 class="text-3xl font-bold break-words"><?= e((string) $product['title']) ?></h1>
        <?php if ((string) ($product['brand'] ?? '') !== ''): ?>
            <p class="text-ink/70"><?= e((string) $product['brand']) ?></p>
        <?php endif; ?>
        <p class="flex flex-wrap items-baseline gap-3">
            <?php if ($product['price_promo'] !== null && !$priceFrom): ?>
                <s class="text-lg text-ink/50"><?= e(commerceFormatMoney(commerceCents((float) $product['price_retail']), (string) $product['currency'])) ?></s>
            <?php endif; ?>
            <span class="text-2xl font-bold"><?= e($priceFrom ? 'от ' . $priceHtml : $priceHtml) ?></span>
        </p>
    </header>

    <?php if ((string) ($product['cover'] ?? '') !== ''): ?>
        <img class="w-full max-w-xl rounded-xl object-cover" src="<?= e((string) $product['cover']) ?>" alt="<?= e((string) $product['title']) ?>" loading="lazy">
    <?php endif; ?>

    <?php if ((string) ($product['lead_text'] ?? '') !== ''): ?>
        <p class="text-lg text-ink/70"><?= e((string) $product['lead_text']) ?></p>
    <?php endif; ?>

    <?php if ($variants === [] && $inStock): ?>
        <form method="post" action="/shop/cart/add" class="flex flex-wrap items-end gap-3">
            <?= csrfField() /* @safe html */ ?>
            <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
            <input type="hidden" name="back" value="<?= e('/shop/' . rawurlencode((string) $product['slug'])) ?>">
            <label for="qty">
                <span class="block text-sm text-ink/60">Количество</span>
                <input class="mt-1 min-h-[44px] w-24 rounded-lg border border-ink/20 bg-white px-3 py-2" id="qty" name="qty" type="number" min="1" max="<?= e((string) min((int) $product['stock'], COMMERCE_CART_MAX_QTY)) ?>" value="1">
            </label>
            <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">В корзину</button>
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/shop/cart">Корзина<?= e($cartCount > 0 ? ' (' . $cartCount . ')' : '') ?></a>
        </form>
    <?php elseif ($variants === []): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">Товара нет в наличии — загляните позже.</p>
    <?php endif; ?>

    <?php if ($variants !== []): ?>
        <section aria-label="Варианты товара" class="space-y-3">
            <h2 class="text-lg font-semibold">Варианты</h2>
            <p class="flex flex-wrap items-center gap-3 text-sm">
                <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 hover:bg-ink/5" href="/shop/cart">Корзина<?= e($cartCount > 0 ? ' (' . $cartCount . ')' : '') ?></a>
            </p>
            <ul class="space-y-2">
                <?php foreach ($variants as $variant): ?>
                    <?php if ($variant['in_stock']): ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">
                            <div class="min-w-0">
                                <p class="font-medium"><?= e((string) $variant['title']) ?></p>
                                <p class="text-xs text-ink/50">
                                    <?= e((string) $variant['sku']) ?>
                                    <span class="tabular-nums">· остаток: <?= e((string) $variant['stock']) ?></span>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-bold">
                                    <?php if ($variant['price_retail_cents'] > 0): ?>
                                        <s class="mr-2 font-normal text-ink/50"><?= e(commerceFormatMoney((int) $variant['price_retail_cents'], (string) $variant['currency'])) ?></s>
                                    <?php endif; ?>
                                    <?= e(commerceFormatMoney((int) $variant['price_cents'], (string) $variant['currency'])) ?>
                                </span>
                                <form class="inline-flex" method="post" action="/shop/cart/add">
                                    <?= csrfField() /* @safe html */ ?>
                                    <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
                                    <input type="hidden" name="variant" value="<?= e((string) $variant['id']) ?>">
                                    <input type="hidden" name="back" value="<?= e('/shop/' . rawurlencode((string) $product['slug'])) ?>">
                                    <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">В корзину</button>
                                </form>
                            </div>
                        </li>
                    <?php else: ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink/10 bg-cream p-4">
                            <div class="min-w-0">
                                <p class="font-medium"><?= e((string) $variant['title']) ?></p>
                                <p class="text-xs text-ink/50"><?= e((string) $variant['sku']) ?></p>
                            </div>
                            <span class="text-sm text-ink/70">Нет в наличии</span>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
        <dt class="font-medium text-ink/60">Артикул</dt>
        <dd class="tabular-nums"><?= e((string) $product['sku']) ?></dd>
        <?php if ((string) ($product['barcode'] ?? '') !== ''): ?>
            <dt class="font-medium text-ink/60">Штрихкод</dt>
            <dd class="tabular-nums"><?= e((string) $product['barcode']) ?></dd>
        <?php endif; ?>
        <?php if ($variants === []): ?>
            <dt class="font-medium text-ink/60">Наличие</dt>
            <dd><?= e($inStock ? 'В наличии: ' . (string) $product['stock'] : 'Нет в наличии') ?></dd>
        <?php endif; ?>
        <?php foreach ($specs as $spec): ?>
            <dt class="font-medium text-ink/60"><?= e((string) $spec['label']) ?></dt>
            <dd class="tabular-nums"><?= e((string) $spec['value']) ?><?= e($spec['unit'] !== null && (string) $spec['unit'] !== '' ? ' ' . (string) $spec['unit'] : '') ?></dd>
        <?php endforeach; ?>
    </dl>

    <?= partial('commerce/partials/body', ['paragraphs' => $paragraphs]) /* @safe html */ ?>

    <?php region('product'); ?>
</article>
