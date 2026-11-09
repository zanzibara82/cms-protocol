<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Видео: строка items — один ролик. video — путь к собственному файлу сайта (страница живёт
// под строгой CSP: чужие площадки и не-видео файлы плеером не становятся), image — обложка до
// старта, title и text — подпись под плеером. Ролик без опознанного видеофайла не печатается;
// секция без единого ролика остаётся пустым местом.
$clips = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $src = pagesVideoFile($row['video'] ?? null);

    if ($src === '') {
        continue;
    }

    $captionParts = array_values(array_filter([
        trim((string) ($row['title'] ?? '')),
        trim((string) ($row['text'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));

    $clips[] = [
        'src' => $src,
        'poster' => trim((string) ($row['image'] ?? '')),
        'caption' => implode(' — ', $captionParts),
    ];
}

$buttons = pagesButtonsOf($section['buttons'] ?? null);
$anchor = (string) $section['anchor'];
// Класс сетки собирается в переменную: PHP-блок внутри class читается стражем стилей именем
// класса («if»), а e() печатает собранную строку. Один ролик — одна колонка, несколько — две.
$gridClass = 'mt-8 grid gap-6' . (count($clips) > 1 ? ' lg:grid-cols-2' : '');
?>
<?php if ($clips !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="<?= e($gridClass) ?>">
                <?php foreach ($clips as $clip): ?>
                    <figure>
                        <video class="w-full rounded-xl" controls preload="metadata" src="<?= e($clip['src']) ?>"<?php if ($clip['poster'] !== ''): ?> poster="<?= e($clip['poster']) ?>"<?php endif; ?>></video>
                        <?php if ($clip['caption'] !== ''): ?>
                            <figcaption class="mt-2 text-sm text-ink/50"><?= e($clip['caption']) ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
            <?php if ($buttons !== []): ?>
                <p class="mt-6 flex flex-wrap gap-3">
                    <?php foreach ($buttons as $button): ?>
                        <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
