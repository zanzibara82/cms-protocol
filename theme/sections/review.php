<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Отзывы: строка items — один отзыв. text — сам отзыв, title — имя, note — кто и откуда,
// image — фото. Отзыв без текста не печатается: имя без выдержки — обрывок, а не отзыв.
$reviews = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $text = trim((string) ($row['text'] ?? ''));

    if ($text === '') {
        continue;
    }

    $reviews[] = [
        'image' => trim((string) ($row['image'] ?? '')),
        'name' => trim((string) ($row['title'] ?? '')),
        'text' => $text,
        'note' => trim((string) ($row['note'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
?>
<?php if ($reviews !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($reviews as $review): ?>
                    <figure class="rounded-xl border border-ink/10 bg-cream p-6">
                        <?php if ($review['image'] !== ''): ?>
                            <img class="mb-4 h-12 w-12 rounded-full object-cover" src="<?= e($review['image']) ?>" alt="" aria-hidden="true">
                        <?php endif; ?>
                        <blockquote class="text-ink/80"><?= e($review['text']) ?></blockquote>
                        <?php if ($review['name'] !== '' || $review['note'] !== ''): ?>
                            <figcaption class="mt-4 text-sm text-ink/50">
                                <?php if ($review['name'] !== ''): ?>
                                    <span class="font-semibold text-ink"><?= e($review['name']) ?></span>
                                <?php endif; ?>
                                <?php if ($review['note'] !== ''): ?>
                                    <span class="block"><?= e($review['note']) ?></span>
                                <?php endif; ?>
                            </figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
