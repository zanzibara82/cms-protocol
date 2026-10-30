<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */
/** @var string $tag */

// Общая шапка секции: надзаголовок, заголовок и абзацы лида. Пустые поля не печатаются — секция
// без шапки остаётся честным пустым местом. Hero и cta рисуют шапку сами: у первого заголовок
// h1 (на странице он один и принадлежит первому экрану), у второго тёмная полоса с кремовым
// текстом и центрированной вёрсткой.
$tag = (string) ($tag ?? 'h2');
?>
<?php if ((string) $section['badge'] !== ''): ?>
    <p class="text-sm font-semibold uppercase tracking-wide text-ink/60"><?= e((string) $section['badge']) ?></p>
<?php endif; ?>
<?php if ((string) $section['title'] !== ''): ?>
    <<?= e($tag) ?> class="mt-2 text-2xl font-bold sm:text-3xl"><?= e((string) $section['title']) ?></<?= e($tag) ?>>
<?php endif; ?>
<?php foreach (pagesParagraphs((string) $section['lead_text']) as $paragraph): ?>
    <p class="mt-4 max-w-2xl text-ink/80"><?= e($paragraph) ?></p>
<?php endforeach; ?>
