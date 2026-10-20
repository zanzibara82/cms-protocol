<?php

declare(strict_types=1);

/**
 * Страница объявления: фото, цена, описание, место и дата.
 *
 * @var array<string, mixed> $listing
 * @var string $priceLabel
 * @var string $dateLabel
 * @var string $categoryHref
 * @var string $categoryTitle
 * @var bool $preview
 */
?>
<article class="space-y-6">
    <nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds">Доска</a>
        <?php if ($categoryHref !== ''): ?>
            <span aria-hidden="true">/</span>
            <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e($categoryHref) ?>"><?= e($categoryTitle) ?></a>
        <?php endif; ?>
    </nav>

    <?php if ($preview): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">
            Предпросмотр: объявление ещё не опубликовано — его видите только вы.
        </p>
    <?php endif; ?>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e((string) $listing['title']) ?></h1>
        <p class="text-2xl font-semibold tabular-nums"><?= e($priceLabel) ?></p>
        <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink/60">
            <?php if ((string) ($listing['location'] ?? '') !== ''): ?>
                <span><?= e((string) $listing['location']) ?></span>
            <?php endif; ?>
            <?php if ($dateLabel !== ''): ?>
                <span><?= e($dateLabel) ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ((string) ($listing['image'] ?? '') !== ''): ?>
        <img class="w-full rounded-xl object-cover" src="<?= e((string) $listing['image']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <section class="space-y-2">
        <h2 class="text-xl font-semibold">Описание</h2>
        <p class="whitespace-pre-line text-ink/80"><?= e((string) $listing['body']) ?></p>
    </section>

    <footer class="rounded-xl border border-ink/10 bg-cream p-4 text-sm text-ink/60">
        Объявление размещено на доске. Договоритесь с продавцом о встрече и осмотрите товар до оплаты.
    </footer>
</article>
