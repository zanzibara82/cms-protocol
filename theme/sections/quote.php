<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Цитаты: строка items — сама выдержка (text), автор (title) и подпись к нему (note).
// Цитата без текста не печатается: автор без выдержки — обрывок, а не отзыв. Секция без единой
// полной цитаты остаётся пустым местом, даже если заголовок блока заполнен.
$quotes = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $text = trim((string) ($row['text'] ?? ''));

    if ($text === '') {
        continue;
    }

    $quotes[] = [
        'text' => $text,
        'author' => trim((string) ($row['title'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
        'image' => trim((string) ($row['image'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
?>
<?php if ($quotes !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-6 space-y-6">
                <?php foreach ($quotes as $quote): ?>
                    <figure class="rounded-xl border border-ink/10 bg-cream p-6">
                        <?php if ($quote['image'] !== ''): ?>
                            <img class="mb-4 h-40 w-full rounded-lg object-cover" src="<?= e($quote['image']) ?>" alt="" aria-hidden="true">
                        <?php endif; ?>
                        <blockquote class="text-xl italic text-ink/80"><?= e($quote['text']) ?></blockquote>
                        <?php if ($quote['author'] !== '' || $quote['note'] !== ''): ?>
                            <figcaption class="mt-3 text-sm text-ink/50">
                                <?php if ($quote['author'] !== ''): ?>
                                    <span class="font-semibold text-ink"><?= e($quote['author']) ?></span>
                                <?php endif; ?>
                                <?php if ($quote['note'] !== ''): ?>
                                    <span>— <?= e($quote['note']) ?></span>
                                <?php endif; ?>
                            </figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
