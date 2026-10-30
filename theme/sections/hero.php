<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Первый экран: заголовок h1, абзацы лида, примечание, картинка и кнопки. Шапку рисует сам —
// h1 на странице один и принадлежит первому экрану, общий частичный печатает h2.
$paragraphs = pagesParagraphs((string) $section['lead_text']);
$buttons = pagesButtonsOf($section['buttons'] ?? null);
$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
$image = (string) $section['image'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?php if ((string) $section['badge'] !== ''): ?>
            <p class="text-sm font-semibold uppercase tracking-wide text-ink/60"><?= e((string) $section['badge']) ?></p>
        <?php endif; ?>
        <?php if ((string) $section['title'] !== ''): ?>
            <h1 class="mt-2 text-3xl font-bold sm:text-4xl"><?= e((string) $section['title']) ?></h1>
        <?php endif; ?>
        <?php foreach ($paragraphs as $paragraph): ?>
            <p class="mt-4 max-w-2xl text-ink/80"><?= e($paragraph) ?></p>
        <?php endforeach; ?>
        <?php if ($note !== ''): ?>
            <p class="mt-4 text-sm text-ink/50"><?= e($note) ?></p>
        <?php endif; ?>
        <?php if ($image !== ''): ?>
            <img class="mt-8 w-full rounded-xl" src="<?= e($image) ?>" alt="<?= e((string) $section['image_alt']) ?>">
        <?php endif; ?>
        <?php if ($buttons !== []): ?>
            <p class="mt-8 flex flex-wrap gap-3">
                <?php foreach ($buttons as $button): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>
</section>
