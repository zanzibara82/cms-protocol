<?php

declare(strict_types=1);

// Журнал 404 и редиректы раздела «SEO и статистика» (S3).
//
// Журнал отвечает на вопрос «куда люди приходят по несуществующим адресам», а редирект — на
// вопрос «что с этим делать». Связка честная: адрес, на который заведено правило, отвечает
// редиректом и в журнал больше не попадает, а число его прежних обращений остаётся —
// видно, сколько трафика этот адрес приносил.
//
// Приватность: в журнал пишется только адрес без строки запроса (иначе в журнал уехали бы
// чувствительные параметры), хост страницы-источника без полного адреса и время. Адрес
// посетителя не пишется, и сам журнал отдаётся только редактору.

const SEO_MONITOR_OPTION_LOG = 'seo_monitor_log';
const SEO_MONITOR_PATH_MAX = 255;
const SEO_MONITOR_MAX_ROWS = 2000;
const SEO_MONITOR_CHAIN_MAX = 5;
const SEO_MONITOR_CANDIDATE_MAX = 20;
const SEO_MONITOR_PAGE_MAX = 100;

/**
 * Вести ли журнал 404. По умолчанию ведём: без журнала нечего превращать в редирект, а
 * вопрос «куда приходят» остаётся без ответа.
 */
function seoMonitorLogEnabled(): bool
{
    return getOption(SEO_MONITOR_OPTION_LOG, '1') !== '0';
}

/**
 * Адрес, который стоит записывать в журнал.
 *
 * Три отказа: пустой адрес, слишком длинный (обрезанный адрес — не адрес) и служебный путь
 * (страница входа, каталог раздела, служебные файлы ядра — их отсутствие ничего не говорит
 * о содержании сайта). Запрос файла — тоже не страница: у него есть расширение в последнем
 * куске адреса, и в журнал он не попадает.
 */
function seoMonitorPathIsWatchable(string $path): bool
{
    if ($path === '' || mb_strlen($path) > SEO_MONITOR_PATH_MAX) {
        return false;
    }

    if (isRobotsDisallowed($path)) {
        return false;
    }

    $last = strrchr($path, '/');
    $last = $last === false ? $path : $last;

    return !str_contains($last, '.');
}

/**
 * Запись обращения к несуществующему адресу. Повторное обращение увеличивает счётчик, а не
 * плодит строки: журнал читается как список адресов, а не как лента запросов.
 */
function seoMonitorRecordMiss(string $path): void
{
    // Строка запроса и якорь отбрасываются здесь, а не у вызывающего: в журнал попадает
    // адрес, и только он, — иначе туда уехали бы чувствительные параметры.
    $cut = explode('?', $path, 2)[0];
    $cut = explode('#', $cut, 2)[0];
    $clean = seoMetaPath($cut);

    if (!seoMonitorLogEnabled() || !seoMonitorPathIsWatchable($clean)) {
        return;
    }

    $referrer = seoStatsReferrerHost((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $stamp = utcStamp(nowUtc());

    // Одна строка на адрес держится уникальным ключом, и вставка с обновлением на месте
    // честнее select-then-insert: два первых одновременных запроса прочли бы оба «нет
    // такой строки», и второй INSERT падал бы 1062-м исключением невхости — смотрющийся
    // посетителю HTTP 500 на несуществующем адресе. Повторное обращение только
    // увеличивает счётчик и обновляет отметку, referrer первой встречи остаётся.
    // Форма VALUES() в обновлении — та же, что в setOption() (core/options.php), и по той
    // же причине: с MySQL 8.0.20 она помечена устаревшей, а форма с псевдонимом строки
    // работает только с MySQL 8.0.19+, проект же держит и MariaDB. Не «чинить» на
    // псевдоним — сломается MariaDB.
    run(
        'INSERT INTO ' . t('seo_404') . ' (id, path, hits, referrer_host, first_seen_at, last_seen_at)'
        . ' VALUES (?, ?, 1, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen_at = VALUES(last_seen_at)',
        [newId(), $clean, $referrer !== '' ? $referrer : null, $stamp, $stamp]
    );

    // Срез вызывается после каждой записи: внутри один COUNT и ранний выход, пока журнал
    // не налился сверх границы.
    seoMonitorTrim();
}

/**
 * Журнал не должен расти без предела из-за бота, который перебирает адреса. Срезаются самые
 * старые строки: давно не вспоминаемый адрес важнее свежего шума.
 */
function seoMonitorTrim(): void
{
    $count = (int) value('SELECT COUNT(*) FROM ' . t('seo_404'));

    if ($count <= SEO_MONITOR_MAX_ROWS) {
        return;
    }

    run(
        'DELETE FROM ' . t('seo_404') . ' WHERE id NOT IN ('
        . 'SELECT id FROM (SELECT id FROM ' . t('seo_404')
        . ' ORDER BY last_seen_at DESC, hits DESC LIMIT ?) AS keep_rows)',
        [SEO_MONITOR_MAX_ROWS]
    );
}

/**
 * Журнал 404: сначала самые востребованные адреса, потом самые свежие.
 *
 * @return list<array<string, mixed>>
 */
function seoMonitorMisses(int $limit = SEO_MONITOR_PAGE_MAX): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, path, hits, referrer_host, first_seen_at, last_seen_at FROM ' . t('seo_404')
        . ' ORDER BY hits DESC, last_seen_at DESC LIMIT ?',
        [$limit]
    );

    return $rows;
}

