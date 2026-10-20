<?php

declare(strict_types=1);

/**
 * Публичная страница продавца: контакты из профиля и опубликованные объявления.
 *
 * @var array{id: string, name: string, city: string, phone: string, about: string, show_phone: bool, member_since: ?DateTimeImmutable} $seller
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int, perPage: int, q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $list
 * @var array{q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $state
 * @var string $base
 * @var list<array{id: string, slug: string, title: string, depth: int}> $categories
 */
?>
<section class="space-y-8">
    <nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds">Доска</a>
        <span aria-hidden="true">/</span>
        <span><?= e($seller['name']) ?></span>
    </nav>

    <header class="space-y-3 rounded-xl border border-ink/10 bg-white p-5">
        <h1 class="text-3xl font-bold"><?= e($seller['name']) ?></h1>
        <div class="space-y-1 text-sm text-ink/70">
            <?php if ($seller['city'] !== ''): ?>
                <p>Город: <?= e($seller['city']) ?></p>
            <?php endif; ?>
            <?php if ($seller['member_since'] !== null): ?>
                <p>На доске с <?= e(formatRuDate($seller['member_since'])) ?></p>
            <?php endif; ?>
            <?php if ($seller['phone'] !== ''): ?>
                <p>Телефон: <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"
                               href="tel:<?= e($seller['phone']) ?>"><?= e($seller['phone']) ?></a></p>
            <?php endif; ?>
        </div>
        <?php if ($seller['about'] !== ''): ?>
            <p class="whitespace-pre-line text-ink/80"><?= e($seller['about']) ?></p>
        <?php endif; ?>
    </header>

    <?php if ($categories !== []): ?>
        <nav aria-label="Категории доски" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $category): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/10 bg-cream px-3 py-1.5 text-sm font-medium hover:bg-ink/5"
                   href="<?= e(classifiedsCategoryHref((string) $category['slug'])) ?>">
                    <?= e(str_repeat('— ', (int) $category['depth']) . (string) $category['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <section class="space-y-4">
        <h2 class="text-xl font-semibold">Объявления продавца: <?= e((string) $list['total']) ?></h2>
        <?php if ((array) $list['rows'] === []): ?>
            <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
                У продавца пока нет опубликованных объявлений.
            </p>
        <?php else: ?>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ((array) $list['rows'] as $listing): ?>
                    <?= partial('classifieds/partials/card', ['listing' => $listing, 'view' => $state['view']]) /* @safe html */ ?>
                <?php endforeach; ?>
            </div>

            <?php if ((int) $list['pages'] > 1): ?>
                <nav aria-label="Страницы объявлений" class="flex flex-wrap items-center gap-2 text-sm">
                    <?php for ($page = 1; $page <= (int) $list['pages']; $page++): ?>
                        <a class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 <?= e($page === (int) $list['current'] ? 'bg-ink text-cream' : 'border border-ink/10 bg-cream hover:bg-ink/5') ?>"
                           href="<?= e(classifiedsFeedUrl($base, $state, $page)) ?>"
                           <?php if ($page === (int) $list['current']): ?>aria-current="true"<?php endif; ?>><?= e((string) $page) ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>
