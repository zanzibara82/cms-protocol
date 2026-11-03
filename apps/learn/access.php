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
