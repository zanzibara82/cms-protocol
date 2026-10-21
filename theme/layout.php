<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $description */
/** @var string $content */
/** @var string $head */
/** @var string $footer */
/** @var bool $noindex */
/** @var string $robots */
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
    <?= $head /* @safe html */ ?>
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<a class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:px-4 focus:py-2" href="#main">К содержимому</a>
<?= partial('partials/header') /* @safe html */ ?>
<main id="main" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6">
    <?= $content /* @safe html */ ?>
</main>
<?= partial('partials/footer') /* @safe html */ ?>
<?= $footer /* @safe html */ ?>
</body>
</html>
