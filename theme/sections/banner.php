<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Баннер: картинка на подложке, поверх неё градиент от цвета бумаги — текст остаётся читаемым
// на любом снимке. Карточки «до/после» живут в items: note — надпись («До», «После»), text —
// содержание. Карточка без текста не печатается: пустая плашка «до/после» ничего не сообщает.
// Примечание секции — сноска под карточками и кнопками (условия акции).
$cards = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $text = trim((string) ($row['text'] ?? ''));

    if ($text === '') {
        continue;
    }

    $cards[] = [
        'note' => trim((string) ($row['note'] ?? '')),
        'text' => $text,
    ];
}

$buttons = pagesButtonsOf($section['buttons'] ?? null);
$anchor = (string) $section['anchor'];
$image = trim((string) $section['image']);
$note = trim((string) $section['note']);
$hasContent = (string) $section['badge'] !== '' || (string) $section['title'] !== ''
    || pagesParagraphs((string) $section['lead_text']) !== [] || $cards !== []
    || $buttons !== [] || $image !== '';
?>
<?php if ($hasContent): ?>
    <section class="relative overflow-hidden py-20"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <?php if ($image !== ''): ?>
            <img class="absolute inset-0 size-full object-cover" src="<?= e($image) ?>" alt="<?= e((string) $section['image_alt']) ?>">
            <div class="absolute inset-0 bg-linear-to-r from-paper via-paper/85 to-paper/40"></div>
        <?php endif; ?>
        <div class="relative mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <?php if ($cards !== []): ?>
                <div class="mt-8 grid gap-4 sm:grid-cols-2">
                    <?php foreach ($cards as $index => $card): ?>
                        <?php
                        // Класс плашки собирается в переменную: PHP-блок внутри class читается
                        // стражем стилей именем класса («0», «if»), а e() печатает готовую строку.
                        $cardShell = 'rounded-xl border bg-cream/90 p-5 ' . ($index === 0 ? 'border-bad/30' : 'border-good/40');
                        $labelTone = 'text-xs font-semibold uppercase ' . ($index === 0 ? 'text-bad' : 'text-good');
                        ?>
                        <div class="<?= e($cardShell) ?>">
                            <?php if ($card['note'] !== ''): ?>
                                <p class="<?= e($labelTone) ?>"><?= e($card['note']) ?></p>
                            <?php endif; ?>
                            <p class="mt-2 text-lg"><?= e($card['text']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($buttons !== []): ?>
                <p class="mt-8 flex flex-wrap gap-3">
                    <?php foreach ($buttons as $button): ?>
                        <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ($note !== ''): ?>
                <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
