<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Картинка и текст вокруг неё: layout выбирает сторону обтекания ('' — картинка слева,
// right — справа, full — во всю ширину над текстом). Ниже sm-ширины обтекание выключается —
// картинка занимает всю ширину, текст продолжается под ней. Абзацы живут в items: title —
// заголовок, text — текст; строка без заголовка и текста не печатается.
$layout = (string) ($section['layout'] ?? '');
$image = trim((string) ($section['image'] ?? ''));
$imageAlt = trim((string) ($section['image_alt'] ?? ''));
$note = trim((string) ($section['note'] ?? ''));
$anchor = (string) $section['anchor'];
$buttons = pagesButtonsOf($section['buttons'] ?? null);

$paragraphs = [];
foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));
    $text = trim((string) ($row['text'] ?? ''));

    if ($title === '' && $text === '') {
        continue;
    }

    $paragraphs[] = ['title' => $title, 'text' => $text];
}

// Класс картинки собирается в переменную: PHP-блок внутри class читается стражем стилей
// именем класса («if», «full»), а e() печатает собранную строку безопасно.
$imageClass = $layout === 'full'
    ? 'mb-6 w-full'
    : 'mb-4 w-full sm:mb-0 sm:w-[42%] ' . ($layout === 'right' ? 'sm:float-right sm:ml-6' : 'sm:float-left sm:mr-6');
?>
<?php if ($image !== '' || $paragraphs !== []): ?>
    <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <div class="mt-8 flow-root">
            <?php if ($image !== ''): ?>
                <img class="aspect-[4/3] w-full rounded-xl border border-ink/10 bg-cream object-cover shadow-sm <?= e($imageClass) ?>" src="<?= e($image) ?>" alt="<?= e($imageAlt) ?>">
            <?php endif; ?>
            <?php if ($paragraphs !== []): ?>
                <div class="space-y-6">
                    <?php foreach ($paragraphs as $paragraph): ?>
                        <?php
                        // Класс абзаца собирается в переменную: PHP-блок внутри class читается
                        // стражем экранирования как сырой вывод $paragraph, а e() печатает
                        // готовую строку безопасно.
                        $paragraphClass = 'text-lg leading-relaxed' . ($paragraph['title'] !== '' ? ' mt-2' : '');
                        ?>
                        <div>
                            <?php if ($paragraph['title'] !== ''): ?>
                                <h3 class="text-xl font-semibold tracking-tight"><?= e($paragraph['title']) ?></h3>
                            <?php endif; ?>
                            <?php if ($paragraph['text'] !== ''): ?>
                                <p class="<?= e($paragraphClass) ?>"><?= e($paragraph['text']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($note !== ''): ?>
            <p class="mt-8 border-l-2 border-accent/30 pl-4 text-sm text-ink/70"><?= e($note) ?></p>
        <?php endif; ?>
        <?php if ($buttons !== []): ?>
            <p class="mt-6 flex flex-wrap gap-3">
                <?php foreach ($buttons as $button): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
