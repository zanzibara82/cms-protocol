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

/**
 * Сверка токена POST-запроса.
 *
 * Режим 'form' (по умолчанию) — обычная форма: токен живёт в сессии посетителя, и сверка
 * обязательна для любого POST, меняющего состояние от его имени.
 *
 * Режим 'public' — машинный приёмник, у которого сессии посетителя нет: счётчик событий
 * раздела «SEO и статистика». Такой адрес открыт мимо входа, и токен в нём — подпись
 * раздела, а не сессионная метка: страница кладёт подпись в разметку скрипта, а раздел
 * проверяет её через фильтр 'csrf_public_token'. Сессия при этом не открывается вовсе —
 * cookie ради счётчика означала бы, что режим «без cookies» недостижим.
 */
function csrfCheck(string $mode = 'form'): void
{
    if ($mode === 'public') {
        // Токен приходит телом запроса: подпись раздела, а не метка сессии.
        $given = postString('_token');

        if (applyFilters('csrf_public_token', false, $given) === true) {
            return;
        }

        logWarn('csrf.rejected', ['path' => requestPath(), 'ip' => clientIp(), 'mode' => 'public']);
        forbidden('Проверка запроса не прошла.');
    }

    startSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $given = postString('_token');

    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
        logWarn('csrf.rejected', ['path' => requestPath(), 'ip' => clientIp()]);
        forbidden('Проверка формы не прошла. Откройте страницу заново и повторите действие.');
    }
}
