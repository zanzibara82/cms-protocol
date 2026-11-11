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
    // Меню шапки и подвала — тот же род контента (право login, как конструктор): экран с двумя
    // вкладками и один POST-обработчик (op) для всех пяти действий пунктов.
    addRoute('GET', '/admin/menu', 'coreAdminMenu', ['private' => true, 'permission' => 'login']);
    addRoute('POST', '/admin/menu', 'coreAdminMenuAction', ['private' => true, 'permission' => 'login']);
    // Текст и логотипы шапки/подвала — настройки контента, поэтому право то же, что у меню.
    addRoute('GET', '/admin/settings', 'coreAdminSettings', ['private' => true, 'permission' => 'login']);
    addRoute('POST', '/admin/settings', 'coreAdminSettingsAction', ['private' => true, 'permission' => 'login']);
    // Заявки с публичных форм: приём — публичный POST, список и действия — только администратор
    // (контакты — личные данные; портал зовёт requireAdmin, здесь право manage, как /admin/apps).
    addRoute('POST', '/lead', 'coreLeadSubmit');
    addRoute('GET', '/admin/leads', 'coreAdminLeads', ['private' => true, 'permission' => 'manage']);
    addRoute('POST', '/admin/leads', 'coreAdminLeadsAction', ['private' => true, 'permission' => 'manage']);
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
 * Состав полей решает вид: у «Карточек» нет примечания и общей картинки, у шагов нет кнопок и
 * ссылок в строке. Поле, которого форма вида не печатает, остаётся прежним значением: скрытое
 * поле не должно стирать данные, до которых человек не добрался. Лишние подполя строки тоже не
 * сохраняются — pagesFormRows читает только ключи вида.
 *
 * @param array<string, mixed>|null $existing прежняя строка секции при сохранении
 * @return array<string, mixed>
 */
