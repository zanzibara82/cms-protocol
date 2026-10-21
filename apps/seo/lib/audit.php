<?php

declare(strict_types=1);

// Аудит SEO раздела «SEO и статистика» (S3). Проверяются данные, которые у нас есть: свои
// метаданные и адреса карты сайта. Внешний обход сайта не делается и содержание чужих
// разделов не разбирается — выдуманная оценка чужой страницы была бы враньём.
//
// Каждая находка живёт в таблице: у неё есть важность, описание, рекомендация, дата
// обнаружения и история (первое и последнее обнаружение, число повторов, отметка об
// исправлении). Повторный запуск не плодит дубликаты, а обновляет историю.

const SEO_AUDIT_SEVERITIES = [
    'critical' => 'Критично',
    'warning' => 'Предупреждение',
    'recommendation' => 'Рекомендация',
];

const SEO_AUDIT_CATALOGUE = [
    'missing_title' => [
        'severity' => 'warning',
        'title' => 'Заголовок страницы не задан',
        'description' => 'У адреса нет своего заголовка: в выдаче он появится как имя файла или как заголовок раздела.',
        'recommendation' => 'Задайте заголовок на экране «Метаданные».',
    ],
    'missing_description' => [
        'severity' => 'recommendation',
        'title' => 'Описание страницы не задано',
        'description' => 'Без описания сниппет в выдаче собирается поисковиком из случайных слов страницы.',
        'recommendation' => 'Задайте описание на экране «Метаданные».',
    ],
    'title_too_long' => [
        'severity' => 'recommendation',
        'title' => 'Заголовок длиннее сниппета',
        'description' => 'Заголовок длиннее 60 знаков: поисковик обрежет его, и смысл конца пропадёт.',
        'recommendation' => 'Сократите заголовок до 60 знаков.',
    ],
    'description_too_long' => [
        'severity' => 'recommendation',
        'title' => 'Описание длиннее сниппета',
        'description' => 'Описание длиннее 160 знаков: поисковик обрежет его.',
        'recommendation' => 'Сократите описание до 160 знаков.',
    ],
    'duplicate_title' => [
        'severity' => 'warning',
        'title' => 'Заголовок повторяется на другом адресе',
        'description' => 'Одинаковые заголовки на разных адресах читаются поисковиком как одна страница с двумя адресами.',
        'recommendation' => 'Разведите заголовки или задайте канонический адрес на главный из них.',
    ],
    'duplicate_description' => [
        'severity' => 'warning',
        'title' => 'Описание повторяется на другом адресе',
        'description' => 'Одинаковые описания на разных адресах — признак копипаста метаданных.',
        'recommendation' => 'Опишите страницу своими словами или задайте канонический адрес.',
    ],
    'canonical_foreign' => [
        'severity' => 'critical',
        'title' => 'Канонический адрес указывает на чужой сайт',
        'description' => 'Страница объявляет главной чужой адрес: поисковик уберёт её из выдачи.',
        'recommendation' => 'Укажите канонический адрес нашего сайта или снимите каноник.',
    ],
    'canonical_other_page' => [
        'severity' => 'recommendation',
        'title' => 'Канонический адрес ведёт на другую страницу',
        'description' => 'Страница объявлена копией другой. Так объединяют дубли, но только если это задумано.',
        'recommendation' => 'Проверьте, что это действительно дубль, и оставьте каноник на месте.',
    ],
    'canonical_missing_target' => [
        'severity' => 'critical',
        'title' => 'Канонический адрес не найден среди страниц сайта',
        'description' => 'Каноник ведёт на адрес, которого нет ни в карте сайта, ни в справочнике метаданных.',
        'recommendation' => 'Создайте страницу по этому адресу или исправьте каноник.',
    ],
    'sitemap_noindex_conflict' => [
        'severity' => 'critical',
        'title' => 'Адрес и в карте сайта, и закрыт от индексации',
        'description' => 'Страница помечена noindex и при этом оставлена в карте сайта: указания противоречат друг другу.',
        'recommendation' => 'Уберите адрес из карты сайта или снимите запрет индексации.',
    ],
];

/**
 * Важность находки по коду проверки.
 */
function seoAuditSeverity(string $code): string
{
    $entry = SEO_AUDIT_CATALOGUE[$code] ?? null;

    return is_array($entry) ? (string) $entry['severity'] : 'recommendation';
}

/**
 * Известные адреса сайта: карта сайта плюс справочник метаданных. Только они считаются
 * «страницей нашего сайта» — по ним судим, куда ведёт каноник.
 *
 * @return list<string>
 */
function seoAuditKnownPaths(): array
{
    $paths = [];

    foreach (sitemapUrls() as $url) {
        $loc = seoMetaPath((string) ($url['loc'] ?? ''));

        if ($loc !== '') {
            $paths[$loc] = true;
        }
    }

    foreach (seoMetaList() as $row) {
        $loc = seoMetaPath((string) ($row['path'] ?? ''));

        if ($loc !== '') {
            $paths[$loc] = true;
        }
    }

    $list = array_keys($paths);
    sort($list);

    return $list;
}

