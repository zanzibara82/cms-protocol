<?php

declare(strict_types=1);

/** @param array<string, string> $headers */
function respond(int $status, string $body, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    echo $body;

    // Под php -S и в вебе запрос завершается здесь; в PHPUnit процесс нужен живым.
    if (defined('PHPUNIT_RUNNING')) {
        throw new \Cms\Core\ResponseFinished((string) $status);
    }

    exit;
}

function redirect(string $to, int $status = 302): never
{
    respond($status, '', ['Location' => $to]);
}

/**
 * Дописывает в адрес параметр: «?» перед первым и «&» после него. Возвращает строку, а не редиректит,
 * поэтому проверять склейку можно без выхода из процесса.
 */
function queryAppend(string $to, string $key, string $value): string
{
    $separator = str_contains($to, '?') ? '&' : '?';

    return $to . $separator . rawurlencode($key) . '=' . rawurlencode($value);
}

// PRG: после действия человек видит GET-страницу, а не повторную отправку формы при обновлении.
function redirectWithNotice(string $to, string $notice): never
{
    redirect(queryAppend($to, 'notice', $notice));
}

function notFound(string $message = ''): never
{
    if (!headers_sent()) {
        http_response_code(404);
    }
    render('404', ['message' => $message], ['title' => 'Страницы нет', 'noindex' => true]);
    exit;
}

function forbidden(string $message = ''): never
{
    logWarn('access.forbidden', ['path' => requestPath(), 'ip' => clientIp()]);
    // Отказ — страница темы, а не фрагмент: человек обязан увидеть шапку, футер и подключённые
    // стили. Канал ответа при этом остаётся единственным местом, где задаются статус и заголовки,
    // поэтому каркас собирается строкой и передаётся в respond().
    respond(
        403,
        renderToString('403', ['message' => $message], ['title' => 'Сюда нельзя', 'noindex' => true]),
        ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow']
    );
}