function pagesSectionValuesFromPost(?array $existing = null): array
{
    $post = postAll();
    $kind = postString('kind');
    $allowed = pagesKindFieldsOf($kind);
    $allows = static fn (string $key): bool => in_array($key, $allowed, true);
    $kept = static fn (string $key): string => array_key_exists($key, $post)
        ? postString($key)
        : (string) ($existing[$key] ?? '');
    $existingButtons = is_array($existing['buttons'] ?? null) ? array_values((array) $existing['buttons']) : [];
    $existingItems = is_array($existing['items'] ?? null) ? array_values((array) $existing['items']) : [];
    $existingSettings = is_array($existing['settings'] ?? null) ? (array) $existing['settings'] : [];
    $itemKeys = [];
    foreach (pagesKindItemFieldsOf($kind) as $field) {
        $itemKeys[] = $field['key'];
    }

    return [
        'kind' => $kind,
        'anchor' => postString('anchor'),
        'badge' => postString('badge'),
        'title' => postString('title'),
        'lead_text' => postString('lead_text'),
        // У вида без примечания и картинки ключей в POST нет — прежние значения остаются.
        'note' => $allows('note') ? $kept('note') : (string) ($existing['note'] ?? ''),
        'image' => $allows('image') ? $kept('image') : (string) ($existing['image'] ?? ''),
        'image_alt' => $allows('image') ? $kept('image_alt') : (string) ($existing['image_alt'] ?? ''),
        // Вёрстка есть только у float и files; у остальных видов ключа в POST нет — прежнее
        // значение остаётся, как у примечания и картинки.
        'layout' => $allows('layout') ? $kept('layout') : (string) ($existing['layout'] ?? ''),
        'buttons' => $allows('buttons') && array_key_exists('buttons', $post)
            ? pagesFormRows($post['buttons'], ['label', 'href', 'blank'])
            : $existingButtons,
        'items' => $allows('items') && array_key_exists('items', $post)
            ? pagesFormRows($post['items'], $itemKeys)
            : $existingItems,
        // Настройки объявляет только вклад приложения; у вида ядра их нет и трогать нечего.
        'settings' => !in_array($kind, PAGES_KINDS, true) && array_key_exists('settings', $post)
            ? pagesSettingsOf($post['settings'])
            : $existingSettings,
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

        render('admin/pages-home', $data, [
            'title' => 'Блоки главной — админка',
            'noindex' => true,
            'head' => ADMIN_SECTIONS_HEAD,
        ]);

        return;
    }

    $alertReason = takeAdminFlashReason();
    $data['page'] = $page;
    $data['alertTitle'] = adminFailureTitle($alertReason);
    $data['alertReason'] = $alertReason;

    render('admin/pages-edit', $data, [
        'title' => 'Страницы — админка',
        'noindex' => true,
        'head' => ADMIN_SECTIONS_HEAD,
    ]);
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

    $sectionValues = null;
    $uploadedImage = '';

    if ($op === 'create-section' || $op === 'save-section') {
        // У «Карточек» нет полей примечания и картинки: сохранение обязано оставить их прежними,
        // а не записать пустоту вместо значений, до которых человек не добрался.
        $previousSection = $op === 'save-section' ? sectionById(postString('section')) : null;
        $sectionValues = pagesSectionValuesFromPost($previousSection);
        $file = uploadedFile('image_file');

        if ($file !== null) {
            $stored = pageImageStore($file);

            if (($stored['ok'] ?? false) !== true) {
                finishSectionAction(['ok' => false, 'error' => (string) ($stored['error'] ?? '')], $to, '');

                return;
            }

            // Присланный файл важнее текстового поля: имя и формат решил сервер, а путь человек
            // мог вписать старый. Если сохранение строки сорвётся, файл удалится ниже.
            $uploadedImage = (string) $stored['href'];
            $sectionValues['image'] = $uploadedImage;
        }
    }

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
        'create-section' => createSection($pageId, $sectionValues ?? [], nowUtc()),
        'save-section' => updateSection(postString('section'), $sectionValues ?? [], nowUtc()),
        'move-section' => sectionMove(postString('section'), postString('dir')),
        'toggle-section' => sectionToggle(postString('section'), postString('visible') !== '0'),
        'delete-section' => sectionDelete(postString('section')),
        'delete-page' => deletePage($pageId),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    if ($uploadedImage !== '' && ($result['ok'] ?? false) !== true) {
        // Файл уже на диске, но на него никто не сослался: удалить дешевле, чем оставить
        // осиротевшую картинку, которую никто не найдёт.
        pageImageDelete($uploadedImage);
    }

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

    // Формы секций плавающая кнопка отправляет через fetch: ей нужен ответ, а не PRG-редирект.
    // Остальные действия (страница, меню, приложения) остаются на прежнем канале.
    if ($op === 'create-section' || $op === 'save-section') {
        finishSectionAction($result, $to, $notice);

        return;
    }

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

// Плавающая кнопка конструктора присылает этот заголовок: ответом должен быть JSON, а не
// PRG-редирект. Иначе fetch следовал бы за редиректом, забирал сессионное уведомление и терял
// причину отказа, которую человек обязан увидеть рядом с формой.
function sectionSaveIsJson(): bool
{
    return (string) ($_SERVER['HTTP_X_CMS_SECTION_SAVE'] ?? '') === '1';
}

/**
 * @param array{ok: bool, error?: string} $result
 */
function finishSectionAction(array $result, string $to, string $notice): void
{
    if (!sectionSaveIsJson()) {
        finishAppAction($result, $to, $notice);

        return;
    }

    $ok = ($result['ok'] ?? false) === true;
    $payload = [
        'ok' => $ok,
        'error' => $ok ? '' : (string) ($result['error'] ?? 'Действие не удалось.'),
        'notice' => $ok ? $notice : '',
    ];

    respond(200, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
        'Content-Type' => 'application/json; charset=utf-8',
    ]);
}

/** Вкладка меню: неизвестное значение не должно открывать пустой экран — только две вкладки. */
function menuAdminTab(mixed $raw): string
{
    return $raw === 'footer' ? 'footer' : 'header';
}

/** Экран «Меню»: две вкладки слотов, штрихи пунктов правятся на месте.
 * @param array<string, string> $params
 */
function coreAdminMenu(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('admin/menu', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'tab' => menuAdminTab(queryString('tab')),
        'headerRows' => menuRowsForAdmin('HEADER'),
        'footerRows' => menuRowsForAdmin('FOOTER'),
    ], ['title' => 'Меню — админка', 'noindex' => true]);
}

/**
 * Действия пунктов меню — один POST-обработчик (op): точка проверки CSRF одна, как у конструктора.
 * Успех — PRG на свой экран с ключом уведомления; видимость делит ключ с сохранением — и то и
 * другое сохранение пункта. Отказ (кривой адрес, призрачный пункт) уходит картой migration-failed
 * с человеческой причиной в сессионный flash. Вкладка едет скрытым полем формы: после действия
 * человек остаётся в том же меню, а не прыгает в шапку.
 *
 * @param array<string, string> $params
 */
function coreAdminMenuAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $itemId = postString('item');

    $result = match ($op) {
        'create-item' => createMenuItem(postString('slot'), menuRawFromPost(), nowUtc()),
        'save-item' => updateMenuItem($itemId, menuRawFromPost(), nowUtc()),
        'move-item' => moveMenuItem($itemId, postString('dir')),
        'toggle-item' => toggleMenuItem($itemId),
        'delete-item' => deleteMenuItem($itemId),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    $notice = match ($op) {
        'create-item' => 'menu_created',
        'save-item', 'toggle-item' => 'menu_saved',
        'move-item' => 'menu_moved',
        'delete-item' => 'menu_deleted',
        default => '',
    };

    $to = menuAdminTab(postString('tab')) === 'footer' ? '/admin/menu?tab=footer' : '/admin/menu';

    finishAppAction($result, $to, $notice);
}

/**
 * Экран «Настройки»: текст, логотип и копирайт шапки и подвала. Значения читаются с запасными из
 * siteSettingDefault(), чтобы пустая установка показывала то, что и так печатает тема.
 *
 * @param array<string, string> $params
 */
function coreAdminSettings(array $params = []): void
{
    $alertReason = takeAdminFlashReason();
    $values = [];

    foreach (SITE_SETTINGS_FIELDS as $key => $field) {
        $values[$key] = siteSettingValue($key);
    }

    render('admin/settings', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'values' => $values,
    ], ['title' => 'Настройки — админка', 'noindex' => true]);
}

/**
 * Сохранение шапки и подвала: тексты, копирайт и пути к логотипам пишутся одной транзакцией; присланный
 * файл важнее текстового поля — имя и формат решил сервер. Если запись сорвалась, уже принятые
 * файлы удаляются, чтобы на диске не осталось картинок без ссылки.
 *
 * @param array<string, string> $params
 */
function coreAdminSettingsAction(array $params = []): void
{
    csrfCheck();

    $values = siteSettingsFromPost();
    $uploaded = [];

    foreach (['header_logo' => 'header_logo_file', 'footer_logo' => 'footer_logo_file'] as $key => $fileKey) {
        $file = uploadedFile($fileKey);

        if ($file === null) {
            continue;
        }

        $stored = pageImageStore($file);

        if (($stored['ok'] ?? false) !== true) {
            foreach ($uploaded as $href) {
                pageImageDelete($href);
            }

            finishAppAction(['ok' => false, 'error' => (string) ($stored['error'] ?? '')], '/admin/settings', '');

            return;
        }

        $values[$key] = (string) $stored['href'];
        $uploaded[] = $values[$key];
    }

    try {
        siteSettingsSave($values);
    } catch (Throwable $error) {
        logError('settings.save_failed', ['message' => $error->getMessage()]);

        foreach ($uploaded as $href) {
            pageImageDelete($href);
        }

        finishAppAction(['ok' => false, 'error' => 'Настройки не сохранились — повторите попытку.'], '/admin/settings', '');

        return;
    }

    logInfo('settings.saved', ['keys' => implode(',', array_keys(SITE_SETTINGS_FIELDS))]);

    finishAppAction(['ok' => true, 'error' => ''], '/admin/settings', 'settings_saved');
}

// ---------------------------------------------------------------------------
// Заявки с публичных форм и их админка (§17)
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function coreLeadSubmit(array $params = []): void
{
    csrfCheck();

    $ip = clientIp();
    $source = leadSourceOrEmpty(postString('source'));

    // Лимит считается и записывается до ловушки и валидации, как в портале: вызов сверх предела
    // отказывает, а обычный — пишет попытку.
    if (leadRateTooMany('ip:' . $ip, LEAD_RATE_LIMIT, LEAD_RATE_WINDOW_MINUTES)) {
        leadRedirect($source, 'lead_failed', 'Заявки с одного адреса принимаются не чаще пяти в час. Попробуйте позже.');
    }

    $data = [
        'name' => postString('name'),
        'contact' => postString('contact'),
        'message' => postString('message'),
        'source' => $source,
        'company' => postString('company'),
        'consent' => postString('consent'),
    ];

    // Ловушка для ботов отвечает тем же «спасибо», что и настоящему человеку: по ответу нельзя
    // определить, сработала она или нет.
    if (leadIsJunk($data)) {
        leadRedirect($source, 'lead_sent', '');
    }

    $error = leadValidationError($data);

    if ($error !== '') {
        leadRedirect($source, 'lead_failed', $error);
    }

    $created = leadCreate($data);

    if (!($created['ok'] ?? false)) {
        leadRedirect($source, 'lead_failed', (string) ($created['error'] ?? 'Заявка не сохранилась.'));
    }

    leadConfirmSend((string) $created['id'], trim((string) $data['name']), trim((string) $data['contact']));

    leadRedirect($source, 'lead_sent', '', trim((string) $data['contact']));
}

