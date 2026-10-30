<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Полоса-обращение: светлая подложка отделяет блок от соседних секций, текст по центру.
// Шапку рисует сам: центрированная вёрстка у общего частичного не предусмотрена.
$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
?>
<section class="bg-ink/5 py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 text-center sm:px-6">
        <?php if ((string) $section['badge'] !== ''): ?>
            <p class="text-sm font-semibold uppercase tracking-wide text-ink/60"><?= e((string) $section['badge']) ?></p>
        <?php endif; ?>
        <?php if ((string) $section['title'] !== ''): ?>
            <h2 class="mt-2 text-2xl font-bold sm:text-3xl"><?= e((string) $section['title']) ?></h2>
        <?php endif; ?>
        <?php foreach (pagesParagraphs((string) $section['lead_text']) as $paragraph): ?>
            <p class="mx-auto mt-4 max-w-2xl text-ink/80"><?= e($paragraph) ?></p>
        <?php endforeach; ?>
        <?php if ($note !== ''): ?>
            <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
        <?php endif; ?>
    </div>
</section>
