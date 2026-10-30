<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Шаги: нумерованный список items[{title, text}]. Номер ставит цикл, а не человек — при
// перестановке шагов нумерация остаётся верной.
$items = array_values(array_filter(
    pagesItemsOf($section['items'] ?? null),
    static fn (array $row): bool => trim((string) ($row['title'] ?? '')) !== ''
));
$anchor = (string) $section['anchor'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <?php if ($items !== []): ?>
            <ol class="mt-8 space-y-4">
                <?php foreach ($items as $index => $row): ?>
                    <li class="flex gap-4">
                        <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-ink font-semibold text-cream"><?= e((string) ((int) $index + 1)) ?></span>
                        <div>
                            <h3 class="font-semibold"><?= e((string) $row['title']) ?></h3>
                            <?php if (trim((string) ($row['text'] ?? '')) !== ''): ?>
                                <p class="mt-1 text-ink/80"><?= e((string) $row['text']) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>
