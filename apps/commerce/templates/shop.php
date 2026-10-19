<?php

declare(strict_types=1);

/**
 * Витрина магазина: каталог товаров и навигация по рубрикам, поиск и сортировка.
 *
 * @var string $heading
 * @var string $intro
 * @var string $notice
 * @var string $query
 * @var string $sort
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
        <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="/shop/order">
            Состояние заказа
        </a>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <form method="get" action="/shop" class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
            <span class="mb-1 block text-ink/70">Поиск по товару, артикулу и варианту</span>
            <input type="search" name="q" maxlength="80" value="<?= e($query) ?>"
                   class="w-64 min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" />
        </label>
        <label class="text-sm">
            <span class="mb-1 block text-ink/70">Сортировка</span>
            <select name="sort" class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm">
                <option value="new" <?= e($sort === 'new' ? 'selected' : '') ?>>Новое сверху</option>
                <option value="price-asc" <?= e($sort === 'price-asc' ? 'selected' : '') ?>>Сначала дешевле</option>
                <option value="price-desc" <?= e($sort === 'price-desc' ? 'selected' : '') ?>>Сначала дороже</option>
            </select>
        </label>
        <button type="submit" class="min-h-[44px] rounded-lg bg-ink px-4 py-2 text-sm text-cream hover:bg-ink/90">
            Показать
        </button>
        <?php if ($query !== ''): ?>
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="/shop">
                Снять поиск
            </a>
        <?php endif; ?>
    </form>

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
            <?= e($query !== ''
                ? 'Ничего не нашлось: попробуйте короче или по-другому.'
                : 'Товаров пока нет — загляните позже.') ?>
        </p>
    <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ((array) $list['rows'] as $product): ?>
                <?= partial('commerce/partials/card', ['product' => $product]) /* @safe html */ ?>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $list['pages'] > 1): ?>
            <nav aria-label="Страницы каталога" class="flex flex-wrap items-center gap-2 text-sm">
                <?php for ($page = 1; $page <= (int) $list['pages']; $page++):
                    $paramsList = ['page' => $page];
                    if ($query !== '') {
                        $paramsList['q'] = $query;
                    }
                    if ($sort !== 'new') {
                        $paramsList['sort'] = $sort;
                    }
                    $href = '/shop?' . http_build_query($paramsList); ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                       href="<?= e($href) ?>" <?= e($page === (int) $list['current'] ? 'aria-current="true"' : '') ?>><?= e((string) $page) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
