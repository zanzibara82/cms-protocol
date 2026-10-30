<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Частые вопросы: пары вопрос-ответ печатаются человеку, разметка FAQPage уезжает в <head> через
// pagesHeadFragment() — render() печатает хук page_head после сборки контента, поэтому подписка
// из шаблона секции успевает встать. Вопрос без ответа печатается, но в разметку не идёт.
$items = array_values(array_filter(
    pagesItemsOf($section['items'] ?? null),
    static fn (array $row): bool => trim((string) ($row['title'] ?? '')) !== ''
));
$anchor = (string) $section['anchor'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <?php if ($items !== []): ?>
            <?php pagesHeadFragment(pagesFaqLd($items)); ?>
            <div class="mt-8 space-y-6">
                <?php foreach ($items as $row): ?>
                    <div>
                        <h3 class="font-semibold"><?= e((string) $row['title']) ?></h3>
                        <?php if (trim((string) ($row['text'] ?? '')) !== ''): ?>
                            <p class="mt-2 text-ink/80"><?= e((string) $row['text']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
