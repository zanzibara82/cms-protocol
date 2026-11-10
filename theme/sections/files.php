<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Материалы по ссылке: файлы лежат в файловой системе сайта, блок сам ничего не хранит. Строка
// items — один файл: title — название, href — адрес (пустой адрес оставляет название текстом),
// text — описание, note — примечание, badge — своя плашка; без badge плашка собирается из
// расширения адреса (PDF, DOCX), без адреса — «Файл». Строка без названия не печатается —
// пустая ссылка или плашка без подписи ничего не сообщают. layout «cards» печатает карточки
// сеткой, пустой — список.
$layout = (string) ($section['layout'] ?? '');
$note = trim((string) ($section['note'] ?? ''));
$anchor = (string) $section['anchor'];

$files = [];
foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));

    if ($title === '') {
        continue;
    }

    $href = trim((string) ($row['href'] ?? ''));
    $badge = trim((string) ($row['badge'] ?? ''));
    $chip = $badge !== '' ? mb_substr($badge, 0, 12) : 'Файл';

    if ($badge === '' && $href !== '') {
        $path = (string) parse_url($href, PHP_URL_PATH);
        if (preg_match('/\.([a-z0-9]{1,6})$/i', $path, $match) === 1) {
            $chip = mb_strtoupper((string) $match[1]);
        }
    }

    $files[] = [
        'href' => $href,
        'title' => $title,
        'text' => trim((string) ($row['text'] ?? '')),
        'note' => trim((string) ($row['note'] ?? '')),
        'chip' => $chip,
    ];
}

// Класс списка собирается в переменную: PHP-блок внутри class читается стражем стилей именем
// класса («cards»), а e() печатает собранную строку безопасно.
$listClass = $layout === 'cards'
    ? 'mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3'
    : 'mt-8 divide-y rounded-xl border border-ink/10 bg-white';
?>
<?php if ($files !== []): ?>
    <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <ul class="<?= e($listClass) ?>">
            <?php foreach ($files as $file): ?>
                <?php if ($layout === 'cards'): ?>
                    <li class="rounded-xl border border-ink/10 bg-white p-5">
                        <span class="shrink-0 rounded-lg bg-accent/10 px-2 py-1 text-xs font-semibold text-accent tabular-nums"><?= e($file['chip']) ?></span>
                        <div class="mt-3">
                            <?php if ($file['href'] !== ''): ?>
                                <a class="inline-flex min-h-[44px] items-center break-words font-medium text-accent underline-offset-4 hover:underline" href="<?= e($file['href']) ?>"><?= e($file['title']) ?></a>
                            <?php else: ?>
                                <span class="block break-words font-medium"><?= e($file['title']) ?></span>
                            <?php endif; ?>
                            <?php if ($file['text'] !== ''): ?>
                                <p class="mt-1 text-sm text-ink/70"><?= e($file['text']) ?></p>
                            <?php endif; ?>
                            <?php if ($file['note'] !== ''): ?>
                                <p class="mt-1 text-xs text-ink/50 tabular-nums"><?= e($file['note']) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php else: ?>
                    <li class="flex items-start gap-3 p-4 sm:gap-4 sm:p-5">
                        <?php // Плашка и название стоят в коробках одной высоты: у ссылки зона нажатия
                        // 44 px, и без такой же коробки у плашки её центр оказывается выше центра
                        // названия. Строка без адреса печатается текстом 24 px и совпадает сама.?>
                        <span class="flex min-h-[44px] shrink-0 items-center">
                            <span class="rounded-lg bg-accent/10 px-2 py-1 text-xs font-semibold text-accent tabular-nums"><?= e($file['chip']) ?></span>
                        </span>
                        <div class="min-w-0">
                            <?php if ($file['href'] !== ''): ?>
                                <a class="inline-flex min-h-[44px] items-center break-words font-medium text-accent underline-offset-4 hover:underline" href="<?= e($file['href']) ?>"><?= e($file['title']) ?></a>
                            <?php else: ?>
                                <?php // Название без адреса стоит в коробке той же высоты, что ссылка выше:
                                // иначе плашка сбоку оказывается ниже первой строки названия.?>
                                <span class="flex min-h-[44px] items-center break-words font-medium"><?= e($file['title']) ?></span>
                            <?php endif; ?>
                            <?php if ($file['text'] !== ''): ?>
                                <p class="mt-1 text-sm text-ink/70"><?= e($file['text']) ?></p>
                            <?php endif; ?>
                            <?php if ($file['note'] !== ''): ?>
                                <p class="mt-1 text-xs text-ink/50 tabular-nums"><?= e($file['note']) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
        <?php if ($note !== ''): ?>
            <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>
