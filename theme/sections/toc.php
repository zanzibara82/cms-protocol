<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */
/** @var list<array{anchor: string, title: string}> $entries */

// Содержание страницы: пункты собраны из секций, которые напечатались (pagesSectionsHtml), —
// якорь и заголовок места дают ссылку. Содержание из одного пункта не печатается: список из
// одной строки ничего не даёт человеку, а место на экране занимает.
$anchor = (string) $section['anchor'];
?>
<?php if (count($entries) >= 2): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <nav class="mt-6" aria-label="Содержание">
                <ol class="space-y-3">
                    <?php foreach ($entries as $index => $entry): ?>
                        <li class="flex gap-3">
                            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-ink font-semibold text-cream"><?= e((string) ((int) $index + 1)) ?></span>
                            <a class="inline-flex min-h-[44px] items-center font-medium text-ink hover:underline" href="#<?= e($entry['anchor']) ?>"><?= e($entry['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
        </div>
    </section>
<?php endif; ?>
