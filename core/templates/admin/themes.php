<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array<string, mixed>> $themes */
/** @var list<array{slug: string, error: string}> $broken */
/** @var string $active */

// Каталог тем: карточка на тему, кнопка активации у неактивной. Встроенная тема `theme/` — отдельная
// карточка с пустым slug: пустое значение в настройке `active_theme` означает именно её. Классы
// зон нажатия стоят литералами в атрибутах (страж TapTargetTest читает литералы, а не переменные).

$card = 'rounded-lg border border-ink/10 bg-white p-4';
$button = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 text-sm font-medium text-white hover:opacity-90';
$buttonQuiet = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 text-sm hover:bg-ink/5';

$cards = '';

// Встроенная тема: всегда доступна, поэтому её карточка стоит первой — это состояние «по умолчанию».
$builtinActive = $active === '';
$builtinState = $builtinActive
    ? '<span class="rounded-full bg-good/10 px-2 py-1 text-xs font-medium text-good">Активная</span>'
    : '<span class="rounded-full bg-ink/5 px-2 py-1 text-xs text-ink/60">Доступна</span>';
$builtinAction = $builtinActive
    ? ''
    : '<form method="post" action="/admin/themes">' . csrfField()
        . '<input type="hidden" name="op" value="deactivate">'
        . '<button class="' . $button . '" type="submit">Сделать активной</button></form>';

$cards .= '<article class="' . $card . '">'
    . '<div class="flex flex-wrap items-start justify-between gap-3">'
    . '<div><h2 class="text-base font-semibold">Встроенная тема</h2>'
    . '<p class="mt-1 text-sm text-ink/60">Каталог <code>theme/</code>. Работает без установки и всегда доступна как запасной вариант.</p></div>'
    . $builtinState . '</div>'
    . ($builtinAction === '' ? '' : '<div class="mt-3">' . $builtinAction . '</div>')
    . '</article>';

foreach ($themes as $theme) {
    $slug = (string) ($theme['slug'] ?? '');
    $name = (string) ($theme['name'] ?? $slug);
    $version = (string) ($theme['version'] ?? '');
    $description = (string) ($theme['description'] ?? '');
    $author = (string) ($theme['author'] ?? '');
    $preview = (string) ($theme['preview'] ?? '');
    $isActive = $active === $slug;

    $state = $isActive
        ? '<span class="rounded-full bg-good/10 px-2 py-1 text-xs font-medium text-good">Активная</span>'
        : '<span class="rounded-full bg-ink/5 px-2 py-1 text-xs text-ink/60">Установлена</span>';

    $meta = 'Версия ' . e($version);
    if ($author !== '') {
        $meta .= ' · ' . e($author);
    }

    $action = $isActive
        ? ''
        : '<form method="post" action="/admin/themes">' . csrfField()
            . '<input type="hidden" name="op" value="activate">'
            . '<input type="hidden" name="slug" value="' . e($slug) . '">'
            . '<button class="' . $button . '" type="submit">Активировать</button></form>';

    $previewHtml = '';
    if ($preview !== '') {
        // Скриншот темы — обычная картинка из её каталога: путь задаёт манифест, значение экранируется.
        $previewHtml = '<img class="mb-3 h-24 w-full rounded-lg border border-ink/10 object-cover" src="' . e($preview) . '" alt="Скриншот темы ' . e($name) . '" loading="lazy">';
    }

    $cards .= '<article class="' . $card . '">' . $previewHtml
        . '<div class="flex flex-wrap items-start justify-between gap-3">'
        . '<div><h2 class="text-base font-semibold">' . e($name) . '</h2>'
        . '<p class="mt-1 text-xs text-ink/50">' . $meta . '</p>'
        . ($description === '' ? '' : '<p class="mt-2 text-sm text-ink/70">' . e($description) . '</p>')
        . '</div>' . $state . '</div>'
        . ($action === '' ? '' : '<div class="mt-3">' . $action . '</div>')
        . '</article>';
}

