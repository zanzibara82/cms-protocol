<?php

declare(strict_types=1);

// Флаги куки из спецификации §7: без JavaScript, только для своего сайта, secure при HTTPS.
// use_strict_mode не даёт серверу принять принесённый извне идентификатор сессии.
const SESSION_LIFETIME_SECONDS = 8 * 60 * 60;

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME_SECONDS);
    session_name('cms_session');
    session_start();
}

function sessionUserId(): ?string
{
    $id = $_SESSION['user_id'] ?? null;

    return is_string($id) && $id !== '' ? $id : null;
}

function sessionVersion(): int
{
    return (int) ($_SESSION['session_version'] ?? 0);
}

/** @param array<string, mixed> $user */
function loginUser(array $user): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
}

function logoutUser(): void
{
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        // Момент берём из nowUtc(): время в обход core/date.php запрещает страж.
        setcookie(
            session_name(),
            '',
            nowUtc()->getTimestamp() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

// Отзыв всех сессий пользователя: номер в базе поднимается, и живые куки перестают подходить.
function revokeSessions(string $userId): void
{
    run('UPDATE ' . t('user') . ' SET session_version = session_version + 1 WHERE id = ?', [$userId]);
}
