<?php

declare(strict_types=1);

// Экран «404 и редиректы» раздела «SEO и статистика» (S3). Журнал показывает, по каким
// несуществующим адресам приходят, а правила редиректов отвечают на них переходом.
//
// Гейт маршрута ('permission' => 'login') пускает за клавиатуру, POST-действия проверяют
// токен (csrfCheck) и отдают свой результат finishAppAction(): отказ гасит список с
// причиной, успех — с ключом уведомления.

/**
 * Экран «404 и редиректы»: журнал несуществующих адресов, поиск целевой страницы и
 * правила переходов.
 *
 * @param array<string, string> $params
 */
function seoMonitorPage(array $params = []): void
{
    $from = seoMetaPath((string) queryString('from', ''));
    $to = seoMetaPath((string) queryString('to', ''));
    $query = trim((string) queryString('q', ''));

    render('seo/admin/errors', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'misses' => seoMonitorMisses(),
        'redirects' => seoMonitorRedirects(),
        'candidates' => $query === '' ? [] : seoMonitorCandidates($query),
        'query' => $query,
        'form' => [
            'from' => $from,
            'to' => $to,
            'code' => 301,
        ],
    ], seoAdminAlert()), ['title' => '404 и редиректы — SEO', 'noindex' => true]);
}

/**
 * Действия экрана: завести правило, удалить правило, очистить журнал. Намерение приходит полем
 * op, а не адресом: все формы идут на один адрес, и страж проверки токена видит одну точку
 * сверки.
 *
 * @param array<string, string> $params
 */
function seoMonitorAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $result = ['ok' => false, 'error' => 'Неизвестное действие.', 'notice' => ''];

    if ($op === 'redirect_create') {
        $check = seoMonitorRedirectValidate($_POST);

        if ($check['errors'] === []) {
            $result = seoMonitorRedirectSave($check['values']);
        } else {
            $result = ['ok' => false, 'error' => implode(' ', $check['errors']), 'notice' => ''];
        }
    }

    if ($op === 'redirect_delete') {
        $result = seoMonitorRedirectDelete(mb_substr(trim((string) ($_POST['id'] ?? '')), 0, 26));
    }

    if ($op === 'errors_clear') {
        seoMonitorMissesClear();
        $result = ['ok' => true, 'error' => '', 'notice' => 'errors_cleared'];
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/seo/admin/errors', $notice);
}
