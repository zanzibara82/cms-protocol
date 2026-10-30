<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Карточки: повторяющиеся items[{image, title, text, href, note}]. У карточки с адресом ссылкой
// становится заголовок; без адреса карточка печатается текстом. Картинка карточки декоративная
// (alt пустой): смысл дают заголовок и текст, картинка только настроение.
$items = pagesItemsOf($section['items'] ?? null);
$anchor = (string) $section['anchor'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <?php if ($items !== []): ?>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($items as $row): ?>
                    <?php
                    $title = trim((string) ($row['title'] ?? ''));
                    $text = trim((string) ($row['text'] ?? ''));
                    $note = trim((string) ($row['note'] ?? ''));
                    $href = trim((string) ($row['href'] ?? ''));
                    $image = trim((string) ($row['image'] ?? ''));
                    ?>
                    <div class="rounded-xl border border-ink/10 bg-cream p-6">
                        <?php if ($image !== ''): ?>
                            <img class="mb-4 h-40 w-full rounded-lg object-cover" src="<?= e($image) ?>" alt="" aria-hidden="true">
                        <?php endif; ?>
                        <?php if ($title !== ''): ?>
                            <?php if ($href !== ''): ?>
                                <a class="inline-flex min-h-[44px] items-center font-semibold hover:underline" href="<?= e($href) ?>"><?= e($title) ?></a>
                            <?php else: ?>
                                <h3 class="font-semibold"><?= e($title) ?></h3>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($text !== ''): ?>
                            <p class="mt-2 text-ink/80"><?= e($text) ?></p>
                        <?php endif; ?>
                        <?php if ($note !== ''): ?>
                            <p class="mt-3 text-sm text-ink/50"><?= e($note) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
