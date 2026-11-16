<?php

declare(strict_types=1);

/** @var array<string, mixed> $type */
/** @var list<array{title: string, href: string, excerpt: string, published_at: string}> $cards */
/** @var string $description */

$content = '';
foreach ($cards as $card) {
    $content .= '<article class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="text-lg font-semibold"><a class="inline-flex min-h-[44px] items-center hover:underline" href="'
        . e((string) $card['href']) . '">' . e((string) $card['title']) . '</a></h2>';
    if ((string) $card['published_at'] !== '') {
        $content .= '<p class="text-xs text-ink/50">' . e(formatRuDateTime(fromDb((string) $card['published_at']))) . '</p>';
    }
    if ((string) $card['excerpt'] !== '') {
        $content .= '<p class="mt-2 text-sm text-ink/70">' . e((string) $card['excerpt']) . '</p>';
    }
    $content .= '</article>';
}
?>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
    <?= partial('partials/breadcrumbs', ['items' => [ /* @safe html */
        ['label' => 'Главная', 'href' => '/'],
        ['label' => (string) $type['name'], 'href' => ''],
    ]]) ?>
    <h1 class="mt-4 text-3xl font-bold"><?= e((string) $type['name']) ?></h1>
    <?php if ((string) $type['description'] !== ''): ?>
        <p class="mt-3 max-w-2xl text-ink/70"><?= e((string) $type['description']) ?></p>
    <?php endif; ?>
    <?php if ($content !== ''): ?>
        <div class="mt-6 grid gap-3 sm:grid-cols-2"><?= $content /* @safe html */ ?></div>
    <?php else: ?>
        <p class="mt-6 text-ink/60">Здесь пока нет опубликованных записей.</p>
    <?php endif; ?>
</div>
