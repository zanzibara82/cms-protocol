<?php

declare(strict_types=1);

// Маршруты фикстуры регистрируются, но не открываются: раздел не установлен, поэтому гейт
// dispatch() отвечает 404. Обработчики нужны, чтобы страж RouteHandlersTest не нашёл пустых имён.
addRoute('GET', '/demo', 'demoFeed', ['app' => 'demo']);
addRoute('GET', '/demo/admin', 'demoAdmin', ['app' => 'demo', 'private' => true, 'permission' => 'login']);

/** @param array<string, string> $params */
function demoFeed(array $params = []): void
{
    respond(200, 'Демонстрационная лента', ['Content-Type' => 'text/plain; charset=utf-8']);
}

/** @param array<string, string> $params */
function demoAdmin(array $params = []): void
{
    respond(200, 'Демонстрационная админка', ['Content-Type' => 'text/plain; charset=utf-8']);
}
