<?php

declare(strict_types=1);

/**
 * Главная доски: категории деревом и свежие объявления.
 *
 * @var list<array{id: string, slug: string, title: string, depth: int}> $categories
 * @var list<array<string, mixed>> $listings
 * @var string $notice
 */
?>
<section class="space-y-8">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Доска объявлений</h1>
        <p class="text-ink/70">Купить, продать, сдать и найти — без посредников.</p>
        <div class="flex flex-wrap gap-2">
            <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"
               href="/classifieds/cabinet/new">Разместить объявление</a>
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"
               href="/classifieds/cabinet">Мои объявления</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($categories !== []): ?>
        <nav aria-label="Категории доски" class="flex flex-wrap gap-2">
            <?php foreach ($categories as $category): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/10 bg-cream px-3 py-1.5 text-sm font-medium hover:bg-ink/5"
                   href="<?= e(classifiedsCategoryHref((string) $category['slug'])) ?>">
                    <?= e(str_repeat('— ', (int) $category['depth']) . (string) $category['title']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php else: ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-4 text-sm text-ink/70">
            Категорий пока нет — их заводит администратор доски.
        </p>
    <?php endif; ?>

    <section class="space-y-4">
        <h2 class="text-xl font-semibold">Свежие объявления</h2>
        <?php if ($listings === []): ?>
            <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
                Объявлений пока нет — станьте первым продавцом.
            </p>
        <?php else: ?>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($listings as $listing): ?>
                    <?= partial('classifieds/partials/card', ['listing' => $listing]) /* @safe html */ ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
