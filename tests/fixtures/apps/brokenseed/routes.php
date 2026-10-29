<?php

declare(strict_types=1);

// Раздел заявляет один маршрут метода GET: он нужен, чтобы реестр считал фикстуру обычным
// разделом. POST-обработчиков и форм здесь нет, поэтому страж CSRF к фикстуре не придирается,
// а страж обработчиков маршрутов находит brokerSeedFeed в исходниках.
addRoute('GET', '/brokenseed', 'brokerSeedFeed', ['app' => 'brokenseed']);

/** @param array<string, string> $params */
function brokerSeedFeed(array $params = []): void
{
    respond(200, 'Лента раздела со сбойной закладкой', ['Content-Type' => 'text/plain; charset=utf-8']);
}
