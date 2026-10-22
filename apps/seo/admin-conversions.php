<?php

declare(strict_types=1);

// Экран целей и конверсий (S5). Цель — правило: посещение адреса по шаблону считается
// конверсией. Конверсия считается один раз на сессию и цель, поэтому обновление страницы
// не плодит повторов. Все отчёты считаются системой из зафиксированных посещений — вручную
// в отчётах ничего не пишется.

/**
 * @param array<string, string> $params
 */
function seoConversionsPage(array $params = []): void
{
    $period = seoSearchPeriod();
    $range = seoSearchRange($period);
    $goals = seoGoals();
    $conversions = seoGoalConversions($range['from'], $range['to']);

    $conversionByGoal = [];
    foreach ($conversions as $row) {
        $conversionByGoal[(string) $row['id']] = (int) $row['conversions'];
    }

    $total = 0;
    foreach ($conversionByGoal as $count) {
        $total += $count;
    }

    render('seo/admin/conversions', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'period' => $period,
        'periods' => SEO_SEARCH_PERIODS,
        'from' => $range['from'],
        'to' => $range['to'],
        'goals' => $goals,
        'conversions' => $conversionByGoal,
        'total' => $total,
    ], seoAdminAlert()), ['title' => 'Конверсии — SEO', 'noindex' => true]);
}

/**
 * @param array<string, string> $params
 */
function seoConversionsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim((string) postString('id')), 0, 26);

    if ($op === 'goal_create') {
        $result = seoGoalCreate([
            'name' => postString('name'),
            'type' => postString('type'),
            'pattern' => postString('pattern'),
            'match_type' => postString('match_type'),
            'source' => postString('source'),
            'device' => postString('device'),
        ]);
    } elseif ($op === 'goal_toggle' && $id !== '') {
        $result = seoGoalToggle($id);
    } elseif ($op === 'goal_delete' && $id !== '') {
        $result = seoGoalDelete($id);
    } else {
        $result = ['ok' => false, 'error' => 'Неизвестное действие с целью.', 'notice' => ''];
    }

    if (($result['ok'] ?? false) === false) {
        finishAppAction(['ok' => false, 'error' => (string) ($result['error'] ?? ''), 'notice' => ''], '/seo/admin/conversions', '');
        return;
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction(['ok' => true, 'error' => '', 'notice' => $notice], '/seo/admin/conversions', $notice);
}
