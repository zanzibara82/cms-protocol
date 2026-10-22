<?php

declare(strict_types=1);

// Экран контентной аналитики (S5). По каждой странице: просмотры, сессии, конверсии и
// конверсионный курс за период. Контентная аналитика использует свои посещения (сео-счётчик)
// и зафиксированные конверсии — данные поисковика здесь не смешиваются.

/**
 * @param array<string, string> $params
 */
function seoContentPage(array $params = []): void
{
    $period = seoSearchPeriod();
    $range = seoSearchRange($period);
    $rows = seoContentReport($range['from'], $range['to']);

    $views = 0;
    $sessions = 0;
    $conversions = 0;
    foreach ($rows as $row) {
        $views += (int) $row['views'];
        $sessions += (int) $row['sessions'];
        $conversions += (int) $row['conversions'];
    }

    render('seo/admin/content', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'period' => $period,
        'periods' => SEO_SEARCH_PERIODS,
        'from' => $range['from'],
        'to' => $range['to'],
        'rows' => $rows,
        'totals' => [
            'views' => $views,
            'sessions' => $sessions,
            'conversions' => $conversions,
            'conversionRate' => $views > 0 ? round($conversions / $views * 100, 1) : 0.0,
        ],
    ], seoAdminAlert()), ['title' => 'Контент — SEO', 'noindex' => true]);
}
