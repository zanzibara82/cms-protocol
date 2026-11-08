<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Галерея: строка items — один кадр. image — сама картинка, title — подпись под кадром и описание
// для скринридера, note — место или дата съёмки. Кадр без картинки не печатается: пустая рамка
// обещала бы фотографию, которой нет (то же правило, что у split и quote). Примечание секции —
// подпись под галереей: где и когда снято. Секция без единого кадра остаётся пустым местом.
$frames = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $image = trim((string) ($row['image'] ?? ''));

    if ($image === '') {
        continue;
    }

    $frames[] = [
        'image' => $image,
        'title' => trim((string) ($row['title'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
?>
<?php if ($frames !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($frames as $frame): ?>
                    <figure class="rounded-xl border border-ink/10 bg-cream p-6">
                        <img class="mb-4 h-40 w-full rounded-lg object-cover" src="<?= e($frame['image']) ?>" alt="<?= e($frame['title']) ?>">
                        <?php if ($frame['title'] !== '' || $frame['note'] !== ''): ?>
                            <figcaption class="text-sm text-ink/50">
                                <?php if ($frame['title'] !== ''): ?>
                                    <span class="font-semibold text-ink"><?= e($frame['title']) ?></span>
                                <?php endif; ?>
                                <?php if ($frame['note'] !== ''): ?>
                                    <span class="block"><?= e($frame['note']) ?></span>
                                <?php endif; ?>
                            </figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
            <?php if ($note !== ''): ?>
                <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
