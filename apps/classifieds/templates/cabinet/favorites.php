<?php

declare(strict_types=1);

/**
 * Избранное покупателя. В списке только доступные объявления: снятые и истёкшие
 * остаются в базе, но не показываются.
 *
 * @var list<array<string, mixed>> $listings
 * @var string $notice
 */
?>
<section class="space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Избранное</h1>
        <p class="text-ink/70">Сохранённые объявления. Убрать можно в любой момент.</p>
        <div class="flex flex-wrap gap-2">
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"
               href="/classifieds/cabinet">В кабинет</a>
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"
               href="/classifieds">К объявлениям</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($listings === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            Пока пусто. Откройте объявление и нажмите «В избранное» — оно появится здесь.
        </p>
    <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($listings as $listing): ?>
                <div class="flex flex-col gap-2">
                    <?= partial('classifieds/partials/card', ['listing' => $listing]) /* @safe html */ ?>
                    <form method="post" action="/classifieds/cabinet/favorites">
                        <?= csrfField() ?>
                        <input type="hidden" name="listing_id" value="<?= e((string) $listing['id']) ?>">
                        <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"
                                type="submit">Убрать из избранного</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
