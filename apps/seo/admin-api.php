<?php

declare(strict_types=1);

// Экран «API-ключи» (S6, спецификация §8): владелец разводит ключи машинного API — каждому
// читателю свой ключ, чтобы отозвать одного, не отрубив остальных. Управление закрыто
// редакторам (гейт маршрута 'permission' => 'manage'): ключи дают доступ к отчётам, поэтому
// ими распоряжается тот же уровень доступа, что и самими отчётами.
//
// Экран отвечает на GET (список) и POST (создание и отзыв). Секрет печатается ровно один раз:
// после создания он кладётся в сессию, POST уходит редиректом, а GET-экран показывает его и
// сразу забывает. Так обновление страницы не повторяет POST и не создаёт второй ключ — то же
// правило PRG, что у остальных действий раздела.

/**
 * Экран API-ключей: список, отозванные рядом со свежими, форма создания. Секрет только что
 * созданного ключа берётся из сессии и стирается при чтении: второй показ невозможен.
 *
 * @param array<string, string> $params
 */
function seoApiKeysPage(array $params = []): void
{
    startSession();
    $created = $_SESSION['seo_api_created'] ?? null;
    unset($_SESSION['seo_api_created']);

    render('seo/admin/api', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'keys' => seoApiKeysList(),
        'created' => is_array($created) && is_string($created['key'] ?? null) ? ['key' => $created['key']] : null,
    ], seoAdminAlert()), ['title' => 'API-ключи — SEO', 'noindex' => true]);
}

/**
 * Действия формы: создать ключ или отозвать. Поле op выбирает намерение — обе формы идут на
 * один адрес, и проверка токена видит одну точку сверки (как настроек статистики).
 *
 * Создание уходит редиректом на список, а секрет ждёт там один показ. Неизвестное значение op
 * не изменяет ничего: раньше всё, что не «key_create», попадало в отзыв, и опечатка гасила ключ.
 *
 * @param array<string, string> $params
 */
function seoApiKeysAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');

    if ($op === 'key_create') {
        $created = seoApiKeyCreate(postString('label'));

        if (!$created['ok']) {
            finishAppAction($created, '/seo/admin/api', '');
        }

        startSession();
        $_SESSION['seo_api_created'] = ['key' => $created['key']];
        $notice = (string) ($created['notice'] ?? '');
        redirectWithNotice('/seo/admin/api', $notice);
    }

    if ($op !== 'key_revoke') {
        finishAppAction(['ok' => false, 'error' => 'Неизвестное действие — ключ не изменён.'], '/seo/admin/api', '');

        return;
    }

    $result = seoApiKeyRevoke(postString('id'));
    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, '/seo/admin/api', $notice);
}
