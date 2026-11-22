<?php

declare(strict_types=1);

// Каркас темы «Школа». Отличие от встроенного `theme/layout.php` — контентный контейнер шире
// (max-w-6xl вместо max-w-5xl) и акцентная полоса под шапкой. Иерархия шаблонов одна и та же:
// этот файл перекрывает встроенный каркас, а партиалы и секции тема не переопределяет —
// они берутся из `theme/`, поэтому смена темы не требует копировать всё дерево.
/** @var string $title */
/** @var string $description */
/** @var string $content */
/** @var string $head */
/** @var bool $noindex */
/** @var string $robots */
/** @var bool $chrome */
/** @var string $footer вывод хука page_footer */
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
<?php if ($chrome): ?>
    <?= partial('partials/header') /* @safe html */ ?>
    <div class="h-1 w-full bg-accent"></div>
<?php endif; ?>
<main id="main" class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">
    <?= $content /* @safe html */ ?>
</main>
<?php if ($chrome): ?>
    <?= partial('partials/footer') /* @safe html */ ?>
<?php endif; ?>
<?= $footer /* @safe html */ ?>
</body>
</html>