// Испорченные темы: причина показывается рядом с именем каталога, а не только в журнале — иначе
// человек видит, что «тема не появилась», и не знает, что править.
$brokenHtml = '';
if ($broken !== []) {
    $items = '';
    foreach ($broken as $entry) {
        $items .= '<li class="text-sm"><strong>' . e((string) $entry['slug']) . '</strong> — '
            . e((string) $entry['error']) . '</li>';
    }

    $brokenHtml = '<section class="rounded-lg border border-bad/30 bg-bad/5 p-4">'
        . '<h2 class="text-base font-semibold">Темы с ошибками</h2>'
        . '<p class="mt-1 text-sm text-ink/70">Эти каталоги не попали в каталог: исправьте манифест или удалите каталог.</p>'
        . '<ul class="mt-2 list-inside list-disc space-y-1">' . $items . '</ul>'
        . '</section>';
}

$empty = $themes === []
    ? '<p class="text-sm text-ink/60">Собственных тем пока нет. Положите каталог с <code>theme.json</code> и <code>layout.php</code> в <code>themes/</code> — он появится здесь.</p>'
    : '';

$help = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Как устроена тема</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Каталог темы содержит манифест <code>theme.json</code> (slug, название, версия, цвета, макеты) и каркас <code>layout.php</code>. '
    . 'Остальные файлы тема перекрывает по обычной иерархии шаблонов: её <code>page.php</code>, <code>partials/</code> и <code>sections/</code> имеют приоритет над встроенными.</p>'
    . '<p class="mt-2 text-sm text-ink/60">PHP темы не выполняется в редакторе: активация — это выбор каталога, а не запуск загруженного кода.</p>'
    . '</section>';

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

// Обмен оформлением: выгрузка файла и ввоз файла. Формат JSON, а не ZIP: распаковка чужого
// архива — это Zip Slip, символические ссылки и права файлов, а перенос палитры файлов не
// требует. Пользовательский PHP в файл не входит: тема — код на сервере, и ввозить код через
// форму нельзя.
$transfer = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="text-base font-semibold">Обмен оформлением</h2>'
    . '<p class="mt-1 text-sm text-ink/70">Файл несёт манифест, палитру и размеры шрифтов — то, '
    . 'что программа умеет применить списком. Код темы и файлы шаблонов в него не входят: '
    . 'их переносят копированием каталога на сервере.</p>'
    . '<div class="mt-3 grid gap-3 sm:grid-cols-2">'
    . '<form class="rounded-lg border border-ink/10 p-3" method="post" action="/admin/themes">' . csrfField()
    . '<input type="hidden" name="op" value="export">'
    . '<input type="hidden" name="slug" value="' . e($active) . '">'
    . '<p class="text-sm font-medium">Выгрузить оформление</p>'
    . '<p class="mt-1 text-xs text-ink/50">Активная тема: ' . ($active === '' ? 'встроенная' : e($active)) . '</p>'
    . '<button class="mt-2 inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 text-sm hover:bg-ink/5" type="submit">Скачать JSON</button>'
    . '</form>'
    . '<form class="rounded-lg border border-ink/10 p-3" method="post" action="/admin/themes" enctype="multipart/form-data">' . csrfField()
    . '<input type="hidden" name="op" value="import">'
    . '<p class="text-sm font-medium">Ввезти оформление</p>'
    . '<div class="mt-1">' . partial('partials/file-picker', [
        'name' => '',
        'fileKey' => 'theme_file',
        'label' => 'Файл JSON',
        'hint' => 'Файл, выгруженный с другого сайта. Предел — 256 КБ.',
        'accept' => '.json,application/json',
        'buttonTitle' => 'Выбрать файл оформления',
    ]) . '</div>'
    . '<button class="mt-2 inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 text-sm font-medium text-cream hover:opacity-90" type="submit">Применить файл</button>'
    . '</form>'
    . '</div></section>';

/* @safe html */ echo adminShell('Темы', $alert . $brokenHtml . $empty . '<div class="grid gap-3 sm:grid-cols-2">' . $cards . '</div>' . $transfer . $help, $notice);
