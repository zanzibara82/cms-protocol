<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Текст и картинка: слева шапка с пунктами списка и кнопками, справа картинка. Пункт без
// заголовка и текста не печатается (пустая строка формы — место для новой, а не строка списка);
// без картинки блок остаётся одной колонкой — пустая рамка обещала бы картинку, которой нет.
$items = pagesItemsOf($section['items'] ?? null);
$buttons = pagesButtonsOf($section['buttons'] ?? null);
$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
$image = (string) $section['image'];
// Класс колонок собирается в переменную: PHP-блок внутри атрибута class читается стражем
// стилей как имя класса («if»), а e() печатает собранную строку безопасно.
$gridClass = $image !== '' ? 'grid gap-6 sm:grid-cols-2' : '';
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <div class="<?= e($gridClass) ?>">
            <div>
                <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
                <?php if ($items !== []): ?>
                    <ul class="mt-6 space-y-3">
                        <?php foreach ($items as $row): ?>
                            <?php
                            $itemTitle = trim((string) ($row['title'] ?? ''));
                            $itemText = trim((string) ($row['text'] ?? ''));
                            ?>
                            <?php if ($itemTitle !== '' || $itemText !== ''): ?>
                                <li>
                                    <?php if ($itemTitle !== ''): ?>
                                        <p class="font-semibold"><?= e($itemTitle) ?></p>
                                    <?php endif; ?>
                                    <?php if ($itemText !== ''): ?>
                                        <p class="text-ink/80"><?= e($itemText) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($buttons !== []): ?>
                    <p class="mt-6 flex flex-wrap gap-3">
                        <?php foreach ($buttons as $button): ?>
                            <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="<?= e($button['href']) ?>"<?php if ($button['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($button['label']) ?></a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <?php if ($note !== ''): ?>
                    <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($image !== ''): ?>
                <div>
                    <img class="w-full rounded-xl" src="<?= e($image) ?>" alt="<?= e((string) $section['image_alt']) ?>">
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
