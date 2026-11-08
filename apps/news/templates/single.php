<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $item
 * @var bool $open
 * @var bool $preview
 * @var bool $scheduled
 * @var bool $showUpdated
 */
$breadcrumbs = [
    ['label' => 'Главная', 'href' => '/'],
    ['label' => 'Новости', 'href' => '/news'],
];

if ((string) $item['categoryTitle'] !== '') {
    $breadcrumbs[] = [
        'label' => (string) $item['categoryTitle'],
        'href' => $item['categoryVisible'] === true ? (string) $item['categoryHref'] : '',
    ];
}

$breadcrumbs[] = ['label' => (string) $item['title'], 'href' => ''];
?>
<article class="space-y-6">
    <?php if ($preview): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">
            <?= e($scheduled
                ? 'Так материал увидят в срок: публикация назначена, на сайте его ещё нет.'
                : 'Так материал видят редакторы: это черновик, на сайте его нет.') ?>
        </p>
    <?php endif; ?>

    <?= partial('partials/breadcrumbs', ['items' => $breadcrumbs]) /* @safe html */ ?>

    <header class="space-y-3">
        <h1 class="text-3xl font-bold"><?= e((string) $item['title']) ?></h1>
        <p class="flex flex-wrap gap-2 text-sm text-ink/70">
            <span><?= e((string) $item['date']) ?></span>
            <?php if ($showUpdated): ?>
                <span>· обновлено <?= e((string) $item['updated']) ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ((string) $item['cover'] !== ''): ?>
        <img class="w-full rounded-xl object-cover" src="<?= e((string) $item['cover']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if ((string) $item['lead'] !== ''): ?>
        <p class="text-lg text-ink/70"><?= e((string) $item['lead']) ?></p>
    <?php endif; ?>

    <?= partial('news/partials/body', ['paragraphs' => $item['paragraphs']]) /* @safe html */ ?>

    <?php if ((array) $item['tags'] !== []): ?>
        <ul class="flex flex-wrap gap-2">
            <?php foreach ((array) $item['tags'] as $tag): ?>
                <li>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg bg-accent/10 px-3 py-1 text-sm hover:opacity-80"
                       href="<?= e((string) $tag['href']) ?>">#<?= e((string) $tag['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php region('article', ['slug' => (string) $item['slug'], 'category' => (string) $item['categorySlug']]); ?>
</article>
