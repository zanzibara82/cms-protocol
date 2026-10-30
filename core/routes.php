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
    // Конструктор — контент, а не установка приложений: право «кто правит контентом» (редактор и
    // администратор), как у админки новостей. Метка permission => 'manage' здесь не годится: гейт
    // читает её как grantsAppManage() — право установки, только администратор (core/auth.php).
    addRoute('GET', '/admin/pages', 'coreAdminPages', ['private' => true, 'permission' => 'login']);
    addRoute('POST', '/admin/pages', 'coreAdminPagesAction', ['private' => true, 'permission' => 'login']);
    addRoute('GET', '/admin/pages/home', 'coreAdminPagesHome', ['private' => true, 'permission' => 'login']);
    addRoute('POST', '/admin/pages/home', 'coreAdminPagesAction', ['private' => true, 'permission' => 'login']);
    addRoute('GET', '/admin/pages/{id}', 'coreAdminPageEdit', ['private' => true, 'permission' => 'login']);
    // Все действия конструктора — один POST-обработчик (op): точка проверки CSRF одна, как на
    // экране «Приложения». Адрес выбирает контекст: список, главная или страница.
    addRoute('POST', '/admin/pages/{id}', 'coreAdminPagesAction', ['private' => true, 'permission' => 'login']);
    addRoute('GET', '/sitemap.xml', 'coreSitemap');
    addRoute('GET', '/robots.txt', 'coreRobots');
    // Страницы живут на /{slug} одним сегментом, поэтому маршрут идёт в конец реестра: точные
    // пути ядра и разделов выигрывают по построению сортировки (router.php ставит статические
    // раньше параметрических), а {slug} ограничен «[^/]+» и не отбирает у разделов их подразделы.
    addRoute('GET', '/{slug}', 'corePage');
}

/** @param array<string, string> $params */
function coreHome(array $params = []): void
{
    render('home', ['sections' => homeSections(true)], [
        'title' => (string) configValue('SITE_NAME', 'Школа'),
        'description' => 'Новости, курсы и объявления школы английского языка.',
    ]);
}

/**
 * Публичная страница /{slug}: блоки, черновик-предпросмотр и 301 прежнего адреса.
 *
 * @param array<string, string> $params
 */
