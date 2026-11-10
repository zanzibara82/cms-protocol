<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Карусель: строка items — один слайд. image — картинка (подпись из title служит её описанием),
// title — заголовок, text — текст, note — надпись над текстом. Слайд без картинки не печатается:
// карусель листает картинки, а пустая плашка подписи ничего не показывает. Переключение держит
// горизонтальная прокрутка со snap-точками — публичные страницы живут без собственного скрипта.
$slides = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $image = trim((string) ($row['image'] ?? ''));

    if ($image === '') {
        continue;
    }

    $slides[] = [
        'image' => $image,
        'title' => trim((string) ($row['title'] ?? '')),
        'text' => trim((string) ($row['text'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
?>
<?php if ($slides !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-4" role="region" aria-label="Слайды">
                <?php foreach ($slides as $slide): ?>
                    <figure class="w-4/5 shrink-0 snap-center rounded-xl border border-ink/10 bg-cream p-6 sm:w-1/2 lg:w-1/3">
                        <img class="mb-4 h-40 w-full rounded-lg object-cover" src="<?= e($slide['image']) ?>" alt="<?= e($slide['title']) ?>">
                        <?php if ($slide['title'] !== ''): ?>
                            <h3 class="text-lg font-bold"><?= e($slide['title']) ?></h3>
                        <?php endif; ?>
                        <?php if ($slide['text'] !== ''): ?>
                            <p class="mt-2 text-ink/70"><?= e($slide['text']) ?></p>
                        <?php endif; ?>
                        <?php if ($slide['note'] !== ''): ?>
                            <p class="mt-3 text-sm font-semibold uppercase tracking-wide text-accent"><?= e($slide['note']) ?></p>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
