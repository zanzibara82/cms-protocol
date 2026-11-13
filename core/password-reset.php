<?php

declare(strict_types=1);

// Сброс пароля редактора. Токен живёт в базе только хешем, действует час и гасится после смены
// пароля. Ответ на запрос всегда один и тот же: по нему нельзя узнать, зарегистрирована ли почта.
// Смена пароля поднимает session_version — все прежние сеансы перестают действовать.

const PASSWORD_RESET_MIN = 10;
const PASSWORD_RESET_MAX = 200;
const PASSWORD_RESET_TTL_MINUTES = 60;
const PASSWORD_RESET_RATE_LIMIT = 5;
const PASSWORD_RESET_RATE_WINDOW_MINUTES = 60;

function passwordResetBrand(): string
{
    $site = trim((string) configValue('SITE_NAME'));

    return $site !== '' ? $site : 'NovaCMS';
}

/**
 * @return array{ok: bool, error: string}
 */
function passwordResetRequest(string $email): array
{
    $clean = trim($email);
    $now = nowUtc();
    $threshold = utcStamp(shiftSeconds($now, -PASSWORD_RESET_RATE_WINDOW_MINUTES * 60));
    $ip = clientIp();

    $attempts = (int) value(
        'SELECT COUNT(*) FROM ' . t('password_reset_attempt') . ' WHERE ip = ? AND attempted_at > ?',
        [$ip, $threshold]
    );

    if ($attempts >= PASSWORD_RESET_RATE_LIMIT) {
        return ['ok' => false, 'error' => 'Сброс пароля запрашивали слишком часто — подождите час.'];
    }

    insert('password_reset_attempt', [
        'email' => mb_substr($clean, 0, 190),
        'ip' => mb_substr($ip, 0, 45),
        'attempted_at' => utcStamp($now),
    ]);

    if ($clean === '' || filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
        // Тот же успех, что и для существующего адреса: форма не должна превращаться в проверку
        // существования аккаунтов.
        return ['ok' => true, 'error' => ''];
    }

    $user = one(
        'SELECT id, email, name, blocked_at FROM ' . t('user') . ' WHERE email = ?',
        [$clean]
    );

    if ($user === null || $user['blocked_at'] !== null) {
        return ['ok' => true, 'error' => ''];
    }

    $raw = bin2hex(random_bytes(32));
    $id = newId();
    $expires = shiftSeconds($now, PASSWORD_RESET_TTL_MINUTES * 60);

    tx(static function () use ($user, $id, $raw, $now, $expires): void {
        // Прежние неиспользованные ссылки гасятся: действует только последняя.
        run(
            'UPDATE ' . t('password_reset') . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
            [utcStamp($now), (string) $user['id']]
        );
        insert('password_reset', [
            'id' => $id,
            'user_id' => (string) $user['id'],
            'token_hash' => hash('sha256', $raw),
            'created_at' => utcStamp($now),
            'expires_at' => utcStamp($expires),
            'used_at' => null,
        ]);
    });

    $url = absoluteUrl('/reset-password/' . $raw);
    $brand = passwordResetBrand();
    $name = (string) $user['name'];
    $text = "Здравствуйте, {$name}!\n\n"
        . "Вы запросили сброс пароля на сайте {$brand}. Ссылка действует час:\n\n"
        . $url . "\n\n"
        . "Если вы не запрашивали сброс, просто удалите письмо — пароль останется прежним.\n";
    // Разметка письма живёт в core/templates/mail: сборка стилей и страж зон нажатия читают
    // разметку только оттуда, а бизнес-логика остаётся здесь.
    $html = partial('mail/reset-password', [
        'url' => $url,
        'name' => $name,
        'brand' => $brand,
    ]);

    $mailer = new \Cms\Core\Mailer();
    $result = $mailer->send([
        'to' => (string) $user['email'],
        'subject' => 'Сброс пароля — ' . $brand,
        'text' => $text,
        'html' => $html,
    ]);

    if (($result['ok'] ?? false) !== true) {
        logError('password_reset.mail_failed', ['userId' => (string) $user['id'], 'reason' => (string) ($result['error'] ?? '')]);
    }

    auditLog('password_reset.request', 'user', (string) $user['id'], [], 'Запрошен сброс пароля');

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed>|null */
function passwordResetTokenRow(string $raw): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
        return null;
    }

    $row = one(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email, u.name, u.blocked_at'
        . ' FROM ' . t('password_reset') . ' AS pr'
        . ' JOIN ' . t('user') . ' AS u ON u.id = pr.user_id'
        . ' WHERE pr.token_hash = ?',
        [hash('sha256', $raw)]
    );

    if ($row === null || $row['used_at'] !== null || $row['blocked_at'] !== null) {
        return null;
    }

    if ((string) $row['expires_at'] <= utcStamp(nowUtc())) {
        return null;
    }

    return $row;
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function passwordResetByToken(string $raw, string $password, string $confirm): array
{
    $row = passwordResetTokenRow($raw);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Ссылка недействительна или устарела — запросите новую.', 'notice' => ''];
    }

    if (mb_strlen($password) < PASSWORD_RESET_MIN) {
        return ['ok' => false, 'error' => 'Пароль — не короче ' . PASSWORD_RESET_MIN . ' символов.', 'notice' => ''];
    }

    if (mb_strlen($password) > PASSWORD_RESET_MAX) {
        return ['ok' => false, 'error' => 'Пароль длиннее ' . PASSWORD_RESET_MAX . ' символов.', 'notice' => ''];
    }

    if ($password !== $confirm) {
        return ['ok' => false, 'error' => 'Пароли не совпадают — повторите ввод.', 'notice' => ''];
    }

    $userId = (string) $row['user_id'];

    tx(static function () use ($userId, $password, $raw): void {
        run(
            'UPDATE ' . t('user') . ' SET password_hash = ? WHERE id = ?',
            [hashPassword($password), $userId]
        );
        run(
            'UPDATE ' . t('password_reset') . ' SET used_at = ? WHERE token_hash = ?',
            [utcStamp(nowUtc()), hash('sha256', $raw)]
        );
        // Остальные ссылки того же пользователя тоже гасятся.
        run(
            'UPDATE ' . t('password_reset') . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
            [utcStamp(nowUtc()), $userId]
        );
    });

    revokeSessions($userId);

    auditLog('password_reset.complete', 'user', $userId, [], 'Пароль изменён по ссылке сброса');
    logInfo('password_reset.completed', ['userId' => $userId]);

    return ['ok' => true, 'error' => '', 'notice' => 'password-reset-done'];
}
