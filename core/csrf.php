<?php

declare(strict_types=1);

// Токен живёт в сессии и возвращается скрытым полем. Проверяется в каждом POST-обработчике:
// страж tests/guard/CsrfCoverageTest.php следит, что обработчик без csrfCheck() не появляется.
function csrfToken(): string
{
    startSession();
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function csrfField(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): void
{
    startSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $given = postString('_token');

    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
        logWarn('csrf.rejected', ['path' => requestPath(), 'ip' => clientIp()]);
        forbidden('Проверка формы не прошла. Откройте страницу заново и повторите действие.');
    }
}
