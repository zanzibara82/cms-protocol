<?php

declare(strict_types=1);

// Цели и конверсии (S5, спецификация §5 «Конверсии и события»). Цель — правило: посещение
// адреса по шаблону (точное совпадение или префикс), опционально с разрезом по источнику и
// устройству, считается конверсией. Конверсия считается один раз на сессию и цель — защита
// от повторов при обновлении страницы (§11: защита от повторов конверсий).
//
// Свои посещения и конверсии не смешиваются с данными поисковика (S4): конверсия фиксирует
// поведение нашего посетителя, а не то, что отдал чужой API.
//
// Воронки и контентная аналитика строятся на тех же посещениях (tbl_seo_visit) — разрез по
// адресу и источнику, без личных данных: адрес посетителя не хранится, подпись посетителя —
// не человек.

const SEO_GOAL_TYPE_PAGE_VIEW = 'page_view';
const SEO_GOAL_TYPE_EVENT = 'event';

const SEO_GOAL_MATCH_PREFIX = 'prefix';
const SEO_GOAL_MATCH_EXACT = 'exact';

const SEO_GOALS_PERIODS = [7, 30, 90];
const SEO_GOALS_PERIOD_DEFAULT = 30;

/** @return array<string, string> */
function seoGoalTypes(): array
{
    return [
        SEO_GOAL_TYPE_PAGE_VIEW => 'Просмотр страницы',
        SEO_GOAL_TYPE_EVENT => 'Событие',
    ];
}

/** @return array<string, string> */
function seoGoalMatchTypes(): array
{
    return [
        SEO_GOAL_MATCH_PREFIX => 'Адрес начинается с шаблона',
        SEO_GOAL_MATCH_EXACT => 'Точное совпадение адреса',
    ];
}

