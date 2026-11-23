<?php

declare(strict_types=1);

// Оформление темы: цвета и типографика, которые человек правит на экране «Дизайн → Оформление».
// Значения лежат в tbl_option и печатаются инлайновым <style> поверх артефакта CSS.
//
// Почему поверх артефакта, а не сборкой: артефакт общий для всех сайтов и собирается при
// выкладке, а цвета — данные конкретного сайта. Переменные называются так же, как в @theme
// (src-css/app.css), поэтому существующие классы (bg-paper, text-ink, bg-accent) подхватывают
// свои значения сами, и ни один шаблон править не нужно.
//
// Пользовательский CSS сюда не попадает: человек задаёт цвет и размер, а не правило. Значение,
// не похожее на цвет, отбрасывается — иначе `red; background:url(...)` внедрило бы в стиль
// чужое правило.
//
// CSP сайта разрешает style-src 'unsafe-inline', поэтому <style> в head проходит. Скриптов это
// не касается: script-src 'self' не менялся.

/** Цвета темы: имя переменной => значение по умолчанию из @theme. */
/** @var array<string, string> */
const DESIGN_COLOR_DEFAULTS = [
    'paper' => '#fdf6ec',
    'cream' => '#fff9ef',
    'ink' => '#2b2118',
    'accent' => '#d97706',
    'good' => '#16a34a',
    'bad' => '#dc2626',
];

/** Подписи цветов: человек выбирает «Фон страницы», а не «paper». */
/** @var array<string, string> */
const DESIGN_COLOR_NAMES = [
    'paper' => 'Фон страницы',
    'cream' => 'Фон карточек',
    'ink' => 'Текст',
    'accent' => 'Акцент',
    'good' => 'Успех',
    'bad' => 'Ошибка',
];

/**
 * Пределы размеров заголовков в px. Нижний — меньше уже не читается как заголовок, верхний —
 * дальше строка распадается на слова. Пределы проверяются на сервере: подделанный POST не должен
 * давать страницу с невидимым или гигантским заголовком.
 */
const DESIGN_FONT_MIN = 14;
const DESIGN_FONT_MAX = 96;

/** Шкала типографики: имя переменной => значение по умолчанию в px (как в теме). */
/** @var array<string, int> */
const DESIGN_FONT_DEFAULTS = [
    'h1' => 36,
    'h2' => 28,
    'h3' => 22,
    'body' => 16,
];

/** @var array<string, string> */
const DESIGN_FONT_NAMES = [
    'h1' => 'Заголовок H1',
    'h2' => 'Заголовок H2',
    'h3' => 'Заголовок H3',
    'body' => 'Основной текст',
];

/**
 * Цвет из значения формы. Понимает `#abc` и `#aabbcc`; всё остальное — не цвет, и тогда
 * остаётся прежнее значение. Прозрачность и именованные цвета не принимаются: палитра сайта —
 * шесть переменных, и «rebeccapurple» в них не превращается.
 *
 * @param array<string, string> $defaults
 * @return array<string, string>
 */
function designColorsOf(mixed $raw, array $defaults = DESIGN_COLOR_DEFAULTS): array
{
    $input = is_array($raw) ? $raw : [];
    $colors = [];

    foreach ($defaults as $key => $fallback) {
        $value = $input[$key] ?? null;
        $colors[$key] = is_string($value) ? (designColorOf($value) ?? $fallback) : $fallback;
    }

    return $colors;
}

/** Один цвет: `#abc` или `#aabbcc` в нижнем регистре, либо null, если это не цвет. */
function designColorOf(mixed $raw): ?string
{
    $value = is_string($raw) ? strtolower(trim($raw)) : '';

    if (preg_match('/^#[0-9a-f]{3}$/', $value) === 1) {
        // Короткая запись разворачивается: `#abc` и `#aabbcc` — один цвет, и сравнивать их
        // как строки (например, с умолчанием) иначе нельзя.
        return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
    }

    return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
}

/**
 * Размер шрифта в пределах шкалы. Нечисловое значение означает «как в теме» — для заголовков это
 * умолчание, а не отказ: пустое поле приходит при первом открытии экрана.
 */
function designSizeOf(string $key, mixed $raw): int
{
    $fallback = DESIGN_FONT_DEFAULTS[$key] ?? DESIGN_FONT_DEFAULTS['body'];

    if (!is_numeric($raw)) {
        return $fallback;
    }

    return max(DESIGN_FONT_MIN, min(DESIGN_FONT_MAX, (int) $raw));
}

/**
 * Типографика из формы. Пустое значение остаётся пустым: это значит «как в теме», и тогда
 * переменная не печатается вовсе. Дублировать умолчания в <style> нельзя — правка CSS темы
 * перестала бы доезжать до сайта.
 *
 * @return array<string, int>
 */
