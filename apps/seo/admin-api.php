<?php

declare(strict_types=1);

// Экран «API-ключи» (S6, спецификация §8): владелец разводит ключи машинного API — каждому
// читателю свой ключ, чтобы отозвать одного, не отрубив остальных. Управление закрыто
// редакторам (гейт маршрута 'permission' => 'manage'): ключи дают доступ к отчётам, поэтому
// ими распоряжается тот же уровень доступа, что и самими отчётами.
//
// Экран отвечает на GET (список) и POST (создание и отзыв). Секрет печатается ровно один раз
// на экране ответа формы (то же правило, что у секретов вебхуков ядра); потом его вернёт
// только отзыв и новый ключ.

/**
 * Экран API-ключей: список, отозванные рядом со свежими, форма создания.
 *
 * @param array<string, string> $params
 */
function seoApiKeysPage(array $params = []): void
{
    render('seo/admin/api', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'keys' => seoApiKeysList(),
    ], seoAdminAlert()), ['title' => 'API-ключи — SEO', 'noindex' => true]);
}

/**
 * Действия формы: создать ключ или отозвать. Поле op выбирает намерение — обе формы идут на
 * один адрес, и проверка токена видит одну точку сверки (как настроек статистики).
 *
 * При создании успех никогда не перенаправляет: экран ответа печатает секрет один раз, пока
 * headers ещё не отданы. Отказ — уходит в finishAppAction с причиной.
 *
 * @param array<string, string> $params
 */
function seoApiKeysAction(array $params = []): void
{
    csrfCheck();

    if (postString('op') === 'key_create') {
        $created = seoApiKeyCreate(postString('label'));

        if (!$created['ok']) {
            finishAppAction($created, '/seo/admin/api', '');
        }

        render('seo/admin/api-created', array_merge([
            'nav' => seoAdminNav('/seo/admin/api'),
            'notice' => seoAdminNotice(),
            'created' => $created,
        ], seoAdminAlert()), ['title' => 'Ключ создан — SEO', 'noindex' => true]);

        return;
    }

    $result = seoApiKeyRevoke(postString('id'));
    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, '/seo/admin/api', $notice);
}
