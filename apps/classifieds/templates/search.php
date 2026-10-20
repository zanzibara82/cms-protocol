<?php

declare(strict_types=1);

/**
 * Поиск по всей доске: слова, сортировка, вид и страницы. Страница всегда noindex.
 *
 * @var list<array{id: string, parent_id: ?string, slug: string, title: string, order_index: int, visible: bool, depth: int}> $categories
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $list
 * @var int $total
 * @var string $q
 * @var array{q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $state
 * @var string $base
 */
$listMode = $state['view'] === 'list';
?>
<section class="space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Поиск объявлений</h1>
        <p class="text-ink/70">
            <?php if ($q !== ''): ?>
                <?= e('По запросу «' . $q . '»: ' . $total . ' ' . plural((int) $total, ['объявление', 'объявления', 'объявлений'])) ?>
            <?php else: ?>
                Введите слова — ищем по заголовку и описанию.
            <?php endif; ?>
        </p>
    </header>

    <?= partial('classifieds/partials/filters', ['attributes' => [], 'state' => $state, 'base' => $base]) /* @safe html */ ?>

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

    <?php if ($categories !== []): ?>
        <nav aria-label="Категории доски" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $item): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/10 bg-cream px-3 py-1.5 text-sm font-medium hover:bg-ink/5"
                   href="<?= e(classifiedsCategoryHref((string) $item['slug'])) ?>">
                    <?= e(str_repeat('— ', (int) $item['depth']) . (string) $item['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ((array) $list['rows'] === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            <?php if ($q === ''): ?>
                Начните с поиска по словам.
            <?php else: ?>
                По этим словам объявлений не нашлось — попробуйте короче.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="<?= e($listMode ? 'space-y-3' : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3') ?>">
            <?php foreach ((array) $list['rows'] as $listing): ?>
                <?= partial('classifieds/partials/card', ['listing' => $listing, 'view' => $state['view']]) /* @safe html */ ?>
            <?php endforeach; ?>
        </div>

        <?php if ((int) $list['pages'] > 1): ?>
            <nav aria-label="Страницы поиска" class="flex flex-wrap items-center gap-2 text-sm">
                <?php for ($page = 1; $page <= (int) $list['pages']; $page++): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                       href="<?= e(classifiedsFeedUrl($base, $state, $page)) ?>"
                       <?php if ($page === (int) $list['current']): ?>aria-current="true"<?php endif; ?>><?= e((string) $page) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
