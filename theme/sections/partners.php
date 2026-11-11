<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Партнёры: строка items — один партнёр. image — логотип, title — название, href — адрес сайта,
// note — подпись (город, направление). Название обязательно: логотип без подписи ничего не
// сообщает, а описание картинки осталось бы пустым. Без картинки печатается монограмма из первой
// буквы названия. Внешняя ссылка открывается в новой вкладке и получает парный rel; внутренняя
// ведёт в том же окне. Примечание секции — сноска под сеткой.
$partners = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));

    if ($title === '') {
        continue;
    }

    $partners[] = [
        'title' => $title,
        'image' => trim((string) ($row['image'] ?? '')),
        'href' => trim((string) ($row['href'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
$note = trim((string) $section['note']);
?>
<?php if ($partners !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($partners as $partner): ?>
                    <?php $external = preg_match('~^https?://~i', $partner['href']) === 1; ?>
                    <div class="rounded-xl border border-ink/10 bg-cream p-5">
                        <?php if ($partner['href'] !== ''): ?>
                            <a class="flex min-h-[44px] items-center gap-4 hover:underline" href="<?= e($partner['href']) ?>"<?php if ($external): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                                <?php if ($partner['image'] !== ''): ?>
                                    <img class="h-12 w-24 shrink-0 rounded-lg bg-white object-contain p-1" src="<?= e($partner['image']) ?>" alt="<?= e($partner['title']) ?>">
                                <?php else: ?>
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-ink/5 text-lg font-bold text-ink/70" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($partner['title'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="font-semibold"><?= e($partner['title']) ?></span>
                            </a>
                        <?php else: ?>
                            <div class="flex min-h-[44px] items-center gap-4">
                                <?php if ($partner['image'] !== ''): ?>
                                    <img class="h-12 w-24 shrink-0 rounded-lg bg-white object-contain p-1" src="<?= e($partner['image']) ?>" alt="<?= e($partner['title']) ?>">
                                <?php else: ?>
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-ink/5 text-lg font-bold text-ink/70" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($partner['title'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="font-semibold"><?= e($partner['title']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($partner['note'] !== ''): ?>
                            <p class="mt-3 text-sm text-ink/50"><?= e($partner['note']) ?></p>
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
