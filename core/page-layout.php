<?php

declare(strict_types=1);

// Макеты страницы и ширина секции. До этой работы каркас был один на весь сайт: `theme/layout.php`
// с шапкой, подвалом и контейнером `max-w-5xl`. Лендинг без шапки, страница с сайдбаром и секция
// во всю ширину были невозможны — каждая страница получала ту же обвязку.
//
// Здесь живут только ПРАВИЛА: перечни значений, пределы и разбор в флаги. Классы разметки лежат в
// теме (`theme/layout.php` и `theme/partials/section-open.php`): сборка стилей читает только
// объявленные каталоги разметки, и класс, написанный в ядре, не попал бы в артефакт — страница
// потеряла бы оформление, а страж StyleSourceTest покраснел бы на просвете.
//
// Пользовательский PHP в макетах не выполняется: макет выбирает из готовых описаний.

// Перечень макетов: стандартный, лендинги, страницы без части обвязки, сайдбары.
// Стандартный идёт первым — он же значение по умолчанию для всех существующих страниц
// (миграция назначает его, а не оставляет пусто).
const PAGE_LAYOUT_DEFAULT = 'default';

/** @var list<string> */
const PAGE_LAYOUTS = [
    'default',
    'landing',
    'blank',
    'no-header',
    'no-footer',
    'wide',
    'sidebar-left',
    'sidebar-right',
    'two-sidebars',
];

// Подписи для селекта в редакторе: человек выбирает «Лендинг», а не «landing».
/** @var array<string, string> */
const PAGE_LAYOUT_NAMES = [
    'default' => 'Стандартная страница',
    'landing' => 'Лендинг без шапки и подвала',
    'blank' => 'Пустой макет (Blank Canvas)',
    'no-header' => 'Без шапки',
    'no-footer' => 'Без подвала',
    'wide' => 'Широкая страница',
    'sidebar-left' => 'Сайдбар слева',
    'sidebar-right' => 'Сайдбар справа',
    'two-sidebars' => 'Две боковые панели',
];

// Ширина контента внутри секции. Standard — прежний контейнер, Wide — шире, Full — во всю
// ширину экрана, Custom — значение человека в пределах ниже.
const PAGE_WIDTH_DEFAULT = 'standard';

/** @var list<string> */
const PAGE_WIDTHS = ['standard', 'wide', 'full', 'custom'];

/** @var array<string, string> */
const PAGE_WIDTH_NAMES = [
    'standard' => 'Стандартная',
    'wide' => 'Широкая',
    'full' => 'Во всю ширину',
    'custom' => 'Своя ширина',
];

// Пределы своей ширины. Нижний — уже него контент не читается, верхний — шире обычного экрана,
// и дальше начинается горизонтальная прокрутка, которую человек не просил.
const PAGE_CONTAINER_CUSTOM_MIN = 320;
const PAGE_CONTAINER_CUSTOM_MAX = 2400;
const PAGE_CONTAINER_CUSTOM_DEFAULT = 1200;

/**
 * План каркаса страницы: что печатать вокруг контента. Неизвестный макет откатывается к
 * стандартному — значение приходит из базы и из формы, и чужая строка не должна давать
 * страницу без шапки или белый экран.
 *
 * @return array{layout: string, header: bool, footer: bool, sidebar: string, blank: bool}
 */
function pageLayoutPlan(mixed $layout): array
{
    $name = is_string($layout) ? trim($layout) : '';
    if (!in_array($name, PAGE_LAYOUTS, true)) {
        $name = PAGE_LAYOUT_DEFAULT;
    }

    // Лендинг и пустой макет снимают шапку и подвал вместе: страница под рекламную кампанию
    // не тянет меню сайта. Остальные макеты снимают только то, что названо в их имени.
    $header = !in_array($name, ['landing', 'blank', 'no-header'], true);
    $footer = !in_array($name, ['landing', 'blank', 'no-footer'], true);

    $sidebar = match ($name) {
        'sidebar-left' => 'left',
        'sidebar-right' => 'right',
        'two-sidebars' => 'both',
        default => 'none',
    };

    return [
        'layout' => $name,
        'header' => $header,
        'footer' => $footer,
        'sidebar' => $sidebar,
        // blank отличает лендинг от «без шапки»: у лендинга нет и внешних отступов каркаса —
        // секции сами решают, чем ограничить содержимое.
        'blank' => in_array($name, ['landing', 'blank'], true),
    ];
}