/**
 * Очистка журнала: сколько адресов было стёрто.
 */
function seoMonitorMissesClear(): int
{
    $count = (int) value('SELECT COUNT(*) FROM ' . t('seo_404'));
    run('DELETE FROM ' . t('seo_404'));

    return $count;
}

/**
 * @return list<array<string, mixed>>
 */
function seoMonitorRedirects(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, from_path, to_path, code, created_at, updated_at FROM ' . t('seo_redirect')
        . ' ORDER BY from_path'
    );

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function seoMonitorRedirectByPath(string $path): ?array
{
    $clean = seoMetaPath($path);

    if ($clean === '') {
        return null;
    }

    return one(
        'SELECT id, from_path, to_path, code FROM ' . t('seo_redirect') . ' WHERE from_path = ?',
        [$clean]
    );
}

/**
 * @return array<string, mixed>|null
 */
function seoMonitorRedirectById(string $id): ?array
{
    $clean = mb_substr(trim($id), 0, 26);

    if ($clean === '') {
        return null;
    }

    return one(
        'SELECT id, from_path, to_path, code FROM ' . t('seo_redirect') . ' WHERE id = ?',
        [$clean]
    );
}

/**
 * Цепочка редиректов, начинающаяся с адреса: куда ведёт и через сколько шагов.
 *
 * Шаги ограничены: цепочка длиннее пяти переходов — это уже не «адрес переехал», а
 * ошибочная настройка, и обходить её придётся по кругу.
 *
 * @return list<string>
 */
function seoMonitorChain(string $start): array
{
    $chain = [];
    $current = seoMetaPath($start);
    $steps = 0;

    while ($current !== '' && $steps <= SEO_MONITOR_CHAIN_MAX) {
        $redirect = seoMonitorRedirectByPath($current);

        if ($redirect === null) {
            break;
        }

        $current = seoMetaPath((string) $redirect['to_path']);
        $chain[] = $current;
        $steps++;
    }

    return $chain;
}

/**
 * Проверка правила редиректа. Проверяется всё, что может испортить переход: адрес ведёт сам
 * на себя, ведёт на чужой домен, замыкает цепочку или продолжает слишком длинную.
 *
 * @param array<string, mixed> $post
 * @return array{errors: list<string>, values: array{from: string, to: string, code: int}}
 */
function seoMonitorRedirectValidate(array $post): array
{
    $errors = [];
    $rawFrom = trim((string) ($post['from'] ?? ''));
    $rawTo = trim((string) ($post['to'] ?? ''));
    $from = seoMetaPath($rawFrom);
    $to = seoMetaPath($rawTo);
    $code = (int) ($post['code'] ?? 301);

    if ($from === '' || $from === '/') {
        $errors[] = 'Укажите адрес, с которого переводить.';
    }

    if ($to === '' || $to === '/') {
        $errors[] = 'Укажите адрес, на который переводить.';
    }

    // Переход ведёт внутри нашего сайта: путь от корня. Чужой домен в адресе означал бы, что
    // правило отправляет посетителя прочь, — а журнал 404 для этого и не предназначен.
    if ($rawFrom !== '' && !str_starts_with($rawFrom, '/')) {
        $errors[] = 'Адрес, с которого переводить, — путь от корня нашего сайта.';
    }

    if ($rawTo !== '' && !str_starts_with($rawTo, '/')) {
        $errors[] = 'Адрес, на который переводить, — путь от корня нашего сайта.';
    }

    if (!in_array($code, [301, 302], true)) {
        $errors[] = 'Код ответа — 301 (переехали навсегда) или 302 (временно).';
    }

    if ($from !== '' && $to !== '' && $from === $to) {
        $errors[] = 'Редирект не может вести сам на себя.';
    }

    if ($from !== '' && $to !== '') {
        $chain = seoMonitorChain($to);

        if (in_array($from, $chain, true)) {
            $errors[] = 'Такой редирект замыкает цепочку: адрес уже ведёт на ' . $to . '.';
        }

        if (count($chain) >= SEO_MONITOR_CHAIN_MAX) {
            $errors[] = 'Цепочка редиректов уже слишком длинная — сначала сократите её.';
        }
    }

    if (isRobotsDisallowed($from)) {
        $errors[] = 'Служебный адрес переводить нельзя.';
    }

    return [
        'errors' => $errors,
        'values' => ['from' => $from, 'to' => $to, 'code' => $code],
    ];
}