/** @return list<array<string, mixed>> */
function seoGoals(): array
{
    $rows = all(
        'SELECT g.*, COALESCE(SUM(cd.conversions), 0) AS conversions'
        . ' FROM ' . t('seo_goal') . ' g'
        . ' LEFT JOIN ' . t('seo_conversion_day') . ' cd ON cd.goal_id = g.id'
        . ' GROUP BY g.id, g.name, g.type, g.pattern, g.match_type, g.source, g.device,'
        . ' g.enabled, g.created_at, g.updated_at'
        . ' ORDER BY conversions DESC, g.name'
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'pattern' => (string) $row['pattern'],
            'matchType' => (string) $row['match_type'],
            'source' => (string) ($row['source'] ?? ''),
            'device' => (string) ($row['device'] ?? ''),
            'enabled' => (int) $row['enabled'] === 1,
            'conversions' => (int) $row['conversions'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    return $out;
}

/**
 * Проверка цели против посещения: адрес, источник, устройство.
 *
 * @param array<string, mixed> $goal
 */
function seoGoalMatch(array $goal, string $path, string $source, string $device): bool
{
    if ((int) ($goal['enabled'] ?? 0) !== 1) {
        return false;
    }

    $pattern = (string) ($goal['pattern'] ?? '');
    $match = ($goal['match_type'] ?? SEO_GOAL_MATCH_PREFIX) === SEO_GOAL_MATCH_EXACT
        ? $path === $pattern
        : str_starts_with($path, $pattern);

    if (!$match) {
        return false;
    }

    $goalSource = (string) ($goal['source'] ?? '');
    if ($goalSource !== '' && $goalSource !== $source) {
        return false;
    }

    $goalDevice = (string) ($goal['device'] ?? '');
    if ($goalDevice !== '' && $goalDevice !== $device) {
        return false;
    }

    return true;
}

/**
 * @param array<string, string> $values
 * @return array{ok: bool, notice: string, error: string}
 */
function seoGoalCreate(array $values): array
{
    $name = mb_substr(collapseGoalText((string) ($values['name'] ?? '')), 0, 190);
    $pattern = mb_substr(collapseGoalText((string) ($values['pattern'] ?? '')), 0, 255);
    $type = (string) ($values['type'] ?? SEO_GOAL_TYPE_PAGE_VIEW);
    $matchType = (string) ($values['match_type'] ?? SEO_GOAL_MATCH_PREFIX);
    $source = mb_substr(trim((string) ($values['source'] ?? '')), 0, 32);
    $device = mb_substr(trim((string) ($values['device'] ?? '')), 0, 16);

    if ($name === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Название цели не задано.'];
    }

    if (!array_key_exists($type, seoGoalTypes())) {
        return ['ok' => false, 'notice' => '', 'error' => 'Неизвестный тип цели.'];
    }

    if (!array_key_exists($matchType, seoGoalMatchTypes())) {
        return ['ok' => false, 'notice' => '', 'error' => 'Неизвестный тип сравнения.'];
    }

    if ($pattern === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Шаблон адреса не задан.'];
    }

    if (patternContainsUnsafe($pattern)) {
        return ['ok' => false, 'notice' => '', 'error' => 'Шаблон содержит недопустимые символы.'];
    }

    $existing = one('SELECT id FROM ' . t('seo_goal') . ' WHERE name = ?', [$name]);
    if ($existing !== null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Цель с таким названием уже есть.'];
    }

    $id = newId();
    $stamp = utcStamp(nowUtc());

    insert('seo_goal', [
        'id' => $id,
        'name' => $name,
        'type' => $type,
        'pattern' => $pattern,
        'match_type' => $matchType,
        'source' => $source,
        'device' => $device,
        'enabled' => 1,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]);

    logInfo('seo.goal_created', ['goalId' => $id, 'name' => $name, 'pattern' => $pattern]);

    return ['ok' => true, 'notice' => 'goal_created', 'error' => ''];
}

/**
 * Включение/выключение цели.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function seoGoalToggle(string $id): array
{
    $row = one('SELECT id, enabled FROM ' . t('seo_goal') . ' WHERE id = ?', [$id]);

    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Цели уже нет — обновите страницу.'];
    }

    $enabled = (int) $row['enabled'] === 1 ? 0 : 1;

    run(
        'UPDATE ' . t('seo_goal') . ' SET enabled = ?, updated_at = ? WHERE id = ?',
        [$enabled, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('seo.goal_toggled', ['goalId' => $id, 'enabled' => $enabled]);

    return ['ok' => true, 'notice' => $enabled === 1 ? 'goal_enabled' : 'goal_disabled', 'error' => ''];
}

/**
 * Удаление цели. Конверсии цели остаются в сырье (история не пересчитывается), а агрегат по
 * целям удаляется — иначе в отчётах остались бы цифры от несуществующей цели.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function seoGoalDelete(string $id): array
{
    $row = one('SELECT id, name FROM ' . t('seo_goal') . ' WHERE id = ?', [$id]);

    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Цели уже нет — обновите страницу.'];
    }

    run('DELETE FROM ' . t('seo_conversion_day') . ' WHERE goal_id = ?', [(string) $row['id']]);
    run('DELETE FROM ' . t('seo_goal') . ' WHERE id = ?', [(string) $row['id']]);

    logInfo('seo.goal_deleted', ['goalId' => $id, 'name' => (string) $row['name']]);

    return ['ok' => true, 'notice' => 'goal_deleted', 'error' => ''];
}

/**
 * Запись конверсии посещения. Цель считается один раз на сессию и цель, поэтому обновление
 * страницы не плодит повторов. Агрегат по дню увеличивается только когда строка реально
 * вставлена.
 *
 * @param string $sessionId
 */
function seoStatsRecordConversion(
    string $sessionId,
    string $visitorId,
    string $path,
    string $source,
    string $device,
    string $stamp,
    string $day
): void {
    $goals = all('SELECT * FROM ' . t('seo_goal') . ' WHERE enabled = 1');

    foreach ($goals as $goal) {
        if (!seoGoalMatch($goal, $path, $source, $device)) {
            continue;
        }

        $inserted = run(
            'INSERT IGNORE INTO ' . t('seo_conversion')
            . ' (id, goal_id, session_id, visitor_id, path, day, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                newId(),
                (string) $goal['id'],
                $sessionId,
                $visitorId,
                $path,
                $day,
                $stamp,
            ]
        );

        if ($inserted > 0) {
            run(
                'INSERT INTO ' . t('seo_conversion_day')
                . ' (goal_id, day, conversions) VALUES (?, ?, 1)'
                . ' ON DUPLICATE KEY UPDATE conversions = conversions + 1',
                [(string) $goal['id'], $day]
            );
        }
    }
}

/**
 * Конверсии целей за период, по целям.
 *
 * @return list<array<string, mixed>>
 */
function seoGoalConversions(string $from, string $to): array
{
    $rows = all(
        'SELECT g.id, g.name, COUNT(c.id) AS conversions'
        . ' FROM ' . t('seo_goal') . ' g'
        . ' LEFT JOIN ' . t('seo_conversion') . ' c ON c.goal_id = g.id AND c.day >= ? AND c.day <= ?'
        . ' GROUP BY g.id, g.name'
        . ' ORDER BY conversions DESC, g.name',
        [$from, $to]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'conversions' => (int) $row['conversions'],
        ];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function seoContentReport(string $from, string $to): array
{
    $rows = all(
        'SELECT path, SUM(views) AS views, SUM(sessions) AS sessions'
        . ' FROM ' . t('seo_stat_day')
        . ' WHERE day >= ? AND day <= ?'
        . ' GROUP BY path',
        [$from, $to]
    );

    $conversions = all(
        'SELECT path, COUNT(*) AS conversions'
        . ' FROM ' . t('seo_conversion')
        . ' WHERE day >= ? AND day <= ?'
        . ' GROUP BY path',
        [$from, $to]
    );

    $conversionByPath = [];
    foreach ($conversions as $row) {
        $conversionByPath[(string) $row['path']] = (int) $row['conversions'];
    }

    $out = [];
    foreach ($rows as $row) {
        $path = (string) $row['path'];
        $views = (int) $row['views'];
        $sessions = (int) $row['sessions'];
        $conversions = $conversionByPath[$path] ?? 0;

        $out[] = [
            'path' => $path,
            'views' => $views,
            'sessions' => $sessions,
            'conversions' => $conversions,
            'conversionRate' => $views > 0 ? round($conversions / $views * 100, 1) : 0.0,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        return (int) $b['views'] <=> (int) $a['views'];
    });

    return $out;
}

/** @return list<array<string, mixed>> */
function seoFunnels(): array
{
    $rows = all(
        'SELECT f.id, f.name, f.enabled, f.created_at, COUNT(s.id) AS steps'
        . ' FROM ' . t('seo_funnel') . ' f'
        . ' LEFT JOIN ' . t('seo_funnel_step') . ' s ON s.funnel_id = f.id'
        . ' GROUP BY f.id, f.name, f.enabled, f.created_at'
        . ' ORDER BY f.name'
    );

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'enabled' => (int) $row['enabled'] === 1,
            'steps' => (int) $row['steps'],
            'createdAt' => (string) $row['created_at'],
        ];
    }

    return $out;
}