/**
 * Назначенный макет: имя из перечня либо пустая строка «не назначено».
 *
 * Пустое и неизвестное значение — это «не назначено», а не «показать пусто». Разница
 * существенная: назначение приходит из базы и из формы, и если читать пустое как решение,
 * каждый новый объект (запись, тип) ломал бы обвязку сайта. Неизвестная строка тоже не
 * назначается — тогда решение принимает следующая ступень приоритета.
 */
function pageLayoutAssigned(mixed $raw): string
{
    $name = is_string($raw) ? trim($raw) : '';

    return in_array($name, PAGE_LAYOUTS, true) ? $name : '';
}

/**
 * Макет из настроек типа контента: ключ `layout` в JSON настроек. Схему ради одного поля не
 * меняем — типы контента уже хранят настройки объектом.
 *
 * @param array<string, mixed> $settings
 */
function pageLayoutFromSettings(array $settings): string
{
    return pageLayoutAssigned($settings['layout'] ?? null);
}

/**
 * Итоговый макет по приоритету спецификации §14: объект → тип контента → умолчание.
 *
 * Ступень «категория или контекст» сюда не входит: у типов контента категорий нет, а у страниц
 * контекста нет вовсе. Появится контекст — добавится аргументом, и порядок останется виден в
 * одном месте, а не в трёх ветках маршрутов.
 */
function pageLayoutResolve(mixed $object, mixed $type, mixed $fallback): string
{
    foreach ([$object, $type, $fallback] as $candidate) {
        $assigned = pageLayoutAssigned($candidate);

        if ($assigned !== '') {
            return $assigned;
        }
    }

    return PAGE_LAYOUT_DEFAULT;
}

/**
 * Ключ ширины из формы: чужое значение означает стандартную. Отдельная функция нужна потому,
 * что ширина секции едет и в конструктор, и в шаблон, и обе стороны обязаны понимать одно и то
 * же значение.
 */
function sectionWidthOf(mixed $raw): string
{
    $name = is_string($raw) ? trim($raw) : '';

    return in_array($name, PAGE_WIDTHS, true) ? $name : PAGE_WIDTH_DEFAULT;
}

/**
 * Своя ширина в пределах: значение вне границ подрезается, а не отклоняется — страницу уже
 * открыли, и потерять её из-за одного числа хуже, чем подрезать.
 */
function sectionWidthCustomOf(mixed $raw): int
{
    $value = is_numeric($raw) ? (int) $raw : PAGE_CONTAINER_CUSTOM_DEFAULT;

    return max(PAGE_CONTAINER_CUSTOM_MIN, min(PAGE_CONTAINER_CUSTOM_MAX, $value));
}

/**
 * Ширина секции в ключах для шаблона: нужен ли вложенный контейнер и с каким пределом. Классы
 * собирает тема — ядро отдаёт решение, а не разметку.
 *
 * @return array{width: string, container: bool, custom: int}
 */
function sectionWidthPlan(mixed $width, mixed $custom): array
{
    $name = sectionWidthOf($width);

    return [
        'width' => $name,
        // У «полной ширины» вложенного контейнера нет вовсе: человек сам решает, печатать ли
        // ограничитель внутри (Full Width Section + Inner Content Container).
        'container' => $name !== 'full',
        'custom' => sectionWidthCustomOf($custom),
    ];
}
