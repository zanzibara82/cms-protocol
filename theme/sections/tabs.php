<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Вкладки: строка items — одна вкладка. title — название, text — содержимое (абзацы через пустую
// строку). Публичные страницы сайта живут без собственного скрипта, поэтому переключение держит
// сам браузер: взаимоисключающие details одной группы (атрибут name) раскрывают ровно одну
// вкладку, первая открыта — человек видит содержимое сразу, а остальные раскрывает сам. Вкладка
// без названия не печатается: раскрытие без подписи не выбрать. Секция без единой вкладки
// остаётся пустым местом, даже если заголовок блока заполнен.
$tabs = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));

    if ($title === '') {
        continue;
    }

    $tabs[] = ['title' => $title, 'text' => (string) ($row['text'] ?? '')];
}

// Группа вкладок называется по ключу секции: два блока вкладок на одной странице не должны
// закрывать друг друга — name общий только внутри блока.
$secKey = (string) ($section['sec_key'] ?? '');
$group = 'tabs-' . ($secKey !== '' ? $secKey : 'blok');
$anchor = (string) $section['anchor'];
?>
<?php if ($tabs !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 space-y-3">
                <?php foreach ($tabs as $index => $tab): ?>
                    <details class="rounded-xl border border-ink/10 bg-cream p-5" name="<?= e($group) ?>"<?php if ($index === 0): ?> open<?php endif; ?>>
                        <summary class="min-h-[44px] cursor-pointer font-semibold"><?= e($tab['title']) ?></summary>
                        <?php foreach (pagesParagraphs($tab['text']) as $paragraph): ?>
                            <p class="mt-3 text-ink/80"><?= e($paragraph) ?></p>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
