<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Цепочка шагов: путь курса или порядок действий короткими плашками с переходами-стрелками.
// Строка items — один шаг, title — подпись шага. Первый шаг выделен заливкой (отправная точка),
// остальные — светлые плашки. Шаг без названия не печатается, стрелка ставится только между
// соседними шагами. Секция без единого шага остаётся пустым местом, даже если заголовок блока
// заполнен.
$steps = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));

    if ($title === '') {
        continue;
    }

    $steps[] = $title;
}

$anchor = (string) $section['anchor'];
?>
<?php if ($steps !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <?php foreach ($steps as $index => $step): ?>
                    <?php if ($index > 0): ?>
                        <span class="text-ink/50" aria-hidden="true">→</span>
                    <?php endif; ?>
                    <?php if ($index === 0): ?>
                        <span class="rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream"><?= e($step) ?></span>
                    <?php else: ?>
                        <span class="rounded-lg border border-ink/10 bg-ink/5 px-4 py-2 text-sm font-medium text-ink"><?= e($step) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
