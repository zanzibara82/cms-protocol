<?php

declare(strict_types=1);

/**
 * Хлебные крошки: последний пункт — текущая страница, ссылки ведут к родительским разделам.
 *
 * @var list<array{label: string, href: string}> $items
 */
$total = count($items);
?>
<?php if ($items !== []): ?>
    <nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">
        <?php foreach ($items as $index => $item): ?>
            <?php if ($index > 0): ?>
                <span aria-hidden="true">→</span>
            <?php endif; ?>
            <?php if ($index === $total - 1): ?>
                <span class="inline-flex min-h-[44px] items-center text-ink" aria-current="page"><?= e($item['label']) ?></span>
            <?php elseif ((string) $item['href'] !== ''): ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
            <?php else: ?>
                <span class="inline-flex min-h-[44px] items-center"><?= e($item['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
