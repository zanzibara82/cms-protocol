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
    <?= partial('partials/breadcrumbs', ['items' => [ /* @safe html */
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Новости', 'href' => '/news'],
        ['label' => $heading, 'href' => ''],
    ]]) ?>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e($heading) ?></h1>
        <p class="text-ink/70"><?= e($intro) ?></p>
    </header>

    <?= partial('news/partials/search', ['q' => '']) /* @safe html */ ?>
    <?= partial('news/partials/categories', ['categories' => $categories, 'current' => $current]) /* @safe html */ ?>
    <?= partial('news/partials/list', ['feed' => $feed, 'empty' => $empty]) /* @safe html */ ?>
<?php region('category'); ?>
</section>
