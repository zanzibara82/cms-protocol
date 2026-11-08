<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Соседние места страницы: строка items — одна ссылка. href — адрес, title — заголовок ссылки,
// text — пояснение, зачем туда идти. Строка без адреса ссылкой не становится, а «смотрите также»
// без ссылки — обрывок, поэтому такая строка не печатается вовсе (вместе с пояснением). Секция
// без единой ссылки остаётся пустым местом, даже если заголовок блока заполнен.
$links = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $href = trim((string) ($row['href'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));

    if ($href === '' || $title === '') {
        continue;
    }

    $links[] = [
        'href' => $href,
        'title' => $title,
        'text' => trim((string) ($row['text'] ?? '')),
    ];
}

$anchor = (string) $section['anchor'];
?>
<?php if ($links !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2">
                <?php foreach ($links as $link): ?>
                    <div class="rounded-xl border border-ink/10 bg-cream p-6">
                        <a class="inline-flex min-h-[44px] items-center font-semibold hover:underline" href="<?= e($link['href']) ?>"><?= e($link['title']) ?></a>
                        <?php if ($link['text'] !== ''): ?>
                            <p class="mt-2 text-ink/80"><?= e($link['text']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
