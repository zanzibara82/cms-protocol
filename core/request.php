<?php

declare(strict_types=1);

// Адрес клиента берём только из серверного: его подделать запросом нельзя. Заголовки вида «кто меня
// проксировал» подставляет кто угодно, и лимит входа, прочитанный из них, обходится одной строкой в
// curl. Названий этих заголовков здесь нет намеренно: страж ClientIpTest читает исходники как текст и
// считает их находками, а адрес клиента из серверного массива обязан встречаться в этом файле ровно один раз.
function clientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

function requestMethod(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function isPost(): bool
{
    return requestMethod() === 'POST';
}

function requestPath(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    return '/' . trim(strtok($uri, '?') ?: '/', '/');
}

function queryString(?string $key = null, ?string $default = null): mixed
{
    if ($key === null) {
        return $_GET;
    }

    $value = $_GET[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/** @return array<string, mixed> */
function postAll(): array
{
    return $_POST;
}

/**
 * Список строк из формы с индексами: подтверждённые флажки массовой операции приходят массивом.
 * Вложенные массивы и пустые значения отбрасываются — обработчик получает только идентификаторы.
 *
 * @return list<string>
 */
function postList(string $key): array
{
    $value = $_POST[$key] ?? null;

    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        if (is_string($item) && $item !== '') {
            $out[] = $item;
        }
    }

    return array_values(array_unique($out));
}

/** @return array<string, mixed>|null */
function uploadedFile(string $key): ?array
{
    $file = $_FILES[$key] ?? null;

    return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
}

function isHttps(): bool
{
    return (($_SERVER['HTTPS'] ?? '') === 'on') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}