function designTypographyOf(mixed $raw): array
{
    $input = is_array($raw) ? $raw : [];
    $sizes = [];

    foreach (DESIGN_FONT_DEFAULTS as $key => $fallback) {
        $value = $input[$key] ?? null;

        if (is_numeric($value) && (int) $value > 0) {
            $sizes[$key] = max(DESIGN_FONT_MIN, min(DESIGN_FONT_MAX, (int) $value));
        }
    }

    return $sizes;
}

/**
 * Свой <style> для страницы: переменные цветов и размеров. Пустой результат означает «ничего не
 * меняли» — лишний тег на каждой странице не нужен.
 *
 * Цвета печатаются, только если хоть один отличается от умолчания: иначе сайт получал бы
 * инлайновый стиль с теми же значениями, что в артефакте, и правка темы не была бы видна.
 *
 * @param array<string, string> $colors
 * @param array<string, int> $sizes
 */
function designStyleTag(array $colors, array $sizes): string
{
    $lines = [];

    foreach (DESIGN_COLOR_DEFAULTS as $key => $fallback) {
        $value = $colors[$key] ?? $fallback;

        if ($value !== $fallback) {
            $lines[] = '--color-' . $key . ': ' . $value . ';';
        }
    }

    foreach ($sizes as $key => $size) {
        // Основной текст идёт через font-size тела, заголовки — своими переменными: шаблоны
        // печатают классы text-2xl и подобные, поэтому переменные подставляются в те же имена,
        // которые объявляет тема.
        $lines[] = $key === 'body'
            ? 'font-size: ' . $size . 'px;'
            : '--design-' . $key . ': ' . $size . 'px;';
    }

    if ($lines === []) {
        return '';
    }

    $body = implode(' ', $lines);

    // Селекторы без пользовательских данных: :root для переменных, body для размера текста.
    // Значения прошли проверку формата, поэтому в правило не может попасть второе объявление.
    return '<style>:root{' . $body . '}</style>';
}

/**
 * Контраст двух цветов по WCAG (1…21). Нужен экрану настроек: своя палитра легко даёт светлый
 * текст на светлом фоне, и это видно только на живой странице.
 *
 * @param string $a цвет `#rrggbb`
 * @param string $b цвет `#rrggbb`
 */
function designContrast(string $a, string $b): float
{
    $la = designLuminance($a);
    $lb = designLuminance($b);
    $light = max($la, $lb);
    $dark = min($la, $lb);

    return ($light + 0.05) / ($dark + 0.05);
}

/** Относительная яркость цвета `#rrggbb` по формуле WCAG. */
function designLuminance(string $hex): float
{
    $value = designColorOf($hex) ?? '#000000';
    $channels = [];

    for ($i = 1; $i <= 5; $i += 2) {
        $channel = hexdec(substr($value, $i, 2)) / 255;
        $channels[] = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Текущие настройки оформления из базы. Отсутствующая опция — умолчание темы, а не ошибка:
 * экран открывается на свежем сайте, где ничего не сохраняли.
 *
 * @return array{colors: array<string, string>, sizes: array<string, int>, css: string}
 */
function designSystemCurrent(): array
{
    $rawColors = [];

    foreach (DESIGN_COLOR_DEFAULTS as $key => $fallback) {
        $rawColors[$key] = getOption('design_color_' . $key, $fallback);
    }

    $rawSizes = [];

    foreach (DESIGN_FONT_DEFAULTS as $key => $fallback) {
        $rawSizes[$key] = getOption('design_font_' . $key, '');
    }

    $colors = designColorsOf($rawColors);
    $sizes = designTypographyOf($rawSizes);

    return ['colors' => $colors, 'sizes' => $sizes, 'css' => designStyleTag($colors, $sizes)];
}

/**
 * Значения оформления из формы.
 *
 * @return array{colors: array<string, string>, sizes: array<string, int>}
 */
function designSystemFromPost(): array
{
    $rawColors = [];

    foreach (DESIGN_COLOR_DEFAULTS as $key => $fallback) {
        $rawColors[$key] = postString('color_' . $key);
    }

    $rawSizes = [];

    foreach (DESIGN_FONT_DEFAULTS as $key => $fallback) {
        $rawSizes[$key] = postString('font_' . $key);
    }

    return ['colors' => designColorsOf($rawColors), 'sizes' => designTypographyOf($rawSizes)];
}

/**
 * Запись оформления одной транзакцией: экран показывает палитру как одно целое, и половина
 * сохранённых цветов хуже, чем прежний набор.
 *
 * @param array{colors: array<string, string>, sizes: array<string, int>} $values
 */
function designSystemSave(array $values): void
{
    tx(static function () use ($values): void {
        foreach (DESIGN_COLOR_DEFAULTS as $key => $fallback) {
            setOption('design_color_' . $key, (string) ($values['colors'][$key] ?? $fallback));
        }

        foreach (DESIGN_FONT_DEFAULTS as $key => $fallback) {
            // Пустая строка — «как в теме»: переменная не печатается, и CSS темы работает сам.
            setOption('design_font_' . $key, isset($values['sizes'][$key]) ? (string) $values['sizes'][$key] : '');
        }
    });
}
