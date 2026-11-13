<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var array<string, mixed> $page */
/** @var list<array{kind: string, name: string}> $kinds */
/** @var list<array<string, mixed>> $sections */
/** @var array{label: string, updated_at: string, section: array<string, mixed>}|null $clipboard */
/** @var string $previewLink */
/** @var list<array{id: string, created_at: string, expires_at: string, revoked_at: string}> $previewTokens */

// Редактор страницы: поля страницы (адрес, статус, noindex) и секции под ними. Удаление —
// отдельная форма: формы не вкладываются друг в друга.
$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$target = '/admin/pages/' . (string) $page['id'];

$statusOptions = '';
foreach (PAGES_STATUSES as $status) {
    $label = $status === PAGES_STATUS_PUBLISHED ? 'Опубликовано' : 'Черновик';
    $selected = (string) $page['status'] === $status ? ' selected' : '';
    $statusOptions .= '<option value="' . e($status) . '"' . $selected . '>' . e($label) . '</option>';
}

$pageForm = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Поля страницы</h2>'
    . '<form class="mt-3 space-y-3" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="save-page">'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block text-sm"><span class="block font-medium">Заголовок</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="title" value="' . e((string) $page['title']) . '" required></label>'
    . '<label class="block text-sm"><span class="block font-medium">Адрес</span>'
    . '<input class="min-h-[44px] ' . e($input) . ' font-mono" type="text" name="slug" value="' . e((string) $page['slug']) . '" required>'
    . '<span class="mt-1.5 block text-ink/60">При смене адреса прежний продолжит работать — переезд запишется автоматически.</span></label>'
    . '</div>'
    . '<label class="block text-sm"><span class="block font-medium">Описание</span>'
    . '<input class="min-h-[44px] ' . e($input) . '" type="text" name="description" value="' . e((string) $page['description']) . '"></label>'
    . '<div class="grid gap-3 sm:grid-cols-2">'
    . '<label class="block text-sm"><span class="block font-medium">Статус</span>'
    . '<select class="min-h-[44px] ' . e($input) . '" name="status">' . $statusOptions . '</select></label>'
    . adminCheckbox('noindex', 'Скрыть страницу от поисковиков', '1', (int) $page['noindex'] === 1)
    . '</div>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Сохранить страницу</button>'
    . '</form></section>';

$deleteForm = '<section class="rounded-lg border border-bad/30 bg-white p-4">'
    . '<form class="flex flex-wrap items-center justify-between gap-3" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="delete-page">'
    . '<p class="text-sm text-ink/70">Страница уходит в корзину вместе с блоками и историей адресов — восстановить можно оттуда.</p>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Убрать в корзину</button>'
    . '</form></section>';

// Предпросмотр: сырая ссылка показывается один раз сразу после создания, в базе лежит только
// хеш. Список действующих ссылок позволяет отозвать случайно отправленную.
$previewSection = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Предпросмотр по ссылке</h2>'
    . '<p class="mt-1.5 text-sm text-ink/70">Ссылка открывает текущий вид страницы без входа и живёт 7 дней. Прав не даёт: изменить страницу по ней нельзя.</p>';

if ($previewLink !== '') {
    $previewSection .= '<div class="mt-3 rounded-lg border border-good/30 bg-good/10 p-3">'
        . '<p class="text-sm font-medium text-good">Ссылка создана — скопируйте её сейчас, потом она не показывается.</p>'
        . '<label class="mt-2 block text-sm"><span class="font-medium">Адрес предпросмотра</span>'
        . '<input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 font-mono text-xs" type="text" readonly value="' . e($previewLink) . '"></label>'
        . '<p class="mt-2"><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" href="' . e($previewLink) . '" target="_blank" rel="noopener noreferrer">Открыть предпросмотр</a></p>'
        . '</div>';
}

$previewSection .= '<form class="mt-3" method="post" action="' . e($target) . '">' . csrfField()
    . '<input type="hidden" name="op" value="preview-page">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit">Создать ссылку предпросмотра</button>'
    . '</form>';

if ($previewTokens !== []) {
    $tokenRows = '';
    $nowStamp = utcStamp(nowUtc());

    foreach ($previewTokens as $token) {
        $active = (string) $token['revoked_at'] === '' && (string) $token['expires_at'] > $nowStamp;
        $state = (string) $token['revoked_at'] !== ''
            ? 'отозвана'
            : ($active ? 'действует до ' . formatRuDateTime(fromDb((string) $token['expires_at'])) : 'истекла');

        $tokenRows .= '<li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/10 py-1">'
            . '<span class="text-sm text-ink/70">Создана ' . e(formatRuDateTime(fromDb((string) $token['created_at']))) . ' · ' . e($state) . '</span>';

        if ($active) {
            $tokenRows .= '<form class="inline" method="post" action="' . e($target) . '">' . csrfField()
                . '<input type="hidden" name="op" value="preview-revoke">'
                . '<input type="hidden" name="token" value="' . e((string) $token['id']) . '">'
                . '<button class="inline-flex min-h-[44px] items-center rounded-lg px-3 py-1.5 text-sm text-bad hover:bg-bad/10" type="submit">Отозвать</button>'
                . '</form>';
        }

        $tokenRows .= '</li>';
    }

    $previewSection .= '<div class="mt-3"><h3 class="text-sm font-medium">Созданные ссылки</h3>'
        . '<ul class="mt-1">' . $tokenRows . '</ul></div>';
}

$previewSection .= '</section>';

// Буфер обмена: скопированная секция лежит в базе за пользователем и переживает перезапуск.
// Вставить её можно на любую страницу — форма ведёт на текущий редактор.
$clipboardSection = '';

if ($clipboard !== null) {
    $clipboardSection = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
        . '<h2 class="font-semibold">Буфер обмена</h2>'
        . '<p class="mt-1.5 text-sm text-ink/70">Скопирована секция «' . e((string) $clipboard['label']) . '» ('
        . e(formatRuDateTime(fromDb((string) $clipboard['updated_at']))) . ').</p>'
        . '<form class="mt-3" method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="op" value="paste-section">'
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Вставить секцию на эту страницу</button>'
        . '</form></section>';
}

$sectionsCard = '<section class="rounded-lg border border-ink/10 bg-white p-4">'
    . '<h2 class="font-semibold">Блоки страницы</h2>'
    . '<p class="mt-1.5 text-sm text-ink/70">Порядок на странице — как в этом списке, сверху вниз.</p>'
    . '</section>'
    . partial('admin/pages-sections', ['sections' => $sections, 'target' => $target, 'kinds' => $kinds]);

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Страница «' . (string) $page['title'] . '»', $alert . $pageForm . $previewSection . $clipboardSection . $sectionsCard . $deleteForm, $notice);
