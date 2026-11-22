<?php

declare(strict_types=1);

// Каркас темы «Школа». Отличие от встроенного `theme/layout.php` — акцентная полоса под шапкой и
// широкий контейнер содержимого. Иерархия шаблонов одна и та же: этот файл перекрывает встроенный
// каркас, а партиалы и секции тема не переопределяет — они берутся из `theme/`, поэтому смена
// темы не требует копировать всё дерево.
//
// Тема обязана уважать макет страницы: `$layout` приходит из render() описанием каркаса
// (core/page-layout.php). Без этого лендинг без шапки и страница с сайдбаром работали бы только
// на встроенной теме, а на этой — молча показывали полную обвязку. Именно так и случилось:
// тема была написана до появления макетов и читала только $chrome.
/** @var string $title */
/** @var string $description */
/** @var string $content */
/** @var string $head */
/** @var bool $noindex */
/** @var string $robots */
/** @var bool $chrome */
/** @var string $footer вывод хука page_footer */
/** @var string $layout макет страницы */

$plan = pageLayoutPlan($layout ?? PAGE_LAYOUT_DEFAULT);
$showHeader = $chrome && $plan['header'];
$showFooter = $chrome && $plan['footer'];
$sidebar = $plan['sidebar'];
$hasSidebar = $sidebar !== 'none';
$shell = match (true) {
    $plan['blank'] => 'w-full',
    $hasSidebar => 'mx-auto grid w-full max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[16rem_minmax(0,1fr)]',
    default => 'mx-auto w-full max-w-6xl px-4 py-8 sm:px-6',
};
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= e($description) ?>">
    <?php endif; ?>
    <?php if ($noindex || $robots !== ''): ?>
        <meta name="robots" content="<?= e($robots !== '' ? $robots : 'noindex, nofollow') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" href="/assets/img/brand/favicon.png" type="image/png">
    <?php if ($chrome): ?>
        <link rel="alternate" type="application/rss+xml" title="RSS" href="/feed.xml">
        <link rel="alternate" type="application/atom+xml" title="Atom" href="/feed.atom">
    <?php endif; ?>
    <?= $head /* @safe html */ ?>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<a class="sr-only focus:not-sr-only focus:bg-white focus:px-4" href="#main">К содержимому</a>
<?php if ($showHeader): ?>
    <?= partial('partials/header') /* @safe html */ ?>
    <div class="h-1 w-full bg-accent"></div>
<?php endif; ?>
<main id="main" class="<?= e($shell) ?>">
    <?php if ($hasSidebar): ?>
        <?php if ($sidebar === 'left' || $sidebar === 'both'): ?>
            <aside class="min-w-0"><?= partial('partials/sidebar') /* @safe html */ ?></aside>
        <?php endif; ?>
        <div class="min-w-0"><?= $content /* @safe html */ ?></div>
        <?php if ($sidebar === 'right' || $sidebar === 'both'): ?>
            <aside class="min-w-0"><?= partial('partials/sidebar') /* @safe html */ ?></aside>
        <?php endif; ?>
    <?php else: ?>
        <?= $content /* @safe html */ ?>
    <?php endif; ?>
</main>
<?php if ($showFooter): ?>
    <?= partial('partials/footer') /* @safe html */ ?>
<?php endif; ?>
<?= $footer /* @safe html */ ?>
</body>
</html>
