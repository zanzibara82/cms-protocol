<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Тарифы: строка items — один тариф. title — название, note — цена и период, text — что входит,
// href — адрес кнопки тарифа. Тариф без названия не печатается: цена без названия — цифра на
// витрине без товара. Примечание секции — сноска под сеткой тарифов (условия, оговорки).
// Секция без единого тарифа остаётся пустым местом, даже если заголовок блока заполнен.
$plans = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));

    if ($title === '') {
        continue;
    }

    $plans[] = [
        'title' => $title,
        'text' => trim((string) ($row['text'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
        'href' => trim((string) ($row['href'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
?>
<?php if ($plans !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($plans as $plan): ?>
                    <div class="rounded-xl border border-ink/10 bg-cream p-6">
                        <h3 class="font-semibold"><?= e($plan['title']) ?></h3>
                        <?php if ($plan['note'] !== ''): ?>
                            <p class="mt-2 text-2xl font-bold"><?= e($plan['note']) ?></p>
                        <?php endif; ?>
                        <?php if ($plan['text'] !== ''): ?>
                            <p class="mt-3 text-ink/80"><?= e($plan['text']) ?></p>
                        <?php endif; ?>
                        <?php if ($plan['href'] !== ''): ?>
                            <p class="mt-4">
                                <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($plan['href']) ?>">Выбрать</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($note !== ''): ?>
                <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
