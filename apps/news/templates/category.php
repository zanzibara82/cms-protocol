<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $heading
 * @var string $intro
 * @var list<array{slug: string, title: string}> $categories
 * @var string $current
 * @var string $empty
 */
?>
<section class="space-y-8">
    <nav aria-label="Путь до рубрики" class="flex flex-wrap gap-2 text-sm text-ink/70">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/">Главная</a>
        <span aria-hidden="true">→</span>
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/news">Новости</a>
        <span aria-hidden="true">→</span>
        <span><?= e($heading) ?></span>
    </nav>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
    </header>

    <?= partial('news/partials/search', ['q' => '']) /* @safe html */ ?>
    <?= partial('news/partials/categories', ['categories' => $categories, 'current' => $current]) /* @safe html */ ?>
    <?= partial('news/partials/list', ['feed' => $feed, 'empty' => $empty]) /* @safe html */ ?>
</section>
