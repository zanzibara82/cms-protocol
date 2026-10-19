<?php

declare(strict_types=1);

/**
 * Страница рубрики витрины: карточки с фильтром рубрики и характеристиками (M2).
 * Форма фильтров печатается по набору атрибутов рубрики, чипы снимают выбранные
 * значения, пагинатор читает их же из адреса, чтобы страница не сбрасывала выбор.
 *
 * @var array<string, mixed> $category
 * @var list<array{id: string, slug: string, title: string, order_index: int}> $categories
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $list
 * @var array{rows: list<array{id: string, key: string, label: string, type: string, unit: ?string, choices: list<string>, raw: string, from: string, to: string}>, conditions: list<array{attribute_id: string, value: string, from: ?float, to: ?float}>, chips: list<array{label: string, value: string, query: string}>, query: string} $filters
 * @var int $cartCount
 */
$categoryHref = '/shop/c/' . rawurlencode((string) $category['slug']);
$keptQuery = (string) ($filters['query'] ?? '');

/**
 * Адрес страницы списка с фильтрами: параметры характеристик сохраняются, страница
 * переписывается.
 *
 * @param int $page
 */
$hrefAt = static function (int $page) use ($categoryHref, $keptQuery): string {
    $parts = [];

    if ($keptQuery !== '') {
        $parts[] = $keptQuery;
    }

    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $categoryHref . ($parts === [] ? '' : '?' . implode('&', $parts));
};

$listingIntro = 'Товаров: ' . (int) $list['total']
    . ($keptQuery !== '' ? ' по фильтрам' : '');
?>
<section class="space-y-8">
    <header class="space-y-2">
        <nav aria-label="Путь до рубрики" class="text-sm text-ink/70">
            <a class="inline-flex min-h-[44px] items-center hover:underline" href="/shop">Каталог</a>
            <span aria-hidden="true">→</span>
            <span class="inline-flex min-h-[44px] items-center"><?= e((string) $category['title']) ?></span>
        </nav>
        <h1 class="text-3xl font-bold"><?= e((string) $category['title']) ?></h1>
        <p class="text-sm text-ink/60"><?= e($listingIntro) ?></p>
        <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="/shop/cart">
            Корзина<?= e($cartCount > 0 ? ' (' . $cartCount . ')' : '') ?>
        </a>
    </header>

    <?php if ($categories !== []): ?>
        <nav aria-label="Рубрики магазина" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $item): ?>
                <?php $active = (string) $item['id'] === (string) $category['id']; ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm font-medium <?= e($active ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                   href="<?= e('/shop/c/' . rawurlencode((string) $item['slug'])) ?>">
                    <?= e((string) $item['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?= partial('commerce/partials/filters', ['filters' => $filters, 'categoryHref' => $categoryHref]) /* @safe html */ ?>

    <?php if ((array) $list['rows'] === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            <?= e($keptQuery !== '' ? 'Под фильтры ничего не подошло — снимите часть условий.' : 'В этой рубрике товаров пока нет.') ?>
        </p>
    <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ((array) $list['rows'] as $product): ?>
                <?= partial('commerce/partials/card', ['product' => $product]) /* @safe html */ ?>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $list['pages'] > 1): ?>
            <nav aria-label="Страницы рубрики" class="flex flex-wrap items-center gap-2 text-sm">
                <?php for ($page = 1; $page <= (int) $list['pages']; $page++): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                       href="<?= e($hrefAt($page)) ?>" <?= e($page === (int) $list['current'] ? 'aria-current="true"' : '') ?>><?= e((string) $page) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
