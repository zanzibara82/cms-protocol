<?php

declare(strict_types=1);

/** @var array<string, mixed> $card */
?>
<article class="flex flex-col overflow-hidden rounded-xl border border-ink/10 bg-cream">
    <?php if ((string) $card['cover'] !== ''): ?>
        <img class="h-40 w-full object-cover" src="<?= e((string) $card['cover']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <div class="flex flex-1 flex-col gap-2 p-4">
        <p class="flex flex-wrap items-center gap-2 text-xs text-ink/70">
            <span><?= e((string) $card['date']) ?></span>
            <?php if ((string) $card['categoryTitle'] !== ''): ?>
                <?php if ($card['categoryVisible'] === true): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded bg-accent/10 px-2 py-0.5 hover:opacity-80"
                       href="<?= e((string) $card['categoryHref']) ?>"><?= e((string) $card['categoryTitle']) ?></a>
                <?php else: ?>
                    <span class="inline-flex min-h-[44px] items-center rounded bg-accent/10 px-2 py-0.5"><?= e((string) $card['categoryTitle']) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($card['pinned'] === true): ?>
                <span class="rounded bg-ink px-2 py-0.5 text-cream">Важное</span>
            <?php endif; ?>
        </p>

        <h3 class="text-lg font-semibold">
            <a class="inline-flex min-h-[44px] items-center hover:text-accent" href="<?= e((string) $card['href']) ?>"><?= e((string) $card['title']) ?></a>
        </h3>

        <p class="text-sm text-ink/70"><?= e(excerpt((string) $card['lead'], 140)) ?></p>
    </div>
</article>
