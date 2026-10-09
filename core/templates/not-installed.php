<?php

declare(strict_types=1);

// Экран «Сайт не установлен»: печатается, когда схема ядра ещё не создана и запрос пришёл не в
// установщик. Разметка статичная, переменных нет — обращение к базе не делается, поэтому экран
// показывается и на полностью пустой базе. Ссылка ведёт на /setup.php, где человек создаёт схему.
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Сайт не установлен</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-[#fdf6ec] text-[#2b2118]">
<main class="mx-auto max-w-xl px-4 py-20 text-center">
    <h1 class="text-2xl font-bold">Сайт не установлен</h1>
    <p class="mt-4">Таблицы ядра ещё не созданы. Откройте установщик, чтобы завершить настройку сайта.</p>
    <p class="mt-6"><a class="inline-flex min-h-[44px] items-center underline" href="/setup.php">Перейти к установке</a></p>
</main>
</body>
</html>
