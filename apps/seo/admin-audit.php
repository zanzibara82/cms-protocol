<?php

declare(strict_types=1);

// Экран «Аудит» раздела «SEO и статистика» (S3). Проверка идёт по нашим данным: свои
// метаданные и адреса карты сайта. Внешний обход не делается, а чужие разделы не разбираются.
//
// Гейт маршрута ('permission' => 'login') пускает за клавиатуру, POST-действия проверяют
// токен (csrfCheck) и отдают свой результат finishAppAction().

/**
 * Экран «Аудит»: находки, их важность и история, плюс запуск проверки.
 *
 * @param array<string, string> $params
 */
function seoAuditPage(array $params = []): void
{
    render('seo/admin/audit', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'issues' => seoAuditIssues(),
        'summary' => seoAuditSummary(),
        'severities' => SEO_AUDIT_SEVERITIES,
    ], seoAdminAlert()), ['title' => 'Аудит — SEO', 'noindex' => true]);
}

/**
 * Действие экрана: запуск проверки. Проверка ничего не меняет на сайте — она только
 * пересчитывает находки по данным, которые уже есть, и обновляет их историю.
 *
 * @param array<string, string> $params
 */
function seoAuditAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $result = ['ok' => false, 'error' => 'Неизвестное действие.', 'notice' => ''];

    if ($op === 'audit_run') {
        seoAuditRun();
        $result = ['ok' => true, 'error' => '', 'notice' => 'audit_done'];
    }

    $notice = (string) $result['notice'];
    finishAppAction($result, '/seo/admin/audit', $notice);
}
