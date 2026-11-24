<?php

declare(strict_types=1);

// Варианты шапки и подвала: выбор внешнего вида полос, а не второй каркас сайта. Вариант —
// набор флагов, которые читает шаблон темы; новый вариант добавляется строкой в перечень,
// шаблон при этом не меняется. Значение живёт в опции настроек рядом с текстами шапки.

/** @var array<string, array{title: string, menu: bool, centered: bool, top: bool}> */
const HEADER_VARIANTS = [
    // Стандарт: знак сайта слева, пункты меню и вход справа — то, что печаталось всегда.
    'standard' => ['title' => 'Стандартная', 'menu' => true, 'centered' => false, 'top' => false],
    // Узкая: только знак сайта и вход. Меню не печатается в полосе — оно остаётся в подвале,
    // поэтому страницы не теряют навигацию совсем.
    'minimal' => ['title' => 'Минималистичная', 'menu' => false, 'centered' => false, 'top' => false],
    // Центрированная: знак сайта по центру, меню под ним отдельным рядом.
    'centered' => ['title' => 'По центру', 'menu' => true, 'centered' => true, 'top' => false],
    // С верхней полосой: дополнительная строка над шапкой под телефон и подпись.
    'topline' => ['title' => 'С верхней полосой', 'menu' => true, 'centered' => false, 'top' => true],
];

const HEADER_VARIANT_DEFAULT = 'standard';

/** @var array<string, array{title: string, columns: int, brand: bool}> */
const FOOTER_VARIANTS = [
    // Столбцы — это число рядов колонок меню подвала, а не жёсткая сетка: колонки печатаются
    // только для существующих разделов меню, пустые не рисуются.
    'standard' => ['title' => 'Стандартный', 'columns' => 4, 'brand' => true],
    'compact' => ['title' => 'Компактный', 'columns' => 2, 'brand' => true],
    'plain' => ['title' => 'Без колонок меню', 'columns' => 0, 'brand' => true],
];

const FOOTER_VARIANT_DEFAULT = 'standard';

// Неизвестное значение означает стандартный вариант: строка приходит из базы и формы, и
// подделанный POST не должен оставить сайт без шапки.
function headerVariantOf(mixed $raw): string
{
    return is_string($raw) && array_key_exists($raw, HEADER_VARIANTS) ? $raw : HEADER_VARIANT_DEFAULT;
}

function footerVariantOf(mixed $raw): string
{
    return is_string($raw) && array_key_exists($raw, FOOTER_VARIANTS) ? $raw : FOOTER_VARIANT_DEFAULT;
}

/** @return array{variant: string, menu: bool, centered: bool, top: bool} */
function headerPlan(mixed $raw): array
{
    $variant = headerVariantOf($raw);
    $flags = HEADER_VARIANTS[$variant];

    return [
        'variant' => $variant,
        'menu' => $flags['menu'],
        'centered' => $flags['centered'],
        'top' => $flags['top'],
    ];
}

/** @return array{variant: string, columns: int, brand: bool} */
function footerPlan(mixed $raw): array
{
    $variant = footerVariantOf($raw);
    $flags = FOOTER_VARIANTS[$variant];

    return [
        'variant' => $variant,
        'columns' => $flags['columns'],
        'brand' => $flags['brand'],
    ];
}

/** @return array<string, string> ключ => подпись, для поля формы */
function headerVariantOptions(): array
{
    $options = [];

    foreach (HEADER_VARIANTS as $slug => $flags) {
        $options[$slug] = $flags['title'];
    }

    return $options;
}

/** @return array<string, string> */
function footerVariantOptions(): array
{
    $options = [];

    foreach (FOOTER_VARIANTS as $slug => $flags) {
        $options[$slug] = $flags['title'];
    }

    return $options;
}
