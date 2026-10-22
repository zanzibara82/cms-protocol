<?php

declare(strict_types=1);

// Экран воронок (S5). Воронка — цепочка шагов по шаблонам адресов; отчёт показывает, сколько
// сессий дошагали до каждого шага и конверсию от шага к шагу. Считается из посещений за
// период: свои посещения, без данных поисковика.

/**
 * @param array<string, string> $params
 */
function seoFunnelsPage(array $params = []): void
{
    $period = seoSearchPeriod();
    $range = seoSearchRange($period);
    $funnels = seoFunnels();

    $selectedId = (string) queryString('funnel', '');
    $funnel = null;
    $report = null;

    if ($selectedId !== '') {
        $row = one('SELECT id, name, enabled, created_at FROM ' . t('seo_funnel') . ' WHERE id = ?', [$selectedId]);
        if ($row !== null) {
            $funnel = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'enabled' => (int) $row['enabled'] === 1,
            ];
            $report = seoFunnelReport($funnel, $range['from'], $range['to']);
        }
    }

    render('seo/admin/funnels', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'period' => $period,
        'periods' => SEO_SEARCH_PERIODS,
        'from' => $range['from'],
        'to' => $range['to'],
        'funnels' => $funnels,
        'funnel' => $funnel,
        'report' => $report,
    ], seoAdminAlert()), ['title' => 'Воронки — SEO', 'noindex' => true]);
}

/**
 * @param array<string, string> $params
 */
function seoFunnelsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');

    if ($op === 'funnel_create') {
        $result = seoFunnelCreate(['name' => postString('name')]);
    } elseif ($op === 'funnel_toggle') {
        $id = mb_substr(trim((string) postString('id')), 0, 26);
        $result = $id !== '' ? seoFunnelToggle($id) : ['ok' => false, 'error' => 'Воронка не выбрана.', 'notice' => ''];
    } elseif ($op === 'funnel_delete') {
        $id = mb_substr(trim((string) postString('id')), 0, 26);
        $result = $id !== '' ? seoFunnelDelete($id) : ['ok' => false, 'error' => 'Воронка не выбрана.', 'notice' => ''];
    } elseif ($op === 'funnel_step_create') {
        $result = seoFunnelStepCreate([
            'funnel_id' => postString('funnel_id'),
            'name' => postString('name'),
            'pattern' => postString('pattern'),
            'match_type' => postString('match_type'),
            'position' => postString('position'),
        ]);
    } else {
        $result = ['ok' => false, 'error' => 'Неизвестное действие с воронкой.', 'notice' => ''];
    }

    if (($result['ok'] ?? false) === false) {
        finishAppAction(['ok' => false, 'error' => (string) ($result['error'] ?? ''), 'notice' => ''], '/seo/admin/funnels', '');
        return;
    }

    $notice = (string) ($result['notice'] ?? '');
    $address = '/seo/admin/funnels';
    if ($notice !== '' && $op === 'funnel_step_create') {
        $funnelId = (string) postString('funnel_id');
        if ($funnelId !== '') {
            $address .= '?funnel=' . $funnelId;
        }
    }
    finishAppAction(['ok' => true, 'error' => '', 'notice' => $notice], $address, $notice);
}
