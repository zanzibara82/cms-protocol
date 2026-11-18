<?php

declare(strict_types=1);

/**
 * Экран техобслуживания: картинка, заголовок и объяснение. Печатается мимо каркаса темы — ответ
 * отдаёт respond() со статусом 503 и запретом индексирования, а шапка и подвал тянули бы меню,
 * новости и прочие опции: при техобслуживании они могут быть причиной недоступности.
 *
 * Свой <html> здесь потому же, почему у экрана «Сайт не установлен»: каркаса нет, а мета-теги
 * (charset, viewport, robots) обязаны быть. Стражи ViewportMetaTest и NoIndexNoStoreSourceTest
 * знают этот файл поимённо.
 *
 * Текст из настроек экранируется: админ правит его на экране настроек, но публичная страница
 * не должна становиться местом исполнения чужой разметки. Пустое поле печатает запасной текст:
 * голый заголовок «на техобслуживании» ничего не объясняет посетителю.
 *
 * @var string $text объяснение из настроек
 * @var string $image путь к картинке; пусто — запасной кот
 */
$text = trim((string) ($text ?? ''));
$image = trim((string) ($image ?? ''));
$message = $text !== '' ? $text : 'Скоро вернёмся: сайт обновляется, материалы уже на месте.';
$picture = $image !== '' ? $image : '/assets/img/cats/cat-happy.png';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Сайт на техобслуживании</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-paper text-ink antialiased">
<main class="mx-auto max-w-xl px-4 py-16 text-center">
    <img class="cat-idle mx-auto h-auto w-[120px]" src="<?= e($picture) ?>" alt="Кот-талисман школы" width="120" height="120">
    <h1 class="mt-6 text-3xl font-bold">Сайт на техобслуживании</h1>
    <p class="mt-4 text-ink/70"><?= e($message) ?></p>
    <p class="mt-6 text-sm text-ink/60">Если вы администратор, войдите в админку — она открыта.</p>
    <p class="mt-4"><a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-3 hover:bg-cream" href="/login">Войти</a></p>
</main>
</body>
</html>
