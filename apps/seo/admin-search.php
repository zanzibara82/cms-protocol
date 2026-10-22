<?php

declare(strict_types=1);

// Экраны поисковой аналитики раздела «SEO и статистика» (S4, спецификация §9). Два экрана:
// «Поиск» — что поисковик отдал по нашему сайту, и «Источник» — как этот источник подключён.
// Право на второй экран — «управлять», то есть только администратор: там лежит клиентский
// секрет, и редактору его видеть не нужно.
//
// Возврат из поисковика (callback) — единственный адрес, на который приходит код. Подпись
// состояния проверяется, иначе подделать «код» можно было бы одной ссылкой.
//
// Импорт запускает человек: фоновых задач в ядре нет, а ходить к API по расписанию мы не
// умеем и не pretendим — лимиты (длина периода, число строк, пауза между запусками) стоят
// в самом импорте.

const SEO_SEARCH_PERIODS = [7, 30, 90];
const SEO_SEARCH_PERIOD_DEFAULT = 30;

/**
 * Период отчёта в днях. Число вне списка не «примерно понимается», а заменяется запасным:
 * молчаливая подмена периода хуже прямого отказа — человек увидел бы не тот счёт.
 */
function seoSearchPeriod(): int
{
    $period = (int) queryString('period', (string) SEO_SEARCH_PERIOD_DEFAULT);

    return in_array($period, SEO_SEARCH_PERIODS, true) ? $period : SEO_SEARCH_PERIOD_DEFAULT;
}

/**
 * Измерение отчёта. Значение вне списка заменяется запасным — так же, как период.
 */
function seoSearchDimension(): string
{
    $dimension = (string) queryString('dimension', 'query');

    return array_key_exists($dimension, seoSearchDimensions()) ? $dimension : 'query';
}

/**
 * Период отчёта: последние N дней до сегодняшнего дня (по UTC).
 *
 * @return array{from: string, to: string}
 */
function seoSearchRange(int $period): array
{
    $today = nowUtc();

    return [
        'from' => utcDayKey(shiftDays($today, -($period - 1))),
        'to' => utcDayKey($today),
    ];
}

/**
 * Экран «Поиск»: что поисковик отдал по нашему сайту. Данные поисковика и своя аналитика
 * показываются на разных экранах и в разных таблицах: одно — наши собственные события,
 * другое — то, что отдал чужой API.
 *
 * @param array<string, string> $params
 */
function seoSearchPage(array $params = []): void
{
    $period = seoSearchPeriod();
    $dimension = seoSearchDimension();
    $range = seoSearchRange($period);
    $importRange = seoSearchRange(28);
    $rows = seoSearchSummary($range['from'], $range['to'], $dimension);
    $settings = seoSearchSettings();
    $connection = seoSearchConnection();

    $clicks = 0;
    $impressions = 0;
    $position = 0.0;

    foreach ($rows as $row) {
        $clicks += (int) ($row['clicks'] ?? 0);
        $impressions += (int) ($row['impressions'] ?? 0);
        $position += (float) ($row['position'] ?? 0);
    }

    render('seo/admin/search', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'state' => seoSearchState(),
        'provider' => $settings['provider'],
        'providerName' => seoSearchProviders()[$settings['provider']] ?? '—',
        'site' => $settings['site'],
        'isTest' => $settings['provider'] === SEO_SEARCH_PROVIDER_TEST,
        'connectedAt' => (string) ($connection['connected_at'] ?? ''),
        'period' => $period,
        'periods' => SEO_SEARCH_PERIODS,
        'dimension' => $dimension,
        'dimensions' => seoSearchDimensions(),
        'from' => $range['from'],
        'to' => $range['to'],
        'importFrom' => $importRange['from'],
        'importTo' => $importRange['to'],
        'rows' => $rows,
        'totals' => [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 5) : 0.0,
            'position' => count($rows) > 0 ? round($position / count($rows), 2) : 0.0,
        ],
        'lastImport' => (int) getOption(SEO_SEARCH_OPTION_LAST_IMPORT, '0'),
    ], seoAdminAlert()), ['title' => 'Поиск — SEO', 'noindex' => true]);
}

/**
 * Экран «Источник»: подключение, секрет и адрес возврата. Право — «управлять».
 *
 * @param array<string, string> $params
 */
function seoSearchSourcePage(array $params = []): void
{
    $settings = seoSearchSettings();
    $connection = seoSearchConnection();

    render('seo/admin/search-source', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'providers' => seoSearchProviders(),
        'provider' => $settings['provider'],
        'site' => $settings['site'],
        'clientId' => $settings['clientId'],
        'redirectUri' => $settings['redirectUri'],
        'secretSet' => (string) ($connection['client_secret'] ?? '') !== '',
        'state' => seoSearchState(),
        'connectedAt' => (string) ($connection['connected_at'] ?? ''),
        'authorizeUrl' => seoSearchAuthorizeUrl(),
        'errors' => [],
    ], seoAdminAlert()), ['title' => 'Источник поиска — SEO', 'noindex' => true]);
}

