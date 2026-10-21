<?php

declare(strict_types=1);

// Экраны статистики раздела «SEO и статистика» (S2). Четыре экрана: обзор, страницы,
// источники и настройки сбора. Все четыре читают агрегаты, а не сырьё, и все четыре
// подписаны методикой: «посетитель» здесь — подпись номера из localStorage, а не человек.
//
// Гейт маршрута ('permission' => 'login') пускает за клавиатуру, POST-действия проверяют
// токен (csrfCheck) и отдают свой результат finishAppAction(): отказ гасит список с
// причиной, успех — с ключом уведомления.

/**
 * Фильтры экрана: период и источник. Число вне своих границ берётся как запасное, а не как
 * «человек хотел другое»: молчаливая подмена периода хуже прямого отказа — человек увидел бы
 * не тот счёт, о котором думал.
 *
 * @return array{days: int, source: string, period: array{from: string, to: string, previousFrom: string, previousTo: string, days: int}}
 */
function seoStatsFilters(): array
{
    $rawDays = queryString('days', '');
    $days = is_string($rawDays) ? (int) $rawDays : 0;

    if (!in_array($days, SEO_STATS_PERIODS, true)) {
        $days = SEO_STATS_DEFAULT_PERIOD;
    }

    $rawSource = queryString('source', '');
    $source = is_string($rawSource) ? trim($rawSource) : '';

    if ($source !== '' && !isset(SEO_SOURCES[$source])) {
        $source = '';
    }

    return [
        'days' => $days,
        'source' => $source,
        'period' => seoStatsPeriod($days),
    ];
}

/**
 * Панель фильтров экрана: период и источник. Форма отправляется методом GET — фильтр ничего
 * не меняет на сервере, а значит, и токен ему не нужен.
 *
 * @return array{base: string, days: int, source: string}
 */
function seoStatsFilterState(string $base): array
{
    $filters = seoStatsFilters();

    return [
        'base' => $base,
        'days' => $filters['days'],
        'source' => $filters['source'],
    ];
}

/**
 * Обзор: сводка за период, график по дням, popular pages и источники.
 *
 * @param array<string, string> $params
 */
function seoStatsOverviewPage(array $params = []): void
{
    $filters = seoStatsFilters();
    $period = $filters['period'];
    $source = $filters['source'];

    $summary = seoStatsSummary($period['from'], $period['to'], $source);
    $previous = seoStatsSummary($period['previousFrom'], $period['previousTo'], $source);

    render('seo/admin/stats', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'filter' => seoStatsFilterState('/seo/admin/stats'),
        'summary' => $summary,
        'previous' => $previous,
        'daily' => seoStatsDaily($period['from'], $period['to'], $source),
        'pages' => seoStatsPages($period['from'], $period['to'], $source),
        'sources' => seoStatsSources($period['from'], $period['to']),
        'settings' => seoStatsSettings(),
    ], seoAdminAlert()), ['title' => 'Обзор — SEO', 'noindex' => true]);
}

/**
 * Страницы: popular pages за период с входами и выходами, рядом — кампании по меткам UTM.
 *
 * @param array<string, string> $params
 */
function seoStatsPagesPage(array $params = []): void
{
    $filters = seoStatsFilters();
    $period = $filters['period'];
    $source = $filters['source'];

    render('seo/admin/pages', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'filter' => seoStatsFilterState('/seo/admin/pages'),
        'pages' => seoStatsPages($period['from'], $period['to'], $source),
        'campaigns' => seoStatsCampaigns($period['from'], $period['to']),
        'settings' => seoStatsSettings(),
    ], seoAdminAlert()), ['title' => 'Страницы — SEO', 'noindex' => true]);
}

/**
 * Источники: откуда пришёл трафик и когда он приходит. Счётчики по источникам считаются
 * по агрегатам, уникальные посетители — по сырью.
 *
 * @param array<string, string> $params
 */
function seoStatsSourcesPage(array $params = []): void
{
    $filters = seoStatsFilters();
    $period = $filters['period'];

    render('seo/admin/sources', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'filter' => seoStatsFilterState('/seo/admin/sources'),
        'sources' => seoStatsSources($period['from'], $period['to']),
        'hourly' => seoStatsHourly($period['from'], $period['to']),
        'campaigns' => seoStatsCampaigns($period['from'], $period['to']),
        'settings' => seoStatsSettings(),
    ], seoAdminAlert()), ['title' => 'Источники — SEO', 'noindex' => true]);
}

/**
 * Настройки сбора: включение, время бездействия, срок хранения сырья, уважение DNT.
 *
 * @param array<string, string> $params
 */
function seoStatsSettingsPage(array $params = []): void
{
    $settings = seoStatsSettings();

    render('seo/admin/settings', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'filter' => seoStatsFilterState('/seo/admin/settings'),
        'settings' => $settings,
        'purge' => seoStatsPurgePreview($settings['retention']),
    ], seoAdminAlert()), ['title' => 'Настройки статистики — SEO', 'noindex' => true]);
}

/**
 * Действия настроек: сохранить сбор и очистить сырьё старше срока хранения. Намерение приходит
 * полем op, а не адресом: обе формы идут на один адрес, и страж проверки токена видит одну
 * точку сверки. Числа проверяет сам раздел: значение вне своих границ — не молчаливое
 * «как получится», а отказ с объяснением.
 *
 * @param array<string, string> $params
 */
function seoStatsSettingsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');

    if ($op === 'stats_purge') {
        // Агрегаты остаются: счётчики за прошлые дни не должны исчезать вместе с сырьём.
        $settings = seoStatsSettings();
        seoStatsPurge($settings['retention']);

        $result = ['ok' => true, 'error' => '', 'notice' => 'stats_purged'];
    } else {
        $result = seoStatsSaveSettings($_POST);
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/seo/admin/settings', $notice);
}
