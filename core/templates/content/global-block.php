<?php

declare(strict_types=1);

// Разметка глобального блока: единственное место, где его фиксированные поля превращаются в HTML.
// Текст собирается richText(), остальные значения печатаются через e(). Вид вёрстки задаёт
// конструктор страниц, поэтому блок одинаково выглядит на всех страницах.

/** @var array<string, mixed> $block */

$title = (string) $block['title'];
$subtitle = (string) $block['subtitle'];
$text = (string) $block['text'];
$image = (string) $block['image'];
$imageAlt = (string) $block['image_alt'];
$link = (string) $block['link'];
$linkLabel = (string) $block['link_label'];
$layout = (string) $block['layout'];

$isBanner = $layout === 'banner';
$wrapperClass = $isBanner
    ? 'rounded-xl bg-ink px-6 py-8 text-cream'
    : 'rounded-xl border border-ink/10 bg-white p-6';
$subtitleClass = $isBanner ? 'text-cream/70' : 'text-ink/60';

$body = '<div class="' . e($wrapperClass) . '">';

$media = '';
if ($image !== '' && contentPublicHref($image, true) !== '') {
    $media = '<img class="w-full rounded-lg object-cover" src="' . e($image) . '" alt="' . e($imageAlt !== '' ? $imageAlt : $title) . '" loading="lazy">';
}

$caption = '<h2 class="text-2xl font-bold">' . e($title) . '</h2>';
if ($subtitle !== '') {
    $caption .= '<p class="mt-1 text-sm ' . e($subtitleClass) . '">' . e($subtitle) . '</p>';
}
if ($text !== '') {
    $caption .= '<div class="mt-3 leading-relaxed">' . richText($text) . '</div>';
}
if ($link !== '' && contentPublicHref($link) !== '') {
    $caption .= '<p class="mt-4"><a class="inline-flex min-h-[44px] items-center rounded-lg border border-current px-3 py-1.5 text-sm hover:opacity-80" href="'
        . e($link) . '">' . e($linkLabel !== '' ? $linkLabel : 'Подробнее') . '</a></p>';
}

if ($layout === 'media-left' || $layout === 'media-right') {
    $body .= '<div class="grid items-center gap-5 sm:grid-cols-2">';
    $body .= $media !== '' ? '<div class="min-w-0">' . $media . '</div>' : '<div></div>';
    $body .= '<div class="min-w-0">' . $caption . '</div></div>';
} else {
    if ($media !== '') {
        $body .= '<div class="mb-4">' . $media . '</div>';
    }
    $body .= $caption;
}

$body .= '</div>';

$content = $body;
?>
<?= $content /* @safe html */ ?>
