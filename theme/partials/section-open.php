<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */
/** @var string $align выравнивание содержимого (text-center у полосы и призыва) */

// Открывающий контейнер секции по её ширине. Общий партиал, а не правка шаблонов по одному:
// ширина — свойство каждой секции, и повторять ветку «standard/wide/full/custom» в каждом виде
// значило бы держать восемнадцать копий одного правила.
//
// Full Width — это внешний блок на всю ширину, а внутренний ограничитель остаётся человеку:
// при «полной» ширине контейнер не печатается вовсе (концепция Full Width Section + Inner
// Content Container). Классы живут в теме: сборка стилей читает только каталоги разметки.
$plan = sectionWidthPlan($section['width'] ?? null, $section['width_custom'] ?? null);
$width = $plan['width'];
$custom = $plan['custom'];
$alignClass = trim((string) ($align ?? ''));

$class = match ($width) {
    'wide' => 'mx-auto max-w-6xl px-4 sm:px-6',
    'full' => 'w-full',
    'custom' => 'mx-auto px-4 sm:px-6',
    default => 'mx-auto max-w-5xl px-4 sm:px-6',
};

if ($alignClass !== '') {
    $class .= ' ' . $alignClass;
}

// Своя ширина — единственная, у которой есть значение от человека: предел уже подрезан правилом.
$style = $width === 'custom' ? 'max-width: ' . $custom . 'px' : '';
?>
<div class="<?= e($class) ?>"<?php if ($style !== ''): ?> style="<?= e($style) ?>"<?php endif; ?>>