/**
 * Сохранение правила: адрес, который уже описан, обновляется, а не дублируется.
 *
 * @param array{from: string, to: string, code: int} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function seoMonitorRedirectSave(array $values): array
{
    $existing = seoMonitorRedirectByPath((string) $values['from']);
    $stamp = utcStamp(nowUtc());

    if ($existing !== null) {
        seoMonitorRedirectRowUpdate($existing, (array) $values);

        return ['ok' => true, 'error' => '', 'notice' => 'redirect_saved'];
    }

    // Select-then-insert гонится в отказ двумя одновременными сохранениями: оба прочли «нет
    // строки», и проигравший INSERT получает отказ уникального ключа. Семантика экрана —
    // «вторая запись на тот же адрес обновляет первую», поэтому проигравший гонку дообновляет
    // строку победителя; если правило меж тем удалили — честная причина, а не 500.
    try {
        insert('seo_redirect', [
            'id' => newId(),
            'from_path' => (string) $values['from'],
            'to_path' => (string) $values['to'],
            'code' => (int) $values['code'],
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    } catch (Throwable $insertError) {
        // Страж помощника перебрасывает чужие отказы до всякого перечитывания; формулировка
        // отвечает за случай, когда правило меж тем удалили.
        $reason = duplicateReason($insertError, 'Это правило только что правят — обновите список и повторите.');

        $raced = seoMonitorRedirectByPath((string) $values['from']);

        if ($raced === null) {
            return ['ok' => false, 'error' => $reason, 'notice' => ''];
        }

        seoMonitorRedirectRowUpdate($raced, (array) $values);
    }

    return ['ok' => true, 'error' => '', 'notice' => 'redirect_saved'];
}

/**
 * Обновление строки правила значениями формы: одна рука для спокойной правки и для
 * дообновления после проигранной гонки вставки.
 *
 * @param array<string, mixed> $existing
 * @param array{from: string, to: string, code: int} $values
 */
function seoMonitorRedirectRowUpdate(array $existing, array $values): void
{
    run(
        'UPDATE ' . t('seo_redirect') . ' SET to_path = ?, code = ?, updated_at = ? WHERE id = ?',
        [(string) $values['to'], (int) $values['code'], utcStamp(nowUtc()), (string) $existing['id']]
    );
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function seoMonitorRedirectDelete(string $id): array
{
    $row = seoMonitorRedirectById($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Такого правила уже нет — обновите список.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('seo_redirect') . ' WHERE id = ?', [(string) $row['id']]);

    return ['ok' => true, 'error' => '', 'notice' => 'redirect_deleted'];
}

/**
 * Поиск целевой страницы: адреса, которые у нас точно есть. Источник один — карта сайта и
 * справочник метаданных: это наши собственные адреса, а не догадка о сайте.
 *
 * @return list<array{path: string, title: string}>
 */
function seoMonitorCandidates(string $query): array
{
    $needle = mb_strtolower(trim($query));

    if ($needle === '') {
        return [];
    }

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

    $found = [];

    foreach (array_keys($paths) as $path) {
        if (!str_contains(mb_strtolower($path), $needle)) {
            continue;
        }

        $found[] = ['path' => $path, 'title' => seoMonitorPathTitle($path)];
    }

    sort($found);

    return array_slice($found, 0, SEO_MONITOR_CANDIDATE_MAX);
}

/**
 * Заголовок известного адреса: из справочника метаданных, а из пустоты — сам адрес.
 */
function seoMonitorPathTitle(string $path): string
{
    $row = seoMetaRow($path);
    $title = $row === null ? '' : trim((string) ($row['title'] ?? ''));

    return $title === '' ? $path : $title;
}

/**
 * Фильтр not_found: журнал и редирект.
 *
 * Правило, на адрес которого уже заведено, отвечает редиректом и в журнал не пишется —
 * адрес больше не отсутствует. Остальные обращения записываются, и страница 404 печатается
 * как прежде.
 *
 * @return mixed
 */
function seoMonitorNotFound(mixed $value, string $path = ''): mixed
{
    if (!isAppActive('seo')) {
        return $value;
    }

    $clean = seoMetaPath($path);

    if ($clean === '') {
        return $value;
    }

    $redirect = seoMonitorRedirectByPath($clean);

    if ($redirect !== null) {
        return [
            'url' => (string) $redirect['to_path'],
            'status' => (int) $redirect['code'],
        ];
    }

    seoMonitorRecordMiss($clean);

    return $value;
}