/**
 * Путь из канонического адреса: наш адрес может быть записан полным или путём от корня.
 */
function seoAuditCanonicalPath(string $canonical): string
{
    $clean = trim($canonical);
    $base = rtrim((string) configValue('SITE_URL', ''), '/');

    if ($base !== '' && str_starts_with($clean, $base)) {
        $clean = substr($clean, strlen($base));
    }

    return seoMetaPath($clean);
}

/**
 * Находки по своим данным. Функция чистая: принимает строки метаданных и известные адреса,
 * возвращает находки — без базы и без записи. Так её можно проверить обычным тестом.
 *
 * @param list<array<string, mixed>> $rows
 * @param list<string> $knownPaths
 * @return list<array{code: string, path: string, severity: string, title: string, description: string, recommendation: string}>
 */
function seoAuditFindIssues(array $rows, array $knownPaths = []): array
{
    $issues = [];
    $titles = [];
    $descriptions = [];

    // Одинаковые заголовки и описания ищутся заранее: второй такой адрес — уже находка.
    foreach ($rows as $row) {
        $path = seoMetaPath((string) ($row['path'] ?? ''));

        if ($path === '') {
            continue;
        }

        $title = trim((string) ($row['title'] ?? ''));

        if ($title !== '') {
            $titles[mb_strtolower($title)][] = $path;
        }

        $description = trim((string) ($row['description'] ?? ''));

        if ($description !== '') {
            $descriptions[mb_strtolower($description)][] = $path;
        }
    }

    foreach ($rows as $row) {
        $path = seoMetaPath((string) ($row['path'] ?? ''));

        if ($path === '') {
            continue;
        }

        $title = trim((string) ($row['title'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $canonical = trim((string) ($row['canonical'] ?? ''));

        if ($title === '') {
            $issues[] = seoAuditIssue('missing_title', $path, []);
        } elseif (mb_strlen($title) > SEO_META_TITLE_RECOMMENDED) {
            $issues[] = seoAuditIssue('title_too_long', $path, [
                'Сейчас ' . mb_strlen($title) . ' знаков, в сниппете помещается около '
                . SEO_META_TITLE_RECOMMENDED . '.',
            ]);
        }

        if ($description === '') {
            $issues[] = seoAuditIssue('missing_description', $path, []);
        } elseif (mb_strlen($description) > SEO_META_DESCRIPTION_RECOMMENDED) {
            $issues[] = seoAuditIssue('description_too_long', $path, [
                'Сейчас ' . mb_strlen($description) . ' знаков, в сниппете помещается около '
                . SEO_META_DESCRIPTION_RECOMMENDED . '.',
            ]);
        }

        if ($canonical !== '') {
            if (!seoMetaIsOurAddress($canonical)) {
                $issues[] = seoAuditIssue('canonical_foreign', $path, [
                    'Каноник: ' . $canonical . '.',
                ]);
            } else {
                $target = seoAuditCanonicalPath($canonical);

                if ($target !== $path) {
                    $issues[] = seoAuditIssue('canonical_other_page', $path, [
                        'Каноник: ' . $target . '.',
                    ]);
                }

                if ($knownPaths !== [] && !in_array($target, $knownPaths, true)) {
                    $issues[] = seoAuditIssue('canonical_missing_target', $path, [
                        'Каноник: ' . $target . ' — такого адреса среди страниц сайта нет.',
                    ]);
                }
            }
        }

        if ((int) ($row['noindex'] ?? 0) === 1 && (int) ($row['in_sitemap'] ?? 0) === 1) {
            $issues[] = seoAuditIssue('sitemap_noindex_conflict', $path, []);
        }

        $titleKey = mb_strtolower($title);

        if ($title !== '' && count($titles[$titleKey] ?? []) > 1) {
            $issues[] = seoAuditIssue('duplicate_title', $path, [
                'Тот же заголовок стоит на: ' . implode(', ', seoAuditOtherPaths($titles[$titleKey], $path)) . '.',
            ]);
        }

        $descriptionKey = mb_strtolower($description);

        if ($description !== '' && count($descriptions[$descriptionKey] ?? []) > 1) {
            $issues[] = seoAuditIssue('duplicate_description', $path, [
                'То же описание стоит на: ' . implode(', ', seoAuditOtherPaths($descriptions[$descriptionKey], $path)) . '.',
            ]);
        }
    }

    return $issues;
}

/**
 * Остальные адреса группы: свой адрес в перечисление не попадает.
 *
 * @param list<string> $paths
 * @return list<string>
 */
function seoAuditOtherPaths(array $paths, string $current): array
{
    $others = [];

    foreach ($paths as $path) {
        if ($path !== $current) {
            $others[] = $path;
        }
    }

    return $others;
}

/**
 * Находка по каталогу проверок: описание и рекомендация берутся оттуда, а подробности
 * добавляются сверху.
 *
 * @param list<string> $details
 * @return array{code: string, path: string, severity: string, title: string, description: string, recommendation: string}
 */
function seoAuditIssue(string $code, string $path, array $details): array
{
    $entry = SEO_AUDIT_CATALOGUE[$code] ?? [
        'severity' => 'recommendation',
        'title' => 'Замечание к метаданным',
        'description' => '',
        'recommendation' => 'Проверьте адрес вручную.',
    ];

    $description = (string) $entry['description'];

    if ($details !== []) {
        $description .= ' ' . implode(' ', $details);
    }

    return [
        'code' => $code,
        'path' => $path,
        'severity' => (string) $entry['severity'],
        'title' => (string) $entry['title'],
        'description' => mb_substr($description, 0, 500),
        'recommendation' => mb_substr((string) $entry['recommendation'], 0, 500),
    ];
}

/**
 * Запуск аудита: находки записываются, пропавшие — отмечаются исправленными. История
 * сохраняется: исправленная находка остаётся в списке с отметкой, когда её видели в последний
 * раз.
 *
 * @return array{open: int, fixed: int, fresh: int, total: int}
 */
function seoAuditRun(): array
{
    $rows = seoMetaList();
    $known = seoAuditKnownPaths();
    $issues = seoAuditFindIssues($rows, $known);
    $stamp = utcStamp(nowUtc());
    $fresh = 0;
    $fixed = 0;
    $found = [];

    foreach ($issues as $issue) {
        $key = $issue['code'] . '|' . $issue['path'];
        $found[$key] = true;
        $existing = one(
            'SELECT id, status FROM ' . t('seo_audit_issue') . ' WHERE code = ? AND path = ?',
            [$issue['code'], $issue['path']]
        );

        if ($existing === null) {
            insert('seo_audit_issue', [
                'id' => newId(),
                'code' => $issue['code'],
                'path' => $issue['path'],
                'severity' => $issue['severity'],
                'title' => $issue['title'],
                'description' => $issue['description'],
                'recommendation' => $issue['recommendation'],
                'status' => 'open',
                'seen_count' => 1,
                'first_seen_at' => $stamp,
                'last_seen_at' => $stamp,
                'fixed_at' => null,
            ]);
            $fresh++;

            continue;
        }

        run(
            'UPDATE ' . t('seo_audit_issue') . ' SET severity = ?, title = ?, description = ?, recommendation = ?,'
            . ' status = ?, seen_count = seen_count + 1, last_seen_at = ?, fixed_at = NULL WHERE id = ?',
            [
                $issue['severity'],
                $issue['title'],
                $issue['description'],
                $issue['recommendation'],
                'open',
                $stamp,
                (string) $existing['id'],
            ]
        );
    }

    foreach (seoAuditIssues() as $row) {
        $key = (string) $row['code'] . '|' . (string) $row['path'];

        if (isset($found[$key])) {
            continue;
        }

        // Уже отмеченная исправленной находка повторно не трогается: иначе каждый прогон заново
        // ставил бы ей дату исправления и счётчик поправок рос бы сам по себе.
        if ((string) $row['status'] === 'fixed') {
            continue;
        }

        run(
            'UPDATE ' . t('seo_audit_issue') . ' SET status = ?, fixed_at = ? WHERE id = ?',
            ['fixed', $stamp, (string) $row['id']]
        );
        $fixed++;
    }

    $open = 0;
    $all = 0;

    foreach (seoAuditIssues() as $row) {
        $all++;

        if ((string) $row['status'] === 'open') {
            $open++;
        }
    }

    return [
        'open' => $open,
        'fixed' => $fixed,
        'fresh' => $fresh,
        'total' => count($issues),
    ];
}

/**
 * Находки аудита: сначала открытые и самые важные, потом исправленные.
 *
 * @return list<array<string, mixed>>
 */
function seoAuditIssues(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, code, path, severity, title, description, recommendation, status, seen_count,'
        . ' first_seen_at, last_seen_at, fixed_at FROM ' . t('seo_audit_issue')
        . " ORDER BY status = 'open' DESC, FIELD(severity, 'critical', 'warning', 'recommendation'), path"
    );

    return $rows;
}

/**
 * Счётчик находок по важности: экран показывает, сколько чего.
 *
 * @return array{critical: int, warning: int, recommendation: int, open: int, resolved: int}
 */
function seoAuditSummary(): array
{
    $summary = ['critical' => 0, 'warning' => 0, 'recommendation' => 0, 'open' => 0, 'resolved' => 0];

    foreach (seoAuditIssues() as $row) {
        $severity = (string) $row['severity'];
        $status = (string) $row['status'];

        if (isset($summary[$severity])) {
            $summary[$severity]++;
        }

        if ($status === 'open') {
            $summary['open']++;
        } else {
            $summary['resolved']++;
        }
    }

    return $summary;
}