/**
 * Импорт данных за выбранный период. Форма с токеном; отказ показывается причиной, успех —
 * уведомлением.
 *
 * @param array<string, string> $params
 */
function seoSearchAction(array $params = []): void
{
    csrfCheck();

    $from = trim(postString('from'));
    $to = trim(postString('to'));
    $dimension = postString('dimension');
    $dimension = array_key_exists($dimension, seoSearchDimensions()) ? $dimension : 'query';

    $result = seoSearchImport($from, $to, $dimension);

    if (($result['ok'] ?? false) !== true) {
        finishAppAction(['ok' => false, 'error' => (string) ($result['error'] ?? 'Импорт не удался.')], '/seo/admin/search', '');

        return;
    }

    // Ключ уведомления едет в результате, а не литералом в вызове: ключи раздела живут в его
    // словаре, и страж уведомлений сверяет именно словари, а не строки по разным файлам.
    $result = ['ok' => true, 'error' => '', 'notice' => 'search_imported'];
    $notice = (string) $result['notice'];

    finishAppAction($result, '/seo/admin/search', $notice);
}

/**
 * Действия экрана «Источник»: сохранить настройки, получить ссылку для входа, отключить
 * источник. Все три идут на один адрес и различаются полем op.
 *
 * @param array<string, string> $params
 */
function seoSearchSourceAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');

    if ($op === 'disconnect') {
        seoSearchDeleteConnection();
        $result = ['ok' => true, 'error' => '', 'notice' => 'search_disconnected'];
        $notice = (string) $result['notice'];

        finishAppAction($result, '/seo/admin/search/source', $notice);

        return;
    }

    $check = seoSearchValidate([
        'provider' => postString('provider'),
        'site' => postString('site'),
        'client_id' => postString('client_id'),
        'client_secret' => postString('client_secret'),
        'redirect' => postString('redirect'),
    ]);

    if ($check['errors'] !== []) {
        finishAppAction(
            ['ok' => false, 'error' => implode(' ', array_values($check['errors']))],
            '/seo/admin/search/source',
            ''
        );

        return;
    }

    $values = $check['values'];

    if ($values['redirect'] === '') {
        $values['redirect'] = seoSearchDefaultRedirect();
    }

    seoSearchSaveSettings($values);
    seoSearchSaveConnection($values);

    if ($op === 'authorize') {
        $url = seoSearchAuthorizeUrl();

        if ($url === '') {
            finishAppAction(
                ['ok' => false, 'error' => 'Вход недоступен: нужен источник Google и идентификатор клиента.'],
                '/seo/admin/search/source',
                ''
            );

            return;
        }

        redirect($url);
    }

    $result = ['ok' => true, 'error' => '', 'notice' => 'search_saved'];
    $notice = (string) $result['notice'];

    finishAppAction($result, '/seo/admin/search/source', $notice);
}

/**
 * Возврат из поисковика: код меняется на токен, токен кладётся в строку подключения. Подпись
 * состояния проверяется — без неё этот адрес принял бы любой «код» и любой «сайт».
 *
 * @param array<string, string> $params
 */
function seoSearchCallback(array $params = []): void
{
    $state = (string) queryString('state', '');
    $code = (string) queryString('code', '');

    // Причину отказа источника из адресной строки не читаем: она уехала бы в историю браузера и
    // в access-лог хостинга. Человеку объясняем своими словами, а подробности пишем в журнал.
    if ($code === '') {
        logWarn('seo.search.callback', ['reason' => 'no-code']);

        finishAppAction(
            ['ok' => false, 'error' => 'Источник не выдал кода доступа: вход не состоялся.'],
            '/seo/admin/search/source',
            ''
        );

        return;
    }

    if (!seoSearchStateValid($state)) {
        logWarn('seo.search.callback', ['reason' => 'bad-state']);

        finishAppAction(
            ['ok' => false, 'error' => 'Ответ источника не опознан: подпись состояния не сошлась.'],
            '/seo/admin/search/source',
            ''
        );

        return;
    }

    $exchanged = seoSearchExchange($code);

    if (!$exchanged['ok']) {
        finishAppAction(
            ['ok' => false, 'error' => (string) $exchanged['error']],
            '/seo/admin/search/source',
            ''
        );

        return;
    }

    seoSearchStoreTokens($exchanged['tokens']);

    $result = ['ok' => true, 'error' => '', 'notice' => 'search_connected'];
    $notice = (string) $result['notice'];

    finishAppAction($result, '/seo/admin/search/source', $notice);
}
