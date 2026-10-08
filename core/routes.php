<?php

declare(strict_types=1);

// Маршруты ядра. Разделы добавляют свои в apps/<id>/routes.php — ядро их не знает.
function registerCoreRoutes(): void
{
    addRoute('GET', '/', 'coreHome');
    addRoute('GET', '/login', 'coreLoginPage');
    addRoute('POST', '/login', 'coreLoginSubmit');
    addRoute('POST', '/logout', 'coreLogout');
    addRoute('GET', '/admin', 'coreAdminSummary', ['private' => true, 'permission' => 'login']);
    addRoute('GET', '/admin/apps', 'coreAdminApps', ['private' => true, 'permission' => 'manage']);
    addRoute('POST', '/admin/apps', 'coreAdminAppsAction', ['private' => true, 'permission' => 'manage']);
    addRoute('GET', '/sitemap.xml', 'coreSitemap');
    addRoute('GET', '/robots.txt', 'coreRobots');
}

/** @param array<string, string> $params */
function coreHome(array $params = []): void
{
    render('home', [], [
        'title' => (string) configValue('SITE_NAME', 'Школа'),
        'description' => 'Новости, курсы и объявления школы английского языка.',
    ]);
}

/** @param array<string, string> $params */
function coreLoginPage(array $params = []): void
{
    if (currentUser() !== null) {
        redirect('/admin');
    }

    // Глубинная ссылка переживает страницу входа: она уезжает в форме скрытым полем,
    // поэтому гейт из задачи 8 досылает её до редиректа после входа.
    $queryNext = queryString('next', '');
    $next = is_string($queryNext) && isInternalNextPath($queryNext) ? $queryNext : '';

    render('login', ['error' => '', 'email' => queryString('email', ''), 'next' => $next], ['title' => 'Вход', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreLoginSubmit(array $params = []): void
{
    csrfCheck();

    $email = postString('email');
    $result = attemptLogin($email, postString('password'));

    // Скрытое поле формы — основной путь для глубинной ссылки, строка адреса остаётся запасным;
    // фильтр тот же, что и на странице входа: чужой домен не проходит ни одним способом.
    $queryNext = queryString('next', '');
    $rawNext = postString('next') !== '' ? postString('next') : (is_string($queryNext) ? $queryNext : '');
    $next = isInternalNextPath($rawNext) ? $rawNext : '';

    if ($result['ok'] === false) {
        // Введённый адрес возвращается в форму, текст ошибки — общий: он не выдаёт, есть ли аккаунт.
        render('login', ['error' => (string) $result['error'], 'email' => $email, 'next' => $next], ['title' => 'Вход', 'noindex' => true]);

        return;
    }

    redirect($next !== '' ? $next : '/admin');
}

/** @param array<string, string> $params */
function coreLogout(array $params = []): void
{
    csrfCheck();
    logoutUser();
    redirectWithNotice('/', 'signed-out');
}

/** @param array<string, string> $params */
function coreSitemap(array $params = []): void
{
    respond(200, sitemapXml(), [
        'Content-Type' => 'application/xml; charset=utf-8',
        // Карта сайта меняется редко, а пересчёт на каждый запрос бьёт по базе.
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

/** @param array<string, string> $params */
function coreRobots(array $params = []): void
{
    respond(200, robotsTxt(), [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
}

/** @param array<string, string> $params */
function coreAdminSummary(array $params = []): void
{
    $apps = [];
    foreach (appInstallations() as $id => $row) {
        // Ядро — не раздел: у него нет ни манифеста, ни вкладки, ни кнопки «Выключить».
        if ($id === 'core') {
            continue;
        }

        $manifest = appManifest((string) $id);
        $nav = (array) ($manifest['adminNav'] ?? []);
        $first = is_array($nav[0] ?? null) ? (string) ($nav[0]['href'] ?? '/admin/apps') : '/admin/apps';

        $apps[] = [
            'title' => (string) ($manifest['title'] ?? $id),
            'href' => $first,
            // enabled приходит то числом, то строкой — зависит от драйвера и режима подготовки,
            // поэтому сравниваем после приведения к int.
            'state' => (int) $row['enabled'] === 1 ? 'Включён, версия ' . (string) $row['version'] : 'Выключен',
        ];
    }

    render('admin/summary', [
        'notice' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? ''),
        'siteName' => getOption('site_name', (string) configValue('SITE_NAME', '')),
        'adminEmail' => getOption('admin_email'),
        'users' => (int) value('SELECT COUNT(*) FROM ' . t('user')),
        'apps' => $apps,
    ], ['title' => 'Сводка — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreAdminApps(array $params = []): void
{
    $rows = [];
    foreach (appManifests() as $id => $manifest) {
        $state = appState((string) $id);
        $rows[] = [
            'id' => (string) $id,
            'title' => (string) $manifest['title'],
            'state' => (string) $state['state'],
            'dbVersion' => (string) $state['dbVersion'],
            'manifestVersion' => (string) $state['manifestVersion'],
            'reason' => (string) $state['reason'],
        ];
    }

    // Причина сбоя приезжает из сессионного flash, а не из адресной строки: текст драйвера не должен
    // светиться в URL, истории браузера и access-логах. Flash показывается ровно один раз.
    startSession();
    $flashed = $_SESSION['admin_error'] ?? '';
    unset($_SESSION['admin_error']);
    $alertReason = is_string($flashed) ? $flashed : '';

    // Заголовок отказа — из словаря по ключу migration-failed: свободный текст из адреса не печатается.
    // Зелёной плашкой этот ключ показывать нельзя, поэтому noticeOfRequest для него гасим.
    $noticeKey = queryString('notice', '');
    $failureRequested = is_string($noticeKey) && $noticeKey === 'migration-failed';
    $alertTitle = ($alertReason !== '' || $failureRequested)
        ? (string) (noticeText('migration-failed', CORE_NOTICE_TEXT) ?? '')
        : '';
    $notice = $failureRequested ? '' : (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? '');

    render('admin/apps', [
        'notice' => $notice,
        'alertTitle' => $alertTitle,
        'alertReason' => $alertReason,
        'coreVersion' => CORE_VERSION,
        'corePending' => coreSqlPending(),
        'apps' => $rows,
    ], ['title' => 'Приложения — админка', 'noindex' => true]);
}

// Все действия — один обработчик: намерение приходит полем op, а не адресом. Так страж
// CsrfCoverageTest видит одну точку проверки, а журнал — одно событие на действие.
/** @param array<string, string> $params */
function coreAdminAppsAction(array $params = []): void
{
    csrfCheck();

    // Право «управлять приложениями» проверяет гейт маршрута (permission => 'manage' в core/router.php),
    // и только он: повторная сверка роли в обработчике дала бы второй источник истины о праве.
    $op = postString('op');
    $app = postString('app');

    if ($op === 'update-core') {
        $plan = planApp('core');
        $result = $plan->isEmpty() ? ['ok' => true, 'error' => ''] : applyApp($plan);
        finishAppAction($result, '/admin/apps', $plan->isEmpty() ? '' : 'core-updated');

        return;
    }

    if ($app === '' || appManifest($app) === null) {
        finishAppAction(['ok' => false, 'error' => 'Раздел не найден в сборке.'], '/admin/apps', '');
    }

    $result = match ($op) {
        'install' => installApp($app),
        'update' => updateApp($app),
        'disable' => setAppEnabled($app, false),
        'enable' => setAppEnabled($app, true),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    $notice = match ($op) {
        'install' => 'installed',
        'update' => 'updated',
        'disable' => 'disabled',
        'enable' => 'enabled',
        default => '',
    };

    finishAppAction($result, '/admin/apps', $notice);
}

/**
 * Отказ показывается на той же странице: заголовок берётся из словаря уведомлений, подробная
 * причина пишется в сессионный flash и не уезжает в адресную строку. Успех — PRG-редирект
 * с ключом уведомления.
 *
 * @param array{ok: bool, error?: string} $result
 */
function finishAppAction(array $result, string $to, string $notice): void
{
    if (($result['ok'] ?? false) === false) {
        startSession();
        // Экрану хватает 200 символов причины: в журнал она пишется целиком, а flash только показывается.
        $_SESSION['admin_error'] = mb_substr((string) ($result['error'] ?? 'Действие не удалось.'), 0, 200);
        redirectWithNotice($to, 'migration-failed');
    }

    // Успех тоже убирает flash: незапоказанная причина прошлого сбоя иначе доехала бы до экрана
    // красным баннером рядом с зелёным уведомлением. Сессию открыл csrfCheck() вызывающего обработчика.
    unset($_SESSION['admin_error']);

    if ($notice === '') {
        redirect($to);
    }

    redirectWithNotice($to, $notice);
}

// Куда пускаем после входа: только внутренний путь, который начинается с одного «/» и дальше не
// содержит ни «/», ни обратного слэша — браузер сводит «//host» и «/\host» к адресу чужого сайта.
// «://» внутри строки — прямая ссылка наружу, её тоже отклоняем целиком.
function isInternalNextPath(string $next): bool
{
    if ($next === '' || !str_starts_with($next, '/')) {
        return false;
    }

    $second = substr($next, 1, 1);
    if ($second === '/' || $second === '\\') {
        return false;
    }

    return !str_contains($next, '://');
}
