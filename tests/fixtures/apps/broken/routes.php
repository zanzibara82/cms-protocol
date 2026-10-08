<?php

declare(strict_types=1);

// Раздел заявляет один маршрут метода GET: он нужен, чтобы реестр считал фикстуру обычным
// разделом. POST-обработчиков и форм здесь нет, поэтому страж CSRF к фикстуре не придирается,
// а страж обработчиков маршрутов находит brokenFeed в исходниках.
addRoute('GET', '/broken', 'brokenFeed', ['app' => 'broken']);

/** @param array<string, string> $params */
function brokenFeed(array $params = []): void
{
    respond(200, 'Лента раздела с битой миграцией', ['Content-Type' => 'text/plain; charset=utf-8']);
}
