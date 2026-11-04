<?php

declare(strict_types=1);

// Гард ученических экранов: войти обязан каждый, заблокированный аккаунт разделом
// не пользуется. Глубинная ссылка уезжает в штатный вход ядра, чтобы после входа
// человек вернулся туда, куда шёл.

/**
 * @return array<string, mixed>
 */
function learnUser(?string $next = null): array
{
    $user = currentUser();

    if ($user === null || $user['blocked_at'] !== null) {
        redirect('/login?next=' . rawurlencode($next ?? requestPath()));
    }

    return $user;
}

/**
 * Просит ли журнал попыток регистрации притормозить: не больше лимита за окно по адресу.
 */
function learnRegistrationBlocked(string $ip, ?DateTimeImmutable $now = null): bool
{
    $moment = $now ?? nowUtc();

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_registration_attempt') . ' WHERE ip = ? AND attempted_at > ?',
        [$ip, utcStamp($moment->sub(new DateInterval('PT' . LEARN_REGISTER_WINDOW_MINUTES . 'M')))]
    ) >= LEARN_REGISTER_LIMIT_PER_IP;
}

function learnRecordRegistrationAttempt(string $email, string $ip, ?DateTimeImmutable $now = null): void
{
    insert('learn_registration_attempt', [
        'id' => newId(),
        'ip' => $ip,
        'email' => mb_substr((string) $email, 0, LEARN_REGISTER_LOG_EMAIL_MAX),
        'attempted_at' => utcStamp($now ?? nowUtc()),
    ]);
}
