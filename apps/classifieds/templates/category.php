<?php

declare(strict_types=1);

/**
 * Страница категории: объявления самой категории и её ветки, поиск, фильтры,
 * сортировка, вид и страницы. Панель фильтров — отдельный partial.
 *
 * @var array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool} $category
 * @var list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool, depth: int}> $categories
 * @var list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $attributes
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $list
 * @var int $total
 * @var array{q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $state
 * @var string $base
 */
$activeFilter = $state['filters'] !== [];
$listMode = $state['view'] === 'list';
?>
<section class="space-y-6">
    <nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds">Доска</a>
        <span aria-hidden="true">/</span>
        <span class="inline-flex min-h-[44px] items-center text-ink"><?= e((string) $category['title']) ?></span>
    </nav>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e((string) $category['title']) ?></h1>
        <p class="text-ink/70"><?= e((string) $total . ' ' . plural((int) $total, ['объявление', 'объявления', 'объявлений'])) ?> в категории и её подкатегориях.</p>
        <div class="flex flex-wrap gap-2">
            <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"
               href="/classifieds/cabinet/new">Разместить объявление</a>
        </div>
    </header>

    <?php if ($categories !== []): ?>
        <nav aria-label="Категории доски" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $item): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border px-3 py-1.5 text-sm font-medium <?= e((string) $item['id'] === (string) $category['id'] ? 'border-ink bg-ink text-cream' : 'border-ink/10 bg-cream hover:bg-ink/5') ?>"
                   href="<?= e(classifiedsCategoryHref((string) $item['slug'])) ?>">
                    <?= e(str_repeat('— ', (int) $item['depth']) . (string) $item['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?= partial('classifieds/partials/filters', ['attributes' => $attributes, 'state' => $state, 'base' => $base]) /* @safe html */ ?>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink/60">Сортировка:</span>
            <?php foreach (CLASSIFIEDS_SORTS as $sort => $label): ?>
                <?php if ($sort === 'relevance' && $state['q'] === '') {
                    continue;
                } ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($sort === $state['sort'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                   href="<?= e(classifiedsFeedUrl($base, array_merge($state, ['sort' => $sort]))) ?>"
                   <?php if ($sort === $state['sort']): ?>aria-current="true"<?php endif; ?>><?= e((string) $label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink/60">Вид:</span>
            <?php foreach (CLASSIFIEDS_VIEW_LABELS as $view => $label): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($view === $state['view'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                   href="<?= e(classifiedsFeedUrl($base, array_merge($state, ['view' => $view]))) ?>"
                   <?php if ($view === $state['view']): ?>aria-current="true"<?php endif; ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ((array) $list['rows'] === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            <?= e($state['q'] !== '' || $activeFilter
                ? 'По этим условиям объявлений не нашлось — попробуйте изменить запрос или фильтры.'
                : 'В этой категории пока нет объявлений — ваше может стать первым.') ?>
        </p>
    <?php else: ?>
        <div class="<?= e($listMode ? 'space-y-3' : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3') ?>">
            <?php foreach ((array) $list['rows'] as $listing): ?>
                <?= partial('classifieds/partials/card', ['listing' => $listing, 'view' => $state['view']]) /* @safe html */ ?>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $list['pages'] > 1): ?>
            <nav aria-label="Страницы категории" class="flex flex-wrap items-center gap-2 text-sm">
                <?php for ($page = 1; $page <= (int) $list['pages']; $page++): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                       href="<?= e(classifiedsFeedUrl($base, $state, $page)) ?>"
                       <?php if ($page === (int) $list['current']): ?>aria-current="true"<?php endif; ?>><?= e((string) $page) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
