<?php

declare(strict_types=1);

// Экран печатает только статичную разметку: переменных здесь нет, поэтому стражу экранирования
// выводить нечего, и пометка безопасного блока этому файлу не нужна.
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Экран помечен `noindex`, поэтому запрет кэширования обязана нести и его строка ответа.
         Директива живёт на обоих путях выдачи: при отсутствующем обработчике она стоит элементом
         массива `respond()` (core/router.php), а на экране непойманного исключения — вызовом
         `header()` внутри ветки `!headers_sent()` обработчика (core/bootstrap.php). -->
    <meta name="robots" content="noindex, nofollow">
    <title>Что-то сломалось</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-[#fdf6ec] text-[#2b2118]">
<main class="mx-auto max-w-xl px-4 py-20 text-center">
    <h1 class="text-2xl font-bold">Что-то сломалось</h1>
    <p class="mt-4">Мы уже записали ошибку в журнал и разберёмся. Попробуйте открыть страницу ещё раз.</p>
    <p class="mt-6"><a class="inline-flex min-h-[44px] items-center underline" href="/">На главную</a></p>
</main>
</body>
</html>
