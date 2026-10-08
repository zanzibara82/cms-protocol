<?php

declare(strict_types=1);

// Роли пилота: администратор может всё, редактор правит разделы контента. Ученик и гость — нет.
const ROLES = ['admin' => 'admin', 'editor' => 'editor'];

// Лимиты подбора пароля. Числа из спецификации §7: 5 попыток на адрес за 15 минут,
// 20 на IP за час. Окно задаётся в минутах.
const LOGIN_LIMIT_PER_EMAIL = 5;
const LOGIN_WINDOW_EMAIL = 15;
const LOGIN_LIMIT_PER_IP = 20;
const LOGIN_WINDOW_IP = 60;

const LOGIN_ERROR_GENERIC = 'Почта или пароль не подошли. Проверьте раскладку и попробуйте ещё раз.';
const LOGIN_ERROR_BLOCKED = 'Слишком много попыток входа. Подождите немного и попробуйте снова.';

function passwordAlgorithm(): string
{
    return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
}

function passwordAlgoConstant(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

// Алгоритм можно передать явно: так тест проверяет обе ветки выбора, не пересобирая PHP.
function hashPassword(string $plain, ?string $algo = null): string
{
    return password_hash($plain, $algo ?? passwordAlgoConstant());
}

function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

function needsRehash(string $hash): bool
{
    return password_needs_rehash($hash, passwordAlgoConstant());
}

/** @param array<string, mixed>|null $account */
function roleGrantsManage(?array $account): bool
{
    if ($account === null || (bool) ($account['blocked'] ?? false)) {
        return false;
    }

    return in_array((string) ($account['role'] ?? ''), [ROLES['admin'], ROLES['editor']], true);
}

// Одна формулировка «кто правит контентом»: её читают и гейт маршрута, и POST-обработчики.
// Аноним получает редирект на /login (для GET) или 403 (для приватного пути админки раздела).
/** @return array{kind: 'granted', editor: array{id: string, email: string}}|array{kind: 'denied'} */
function getEditorAccess(): array
{
    $user = currentUser();
    if ($user === null) {
        return ['kind' => 'denied'];
    }

    $account = [
        'role' => (string) $user['role'],
        'email' => (string) $user['email'],
        'blocked' => $user['blocked_at'] !== null,
    ];

    if (!roleGrantsManage($account)) {
        logWarn('access.manage_denied', ['userId' => (string) $user['id'], 'role' => $account['role']]);

        return ['kind' => 'denied'];
    }

    return ['kind' => 'granted', 'editor' => ['id' => (string) $user['id'], 'email' => $account['email']]];
}

/** @return array<string, mixed>|null */
function currentUser(): ?array
{
    $id = sessionUserId();
    if ($id === null) {
        return null;
    }

    $row = one(
        'SELECT id, email, name, role, session_version, blocked_at FROM ' . t('user') . ' WHERE id = ?',
        [$id]
    );
    if ($row === null) {
        return null;
    }

    // Отзыв сессии: номер в куке ниже номера в базе — человек разлогинен, даже если кука жива.
    if ((int) $row['session_version'] !== sessionVersion()) {
        logoutUser();

        return null;
    }

    return $row;
}

// Подбор пароля. Попытки пишутся в tbl_login_attempt и читаются только из неё: лимит по IP
// считает адрес из серверного массива, поэтому за прокси все посетители делят один лимит — это сознательно.
function loginBlocked(string $email, string $ip, ?DateTimeImmutable $now = null): bool
{
    $moment = $now ?? nowUtc();

    $byEmail = (int) value(
        'SELECT COUNT(*) FROM ' . t('login_attempt') . ' WHERE email = ? AND attempted_at > ?',
        [mb_strtolower(trim($email)), utcStamp($moment->sub(new DateInterval('PT' . LOGIN_WINDOW_EMAIL . 'M')))]
    );
    if ($byEmail >= LOGIN_LIMIT_PER_EMAIL) {
        return true;
    }

    $byIp = (int) value(
        'SELECT COUNT(*) FROM ' . t('login_attempt') . ' WHERE ip = ? AND attempted_at > ?',
        [$ip, utcStamp($moment->sub(new DateInterval('PT' . LOGIN_WINDOW_IP . 'M')))]
    );

    return $byIp >= LOGIN_LIMIT_PER_IP;
}

function recordLoginAttempt(string $email, string $ip, ?DateTimeImmutable $now = null): void
{
    insert('login_attempt', [
        'email' => mb_strtolower(trim($email)),
        'ip' => $ip,
        'attempted_at' => utcStamp($now ?? nowUtc()),
    ]);
}

/** @return array{ok: true, user: array<string, mixed>}|array{ok: false, error: string} */
function attemptLogin(string $email, string $password): array
{
    $clean = mb_strtolower(trim($email));
    $ip = clientIp();

    if ($clean === '' || $password === '') {
        return ['ok' => false, 'error' => LOGIN_ERROR_GENERIC];
    }

    if (loginBlocked($clean, $ip)) {
        logWarn('auth.blocked', ['email' => $clean, 'ip' => $ip]);

        return ['ok' => false, 'error' => LOGIN_ERROR_BLOCKED];
    }

    $row = one('SELECT * FROM ' . t('user') . ' WHERE email = ?', [$clean]);
    if ($row === null || !verifyPassword($password, (string) $row['password_hash'])) {
        recordLoginAttempt($clean, $ip);
        logWarn('auth.failed', ['email' => $clean, 'ip' => $ip]);

        return ['ok' => false, 'error' => LOGIN_ERROR_GENERIC];
    }

    if ($row['blocked_at'] !== null) {
        return ['ok' => false, 'error' => 'Аккаунт заблокирован. Напишите администратору.'];
    }

    if (needsRehash((string) $row['password_hash'])) {
        run('UPDATE ' . t('user') . ' SET password_hash = ? WHERE id = ?', [hashPassword($password), $row['id']]);
    }

    loginUser($row);
    logInfo('auth.login', ['userId' => (string) $row['id']]);

    return ['ok' => true, 'user' => $row];
}
