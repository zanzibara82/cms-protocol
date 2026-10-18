<?php

declare(strict_types=1);

/**
 * Витрина магазина: каталог товаров и навигация по рубрикам.
 *
 * @var string $heading
 * @var string $intro
 * @var string $notice
 * @var list<array{id: string, slug: string, title: string, order_index: int}> $categories
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $list
 * @var int $cartCount
 */
?>
<section class="space-y-8">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
        <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="/shop/cart">
            Корзина<?= e($cartCount > 0 ? ' (' . $cartCount . ')' : '') ?>
        </a>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($categories !== []): ?>
        <nav aria-label="Рубрики магазина" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $category): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium border border-ink/10 bg-cream hover:bg-ink/5"
                   href="<?= e('/shop/c/' . rawurlencode((string) $category['slug'])) ?>">
                    <?= e((string) $category['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ((array) $list['rows'] === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            Товаров пока нет — загляните позже.
        </p>
    <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ((array) $list['rows'] as $product): ?>
                <?= partial('commerce/partials/card', ['product' => $product]) /* @safe html */ ?>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $list['pages'] > 1): ?>
            <nav aria-label="Страницы каталога" class="flex flex-wrap items-center gap-2 text-sm">
                <?php for ($page = 1; $page <= (int) $list['pages']; $page++): ?>
                    <?php $href = $page === 1 ? '/shop' : '/shop?page=' . $page; ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                       href="<?= e($href) ?>" <?= e($page === (int) $list['current'] ? 'aria-current="true"' : '') ?>><?= e((string) $page) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