function corePage(array $params = []): void
{
    $slug = (string) ($params['slug'] ?? '');

    // Прежний адрес ведёт на живой адрес страницы. Черновик-владелец отвечает как несуществующий:
    // 301 выдал бы существование скрытой страницы.
    $owner = pageByOldSlug($slug);

    if ($owner !== null && (string) $owner['status'] === PAGES_STATUS_PUBLISHED) {
        redirect('/' . (string) $owner['slug'], 301);
    }

    $page = findPage($slug);

    if ($page === null) {
        notFound();
    }

    $published = (string) $page['status'] === PAGES_STATUS_PUBLISHED;

    // Черновик виден редактору прямым адресом — предпросмотр; всем остальным страница отвечает
    // ровно как несуществующая (notFound(): фильтры приложений на not_found остаются живыми).
    if (!$published && !roleGrantsManage(currentUser())) {
        notFound();
    }

    render('page', [
        'sections' => pageSections((string) $page['id'], true),
        'page' => $page,
        'preview' => !$published,
    ], [
        'title' => (string) $page['title'],
        'description' => (string) $page['description'],
        'noindex' => !$published || (int) $page['noindex'] === 1,
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
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'siteName' => getOption('site_name', (string) configValue('SITE_NAME', '')),
        'adminEmail' => getOption('admin_email'),
        'users' => (int) value('SELECT COUNT(*) FROM ' . t('user')),
        'apps' => $apps,
        // Версия движка на хостинге: обновление безопасности 21 октября 2026 года
        // (CVE-2026-109618, CVE-2026-109619) администратор должен видеть сразу, а не в
        // журнале ошибок. Список безопасных версий живёт в core/version.php.
        'phpVersion' => PHP_VERSION,
        'phpState' => corePhpSecurityState(),
        'phpHint' => corePhpSecurityHint(),
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
    // светиться в URL, истории браузера и access-логах. Где причина лежит и как называется — знает
    // только пара помощников core/notice.php, экраны раздела пользуются теми же.
    $alertReason = takeAdminFlashReason();
    $alertTitle = adminFailureTitle($alertReason);
    $notice = noticeForAdminScreen(CORE_NOTICE_TEXT);

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

// ---------------------------------------------------------------------------
// Конструктор страниц: список, редакторы, один POST-обработчик действий
// ---------------------------------------------------------------------------

/** @return list<array{id: string, title: string, slug: string, status: string, noindex: string, updated: string}> */
function pagesAdminList(): array
{
    // Список конструктора ограничен сверху, как справочники новостей (рубрики, теги, блоки):
    // страж производительности требует у однострочного all() предел выгрузки.
    $rows = [];
    foreach (all('SELECT id, title, slug, status, noindex, updated_at FROM ' . t('page') . ' ORDER BY updated_at DESC, id ASC LIMIT 500') as $row) {
        $rows[] = [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'] === PAGES_STATUS_PUBLISHED ? 'Опубликовано' : 'Черновик',
            'noindex' => (int) $row['noindex'] === 1 ? 'Да' : 'Нет',
            'updated' => (string) $row['updated_at'],
        ];
    }

    return $rows;
}

/**
 * Поля страницы из формы конструктора. Статус не доверяет POST: чужое значение означало бы
 * черновик, который никто не выбирал.
 *
 * @return array<string, mixed>
 */
function pagesPageValuesFromPost(): array
{
    $status = postString('status');

    return [
        'title' => postString('title'),
        'slug' => postString('slug'),
        'description' => postString('description'),
        'status' => in_array($status, PAGES_STATUSES, true) ? $status : PAGES_STATUS_DRAFT,
        'noindex' => postString('noindex'),
    ];
}

/**
 * Поля секции из формы конструктора: списки кнопок и items собираются из строк с индексами
 * (образец — характеристики доски), пустые строки отбрасываются в pagesFormRows; настройки
 * вклада едут в JSON как есть — их смысл знает вклад приложения, не ядро.
 *
 * @return array<string, mixed>
 */
function pagesSectionValuesFromPost(): array
{
    $post = postAll();

    return [
        'kind' => postString('kind'),
        'anchor' => postString('anchor'),
        'badge' => postString('badge'),
        'title' => postString('title'),
        'lead_text' => postString('lead_text'),
        'note' => postString('note'),
        'image' => postString('image'),
        'image_alt' => postString('image_alt'),
        'buttons' => pagesFormRows($post['buttons'] ?? null, ['label', 'href', 'blank']),
        'items' => pagesFormRows($post['items'] ?? null, ['title', 'text', 'href', 'image', 'note']),
        'settings' => pagesSettingsOf($post['settings'] ?? null),
        'visible' => postString('visible') !== '0',
    ];
}

/** @param array<string, string> $params */
function coreAdminPages(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('admin/pages', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => pagesAdminList(),
    ], ['title' => 'Страницы — админка', 'noindex' => true]);
}

/** Экран блоков главной: секции с page_id = '', полей страницы у главной нет.
 * @param array<string, string> $params
 */
function coreAdminPagesHome(array $params = []): void
{
    coreAdminPageScreen(PAGES_HOME_ID);
}

/**
 * @param array<string, string> $params
 */
function coreAdminPageEdit(array $params = []): void
{
    coreAdminPageScreen((string) ($params['id'] ?? ''));
}

/**
 * Экран редактора: у главной — только секции, у страницы — поля страницы плюс секции.
 * Несуществующая страница ведёт в список: актуальный состав там, а не в мёртвой форме.
 */
function coreAdminPageScreen(string $pageId): void
{
    $page = $pageId === PAGES_HOME_ID ? null : pageById($pageId);

    if ($pageId !== PAGES_HOME_ID && $page === null) {
        redirect('/admin/pages');
    }

    $data = [
        'kinds' => pagesKindOptions(),
        'sections' => pageSections($pageId),
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
    ];

    if ($page === null) {
        $alertReason = takeAdminFlashReason();
        $data['alertTitle'] = adminFailureTitle($alertReason);
        $data['alertReason'] = $alertReason;

        render('admin/pages-home', $data, ['title' => 'Блоки главной — админка', 'noindex' => true]);

        return;
    }

    $alertReason = takeAdminFlashReason();
    $data['page'] = $page;
    $data['alertTitle'] = adminFailureTitle($alertReason);
    $data['alertReason'] = $alertReason;

    render('admin/pages-edit', $data, ['title' => 'Страницы — админка', 'noindex' => true]);
}

// Все действия раздела — один обработчик: намерение приходит полем op, а не адресом. Так страж
// CsrfCoverageTest видит одну точку проверки, а журнал — одно событие на действие (механика
// экрана «Приложения»).
/** @param array<string, string> $params */
function coreAdminPagesAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');

    // Контекст: у главной секции живут с page_id = '' (параметров у точного маршрута нет),
    // у страницы идемпотент адрес маршрута — правки вне своего редактора невозможны.
    $pageId = (string) ($params['id'] ?? PAGES_HOME_ID);
    $to = $pageId === PAGES_HOME_ID ? '/admin/pages/home' : '/admin/pages/' . $pageId;

    if ($op === 'create-page') {
        // Создание приходит со списка: успех ведёт в редактор новой страницы, отказ — в список
        // с человеческой причиной в flash.
        $result = createPage(pagesPageValuesFromPost(), nowUtc());
        $created = ($result['ok'] ?? false) === true;

        finishAppAction($result, $created ? '/admin/pages/' . (string) $result['id'] : '/admin/pages', $created ? 'page_created' : '');

        return;
    }

    $result = match ($op) {
        'save-page' => updatePage($pageId, pagesPageValuesFromPost(), nowUtc()),
        'create-section' => createSection($pageId, pagesSectionValuesFromPost(), nowUtc()),
        'save-section' => updateSection(postString('section'), pagesSectionValuesFromPost(), nowUtc()),
        'move-section' => sectionMove(postString('section'), postString('dir')),
        'toggle-section' => sectionToggle(postString('section'), postString('visible') !== '0'),
        'delete-section' => sectionDelete(postString('section')),
        'delete-page' => deletePage($pageId),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    // Успех у страницы уводит в список — страницы больше нет. Остальные действия остаются
    // на своём экране.
    if ($op === 'delete-page' && ($result['ok'] ?? false) === true) {
        $to = '/admin/pages';
    }

    $notice = match ($op) {
        'save-page' => 'page_saved',
        'create-section' => 'section_created',
        'save-section', 'toggle-section' => 'section_saved',
        'move-section' => 'section_moved',
        'delete-section' => 'section_deleted',
        'delete-page' => 'page_deleted',
        default => '',
    };

    finishAppAction($result, $to, $notice);
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
