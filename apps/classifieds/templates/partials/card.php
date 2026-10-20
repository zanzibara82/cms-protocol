<?php

declare(strict_types=1);

/**
 * Карточка объявления: картинка, цена, заголовок, место и дата.
 *
 * @var array<string, mixed> $listing
 */
$href = '/classifieds/' . rawurlencode((string) $listing['slug']);
$price = classifiedsPriceLabel(
    $listing['price'] === null ? null : (float) $listing['price'],
    (int) $listing['negotiable'] === 1
);
$date = formatRuDate(fromDb((string) $listing['created_at']));
?>
<article class="flex h-full flex-col overflow-hidden rounded-xl border border-ink/10 bg-white">
    <a class="block min-h-[44px] aspect-[4/3] bg-cream" href="<?= e($href) ?>">
        <?php if ((string) ($listing['image'] ?? '') !== ''): ?>
            <img class="h-full w-full object-cover" src="<?= e((string) $listing['image']) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="flex h-full items-center justify-center text-sm text-ink/40">Без фото</span>
        <?php endif; ?>
    </a>
    <div class="flex flex-1 flex-col gap-1 p-4">
        <p class="text-lg font-semibold tabular-nums"><?= e($price) ?></p>
        <h3 class="font-medium">
            <a class="inline-flex min-h-[44px] items-center underline-offset-4 hover:underline" href="<?= e($href) ?>"><?= e((string) $listing['title']) ?></a>
        </h3>
        <p class="mt-auto flex flex-wrap gap-x-3 text-sm text-ink/60">
            <?php if ((string) ($listing['location'] ?? '') !== ''): ?>
                <span><?= e((string) $listing['location']) ?></span>
            <?php endif; ?>
            <?php if ($date !== ''): ?>
                <span><?= e($date) ?></span>
            <?php endif; ?>
        </p>
    </div>
</article>
