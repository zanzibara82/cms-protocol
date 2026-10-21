<?php

declare(strict_types=1);

// Экран метаданных раздела «SEO и статистика». Гейт маршрута ('permission' => 'login')
// пускает за клавиатуру, POST-действия проверяют токен (csrfCheck) и отдают свой результат
// finishAppAction(): отказ гасит список с причиной, успех — с ключом уведомления.

const SEO_NAV_PATHS = [
    '/seo/admin' => 'Метаданные',
    '/seo/admin/stats' => 'Обзор',
    '/seo/admin/pages' => 'Страницы',
    '/seo/admin/sources' => 'Источники',
    '/seo/admin/errors' => '404 и редиректы',
    '/seo/admin/audit' => 'Аудит',
    '/seo/admin/schema' => 'Разметка',
    '/seo/admin/settings' => 'Настройки',
];

const SEO_NOTICE_TEXT = [
    'meta_saved' => 'Метаданные сохранены.',
    'meta_deleted' => 'Метаданные удалены.',
    'stats_saved' => 'Настройки сбора сохранены.',
    'stats_purged' => 'Сырьё старше срока хранения удалено.',
    'redirect_saved' => 'Правило перехода сохранено.',
    'redirect_deleted' => 'Правило перехода удалено.',
    'errors_cleared' => 'Журнал 404 очищен.',
    'audit_done' => 'Проверка выполнена, находки пересчитаны.',
    'schema_saved' => 'Разметка сохранена.',
    'schema_deleted' => 'Разметка удалена.',
];

/**
 * Ссылки-вкладки. Активная вкладка печатается шаблоном как разметка со статическими
 * классами; здесь — только адреса, подписи и признак активности.
 */
function seoAdminNav(string $currentPath): string
{
    // Активной считается самая длинная подходящая вкладка: адрес «/seo/admin/stats» подходит
    // и «Метаданным» (общий префикс), и «Обзору» — и открыт именно «Обзор». Без этого правила
    // подсвечивались бы две вкладки сразу, и человек не понимал бы, где он.
    $activeHref = '';

    foreach (array_keys(SEO_NAV_PATHS) as $href) {
        $matches = $currentPath === $href || str_starts_with($currentPath, $href . '/');

        if ($matches && strlen($href) > strlen($activeHref)) {
            $activeHref = $href;
        }
    }

    $items = '';

    foreach (SEO_NAV_PATHS as $href => $label) {
        $active = $href === $activeHref;
        $class = $active
            ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e($label) . '</a></li>';
    }

    return '<nav aria-label="Экраны раздела"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

function seoAdminNotice(): string
{
    return noticeForAdminScreen(SEO_NOTICE_TEXT);
}

/**
 * Причина отказа из сессионного flash вместе со стандартным заголовком ядра.
 *
 * @return array{alertTitle: string, alertReason: string}
 */
function seoAdminAlert(): array
{
    $reason = takeAdminFlashReason();

    return [
        'alertTitle' => adminFailureTitle($reason),
        'alertReason' => $reason,
    ];
}

/**
 * Идентификатор из адреса: обрезан по длине, как и всякий id в разделе.
 *
 * @param array<string, string> $params
 */
function seoMetaId26(array $params): string
{
    return mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
}

/**
 * Экран метаданных: справочник адресов и форма. По адресу /{id} открывается правка
 * выбранной записи — список и форма на одном экране.
 *
 * @param array<string, string> $params
 */
function seoAdminPage(array $params = []): void
{
    $id = seoMetaId26($params);
    $row = $id !== '' ? seoMetaById($id) : null;

    if ($id !== '' && $row === null) {
        finishAppAction(['ok' => false, 'error' => 'Такой записи уже нет — обновите список.'], '/seo/admin', '');

        return;
    }

    render('seo/admin/meta', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'rows' => seoMetaList(),
        'mode' => $row !== null ? 'edit' : 'new',
        'row' => $row,
        'robotsOptions' => SEO_META_ROBOTS_OPTIONS,
        'form' => [
            'path' => $row !== null ? (string) $row['path'] : '',
            'title' => $row !== null ? (string) ($row['title'] ?? '') : '',
            'description' => $row !== null ? (string) ($row['description'] ?? '') : '',
            'canonical' => $row !== null ? (string) ($row['canonical'] ?? '') : '',
            'robots' => $row !== null ? (string) ($row['robots'] ?? '') : '',
            'og_title' => $row !== null ? (string) ($row['og_title'] ?? '') : '',
            'og_description' => $row !== null ? (string) ($row['og_description'] ?? '') : '',
            'og_image' => $row !== null ? (string) ($row['og_image'] ?? '') : '',
            'noindex' => $row !== null ? (int) $row['noindex'] : 0,
            'in_sitemap' => $row !== null ? (int) $row['in_sitemap'] : 0,
        ],
        'errors' => [],
    ], seoAdminAlert()), ['title' => 'Метаданные — SEO', 'noindex' => true]);
}

/**
 * Действия с метаданными: создать, сохранить, удалить. Правка и удаление смотрят в саму
 * строку по идентификатору из адреса — подменить чужой адрес в запросе нельзя.
 *
 * @param array<string, string> $params
 */
function seoAdminAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = seoMetaId26($params);
    $address = '/seo/admin';
    $result = ['ok' => false, 'error' => 'Неизвестное действие с метаданными.', 'notice' => ''];

    if ($op === 'create') {
        $check = seoMetaValidate($_POST);

        if ($check['errors'] === []) {
            $result = seoMetaSave($check['values']);
        } else {
            $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
        }
    }

    if ($op === 'save' || $op === 'delete') {
        $row = seoMetaById($id);

        if ($row === null) {
            $result = ['ok' => false, 'error' => 'Такой записи уже нет — обновите список.', 'notice' => ''];
        } elseif ($op === 'delete') {
            $result = seoMetaDelete($id);
        } else {
            $check = seoMetaValidate($_POST);

            if ($check['errors'] === []) {
                $result = seoMetaSave($check['values'], $id);
            } else {
                $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
            }
        }
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, $address, $notice);
}
