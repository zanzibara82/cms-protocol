<?php

declare(strict_types=1);

/**
 * График просмотров по дням: линия в прямоугольнике. Числа под графиком не рисуются текстом
 * внутри SVG — подписи осей вынесены под него обычной разметкой, иначе шрифт в рисунке не
 * масштабировался бы вместе с рисунком.
 *
 * @var list<array{day: string, views: int, sessions: int}> $daily
 */
$width = 720;
$height = 160;
$max = 1;

foreach ($daily as $row) {
    $max = max($max, (int) $row['views']);
}

$count = count($daily);
$step = $count > 1 ? (float) ($width - 20) / (float) ($count - 1) : 0.0;
$points = [];

foreach ($daily as $index => $row) {
    $x = 10 + $index * $step;
    $y = $height - 10 - ((int) $row['views'] / $max) * ($height - 30);
    $points[] = round($x, 1) . ',' . round($y, 1);
}

$content = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="h-40 w-full text-accent" role="img"'
    . ' aria-label="Просмотры по дням">'
    . '<polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" points="'
    . e(implode(' ', $points)) . '"></polyline>'
    . '</svg>';
?>
<?= $content /* @safe html */ ?>