/**
 * PRG-редирект обратно к форме: человек остаётся у блока, а не прыгает вверх страницы. Цель
 * собирается из нормализованного источника — чужой домен и «//» в него не проходят. Ошибка
 * показывается flash-причиной, успешный контакт — подписью «подтверждение придёт на почту».
 */
function leadRedirect(string $source, string $notice, string $error, string $acceptedContact = ''): never
{
    startSession();

    if ($error !== '') {
        $_SESSION['lead_error'] = $error;
    } else {
        unset($_SESSION['lead_error']);
    }

    if ($acceptedContact !== '') {
        $_SESSION['lead_accepted'] = $acceptedContact;
    } else {
        unset($_SESSION['lead_accepted']);
    }

    $target = leadRedirectTarget($source);
    redirect(queryAppend($target, 'notice', $notice) . leadSourceFragment($source));
}

// Путь для редиректа без якоря: queryAppend() обязан добавить notice до «#», иначе параметр
// уедет в якорь и не прочитается. Путь без схемы гарантирован leadSourceOrEmpty().
function leadRedirectTarget(string $source): string
{
    if ($source === '') {
        return '/';
    }

    $path = (string) (strtok($source, '?#') ?: '/');

    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '/';
    }

    $query = str_contains($source, '?draft=1') ? '?draft=1' : '';

    return $path . $query;
}

function leadSourceFragment(string $source): string
{
    $pos = strpos($source, '#');

    return $pos === false ? '' : substr($source, $pos);
}

// Читает и стирает flash-причину отказа публичной формы: показывается ровно один раз.
function takeLeadFlashReason(): string
{
    startSession();
    $reason = $_SESSION['lead_error'] ?? '';
    unset($_SESSION['lead_error']);

    return is_string($reason) ? $reason : '';
}

// Читает и стирает принятый контакт (для подписи «подтверждение придёт на …»).
function takeLeadAcceptedContact(): string
{
    startSession();
    $contact = $_SESSION['lead_accepted'] ?? '';
    unset($_SESSION['lead_accepted']);

    return is_string($contact) ? $contact : '';
}

/** @param array<string, string> $params */
function coreAdminLeads(array $params = []): void
{
    $alertReason = takeAdminFlashReason();
    $status = leadAdminStatus(queryString('status'));
    $query = trim((string) queryString('q', ''));
    $page = max(1, (int) queryString('page', '1'));
    $result = leadsPage($status, $query, $page);

    render('admin/leads', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => $result['rows'],
        'total' => $result['total'],
        'pages' => $result['pages'],
        'page' => min($page, $result['pages']),
        'status' => $status,
        'query' => $query,
    ], ['title' => 'Заявки — админка', 'noindex' => true]);
}

// Фильтр админки: NEW (по умолчанию), DONE, ALL. Чужое значение тихо сводится к NEW — открывать
// пустую вкладку не за чем.
function leadAdminStatus(mixed $raw): string
{
    return $raw === LEAD_STATUS_DONE || $raw === 'ALL' ? (string) $raw : LEAD_STATUS_NEW;
}

/** @param array<string, string> $params */
function coreAdminLeadsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = postString('lead');
    $status = leadAdminStatus(postString('status'));
    $query = trim(postString('q'));

    $result = match ($op) {
        'set-status' => leadSetStatus($id, postString('target')),
        'delete' => leadDelete($id),
        'reply' => leadReplySend($id, postString('text'), (string) (currentUser()['email'] ?? '')),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    $notice = match ($op) {
        'set-status' => 'lead_status_saved',
        'delete' => 'lead_deleted',
        'reply' => 'lead_reply_sent',
        default => '',
    };

    finishAppAction($result, '/admin/leads' . leadAdminQuery($status, $query), $notice);
}

// Адресная часть списка после действия: фильтр и поиск возвращаются, чтобы человек остался в
// том же списке, а не прыгнул в «Новые».
function leadAdminQuery(string $status, string $query): string
{
    $parts = [];

    if ($status !== LEAD_STATUS_NEW) {
        $parts[] = 'status=' . rawurlencode($status);
    }

    if (trim($query) !== '') {
        $parts[] = 'q=' . rawurlencode(trim($query));
    }

    return $parts === [] ? '' : '?' . implode('&', $parts);
}

/**
 * Поля пункта из формы карты. Слот — только у формы добавления: в правке он и так игнорируется
 * нормализатором по слоту строки, а лишнее поле не мешает.
 *
 * @return array<string, mixed>
 */
function menuRawFromPost(): array
{
    return [
        'label' => postString('label'),
        'href' => postString('href'),
        'section' => postString('section'),
        'blank' => postString('blank'),
    ];
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
