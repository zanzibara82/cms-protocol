<?php

declare(strict_types=1);

// Каркас админки — библиотека функций, а не шаблон: resolveTemplate() ищет файлы в apps/*/templates,
// theme/ и core/templates, а каталога admin/ в этой цепочке нет. Экраны админки лежат в
// core/templates/admin/ и вызывают adminShell(), поэтому вкладки и уведомление собраны в одном месте.
const ADMIN_CORE_TABS = [
    ['href' => '/admin', 'label' => 'Сводка'],
    ['href' => '/admin/pages', 'label' => 'Страницы'],
    ['href' => '/admin/apps', 'label' => 'Приложения'],
];

/** @return list<array{href: string, label: string}> */
function adminTabs(): array
{
    $tabs = ADMIN_CORE_TABS;

    foreach (appManifests() as $manifest) {
        if (!isAppActive((string) $manifest['id'])) {
            continue;
        }

        foreach ((array) ($manifest['adminNav'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $href = (string) ($item['href'] ?? '');
            $label = (string) ($item['label'] ?? '');
            if ($href === '' || $label === '') {
                continue;
            }

            $tabs[] = ['href' => $href, 'label' => $label];
        }
    }

    // Фильтр admin_nav (спецификация §5.4): раздел добавляет вкладку, не правя ядро.
    // array_values нужен только после фильтра: раздел мог пересобрать массив с пропуском ключей,
    // а экрану обязана вернуться именно последовательность. $tabs — список всегда, повторный вызов
    // на нём PHPStan помечает как недействующий.
    $filtered = applyFilters('admin_nav', $tabs);

    return is_array($filtered) ? array_values($filtered) : $tabs;
}

function adminNotice(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }

    return '<p class="mb-4 rounded-lg border border-good/30 bg-good/10 px-4 py-3 text-sm text-good" role="status">'
        . e($text) . '</p>';
}

function adminTabsHtml(string $current): string
{
    // Вкладки и кнопка выхода несут min-h-[44px]: глобальное требование проекта — зона нажатия не
    // меньше 44 px, тот же класс, что уже стоит в theme/partials/header.php и на экране входа.
    $items = '';
    foreach (adminTabs() as $tab) {
        $href = (string) $tab['href'];
        $active = $href === $current;
        $class = $active
            ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e((string) $tab['label']) . '</a></li>';
    }

    return '<nav aria-label="Разделы админки"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

// Экраны админки печатаются внутри публичного layout темы: своего HTML-каркаса у ядра нет,
// а шапка, футер и кот остаются общими.
function adminShell(string $heading, string $content, ?string $notice = null): string
{
    return '<div class="space-y-6">'
        . adminNotice($notice)
        . '<header class="flex flex-wrap items-center justify-between gap-3">'
        . '<h1 class="text-2xl font-bold">' . e($heading) . '</h1>'
        . '<form method="post" action="/logout">' . csrfField()
        . '<button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Выйти</button>'
        . '</form></header>'
        . adminTabsHtml(requestPath())
        . '<div class="space-y-4">' . $content . '</div>'
        . '</div>';
}
