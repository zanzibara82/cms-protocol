<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Призыв: тёмная полоса с кремовым текстом, по центру. Шапку рисует сам — общий частичный
// оставляет светлые поля и левое выравнивание. Кнопки инвертируются: кремовая подложка на тёмном.
$buttons = pagesButtonsOf($section['buttons'] ?? null);
$anchor = (string) $section['anchor'];
?>
<section class="bg-ink py-12 text-cream"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 text-center sm:px-6">
        <?php if ((string) $section['badge'] !== ''): ?>
            <p class="text-sm font-semibold uppercase tracking-wide text-cream/60"><?= e((string) $section['badge']) ?></p>
        <?php endif; ?>
        <?php if ((string) $section['title'] !== ''): ?>
            <h2 class="mt-2 text-2xl font-bold sm:text-3xl"><?= e((string) $section['title']) ?></h2>
        <?php endif; ?>
        <?php foreach (pagesParagraphs((string) $section['lead_text']) as $paragraph): ?>
            <p class="mx-auto mt-4 max-w-2xl text-cream/80"><?= e($paragraph) ?></p>
        <?php endforeach; ?>
        <?php if ($buttons !== []): ?>
            <p class="mt-6 flex flex-wrap justify-center gap-3">
                <?php foreach ($buttons as $button): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg bg-cream px-4 py-2 font-semibold text-ink hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>
</section>
