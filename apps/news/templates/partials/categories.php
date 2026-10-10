<?php

declare(strict_types=1);

/**
 * @var list<array{slug: string, title: string}> $categories
 * @var string $current
 */
?>
<?php if ($categories !== []): ?>
    <nav aria-label="Рубрики новостей">
        <ul class="flex flex-wrap gap-2">
            <li>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border px-3 py-1 text-sm <?= e($current === '' ? 'border-accent bg-accent/10' : 'border-ink/10 bg-cream hover:border-ink/20') ?>" href="/news">Все</a>
            </li>
            <?php foreach ($categories as $category): ?>
                <li>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg border px-3 py-1 text-sm <?= e($current === $category['slug'] ? 'border-accent bg-accent/10' : 'border-ink/10 bg-cream hover:border-ink/20') ?>"
                       href="<?= e(newsHref(['category' => (string) $category['slug']])) ?>"><?= e((string) $category['title']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