/**
 * @param array<string, string> $values
 * @return array{ok: bool, notice: string, error: string}
 */
function seoFunnelCreate(array $values): array
{
    $name = mb_substr(collapseGoalText((string) ($values['name'] ?? '')), 0, 190);

    if ($name === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Название воронки не задано.'];
    }

    $existing = one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', [$name]);
    if ($existing !== null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Воронка с таким названием уже есть.'];
    }

    $id = newId();
    $stamp = utcStamp(nowUtc());

    insert('seo_funnel', [
        'id' => $id,
        'name' => $name,
        'enabled' => 1,
        'created_at' => $stamp,
    ]);

    logInfo('seo.funnel_created', ['funnelId' => $id, 'name' => $name]);

    return ['ok' => true, 'notice' => 'funnel_created', 'error' => ''];
}

/**
 * @return array{ok: bool, notice: string, error: string}
 */
function seoFunnelToggle(string $id): array
{
    $row = one('SELECT id, enabled FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]);

    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Воронки уже нет — обновите страницу.'];
    }

    $enabled = (int) $row['enabled'] === 1 ? 0 : 1;

    run(
        'UPDATE ' . t('seo_funnel') . ' SET enabled = ?, created_at = ? WHERE id = ?',
        [$enabled, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('seo.funnel_toggled', ['funnelId' => $id, 'enabled' => $enabled]);

    return ['ok' => true, 'notice' => $enabled === 1 ? 'funnel_enabled' : 'funnel_disabled', 'error' => ''];
}

/**
 * @return array{ok: bool, notice: string, error: string}
 */
function seoFunnelDelete(string $id): array
{
    $row = one('SELECT id, name FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]);

    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Воронки уже нет — обновите страницу.'];
    }

    run('DELETE FROM ' . t('seo_funnel_step') . ' WHERE funnel_id = ?', [(string) $row['id']]);
    run('DELETE FROM ' . t('seo_funnel') . ' WHERE id = ?', [(string) $row['id']]);

    logInfo('seo.funnel_deleted', ['funnelId' => $id, 'name' => (string) $row['name']]);

    return ['ok' => true, 'notice' => 'funnel_deleted', 'error' => ''];
}

/**
 * @param array<string, string> $values
 * @return array{ok: bool, notice: string, error: string}
 */
function seoFunnelStepCreate(array $values): array
{
    $funnelId = (string) ($values['funnel_id'] ?? '');
    $name = mb_substr(collapseGoalText((string) ($values['name'] ?? '')), 0, 190);
    $pattern = mb_substr(collapseGoalText((string) ($values['pattern'] ?? '')), 0, 255);
    $matchType = (string) ($values['match_type'] ?? SEO_GOAL_MATCH_PREFIX);
    $position = (int) ($values['position'] ?? 0);

    if ($funnelId === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Воронка не выбрана.'];
    }

    if (one('SELECT id FROM ' . t('seo_funnel') . ' WHERE id = ?', [$funnelId]) === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Воронки уже нет — обновите страницу.'];
    }

    if ($name === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Название шага не задано.'];
    }

    if (!array_key_exists($matchType, seoGoalMatchTypes())) {
        return ['ok' => false, 'notice' => '', 'error' => 'Неизвестный тип сравнения.'];
    }

    if ($pattern === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Шаблон адреса не задан.'];
    }

    if (patternContainsUnsafe($pattern)) {
        return ['ok' => false, 'notice' => '', 'error' => 'Шаблон содержит недопустимые символы.'];
    }

    if ($position <= 0) {
        $max = (int) value('SELECT COALESCE(MAX(position), 0) FROM ' . t('seo_funnel_step') . ' WHERE funnel_id = ?', [$funnelId]);
        $position = $max + 1;
    }

    $id = newId();
    $stamp = utcStamp(nowUtc());

    insert('seo_funnel_step', [
        'id' => $id,
        'funnel_id' => $funnelId,
        'position' => $position,
        'name' => $name,
        'pattern' => $pattern,
        'match_type' => $matchType,
    ]);

    logInfo('seo.funnel_step_created', ['funnelId' => $funnelId, 'stepId' => $id, 'name' => $name]);

    return ['ok' => true, 'notice' => 'step_created', 'error' => ''];
}

/**
 * Отчёт воронки за период: по шагам — сессии, дошагшавшие до шага, и конверсия от предыдущего.
 *
 * @param array<string, mixed> $funnel
 * @return array{steps: list<array<string, mixed>>, sessions: int}
 */
function seoFunnelReport(array $funnel, string $from, string $to): array
{
    $steps = all(
        'SELECT id, name, pattern, match_type FROM ' . t('seo_funnel_step')
        . ' WHERE funnel_id = ? ORDER BY position, id',
        [(string) $funnel['id']]
    );

    $visits = all(
        'SELECT session_id, path FROM ' . t('seo_visit')
        . ' WHERE day >= ? AND day <= ? ORDER BY session_id, created_at',
        [$from, $to]
    );

    $bySession = [];
    foreach ($visits as $visit) {
        $bySession[(string) $visit['session_id']][] = (string) $visit['path'];
    }

    $maxStep = [];
    foreach ($bySession as $paths) {
        $step = 0;
        foreach ($paths as $path) {
            if ($step >= count($steps)) {
                break;
            }

            $expected = (string) ($steps[$step]['pattern'] ?? '');
            $matchType = (string) ($steps[$step]['match_type'] ?? SEO_GOAL_MATCH_PREFIX);
            $matched = $matchType === SEO_GOAL_MATCH_EXACT
                ? $path === $expected
                : str_starts_with($path, $expected);

            if ($matched) {
                $step++;
            }
        }

        $maxStep[] = $step;
    }

    $reached = [];
    $reached[0] = count($bySession);
    for ($k = 1; $k <= count($steps); $k++) {
        $reached[$k] = 0;
        foreach ($maxStep as $reachedStep) {
            if ($reachedStep >= $k) {
                $reached[$k]++;
            }
        }
    }

    $out = [];
    foreach ($steps as $index => $step) {
        $stepNumber = $index + 1;
        $count = $reached[$stepNumber] ?? 0;
        $previous = $reached[$stepNumber - 1] ?? 0;

        $out[] = [
            'position' => $stepNumber,
            'name' => (string) ($step['name'] ?? ''),
            'pattern' => (string) ($step['pattern'] ?? ''),
            'sessions' => $count,
            'rate' => $previous > 0 ? round($count / $previous * 100, 1) : 0.0,
        ];
    }

    return ['steps' => $out, 'sessions' => $reached[0]];
}

/**
 * Схлопывание пробелов в тексте цели/шага.
 */
function collapseGoalText(string $raw): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $raw));
}

/**
 * Шаблон цели — только произвольный текст адреса: без жёстких символов запроса и без
 * подстановок, которые могли бы изменить смысл правила после сохранения.
 */
function patternContainsUnsafe(string $pattern): bool
{
    return preg_match('/[\x00-\x1F\x7F<>\"\'#?]/', $pattern) === 1;
}
