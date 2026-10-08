<?php

declare(strict_types=1);

// Маршруты фикстуры регистрируются, но не открываются: раздел не установлен, поэтому гейт
// dispatch() отвечает 404. Обработчики нужны, чтобы страж RouteHandlersTest не нашёл пустых имён.
addRoute('GET', '/demo', 'demoFeed', ['app' => 'demo']);
addRoute('GET', '/demo/admin', 'demoAdmin', ['app' => 'demo', 'private' => true, 'permission' => 'login']);
// Обработчик-замыкание: F5 проверяет, что снимок маршрутов сохраняет его как есть, а не приводит к строке.
addRoute('GET', '/demo/closure', static function (array $params = []): void {
    respond(200, 'Лента из замыкания', ['Content-Type' => 'text/plain; charset=utf-8']);
}, ['app' => 'demo']);

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
