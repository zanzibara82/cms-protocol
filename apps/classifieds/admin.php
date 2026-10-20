<?php

declare(strict_types=1);

// Экраны администратора доски. Гейт маршрута ('permission' => 'login') пускает за
// клавиатуру, POST-действия проверяют токен (csrfCheck) и свой результат отдают
// finishAppAction(): отказ гасит список с причиной, успех — с ключом уведомления.

const CLASSIFIEDS_NAV_PATHS = [
    '/classifieds/admin' => 'Обзор',
    '/classifieds/admin/categories' => 'Категории',
];

const CLASSIFIEDS_NOTICE_TEXT = [
    'category_saved' => 'Категория сохранена.',
    'category_deleted' => 'Категория удалена.',
    'account_created' => 'Аккаунт создан — добро пожаловать на доску.',
    'listing_saved' => 'Объявление сохранено.',
    'listing_published' => 'Объявление опубликовано.',
    'listing_hidden' => 'Объявление снято с публикации.',
    'listing_deleted' => 'Объявление удалено.',
];

/**
 * Ссылки-вкладки. Активная вкладка печатается шаблоном как разметка со статическими
 * классами; здесь — только адреса, подписи и признак активности.
 */
function classifiedsAdminNav(string $currentPath): string
{
    $items = '';

    foreach (CLASSIFIEDS_NAV_PATHS as $href => $label) {
        $active = $currentPath === $href
            || ($href !== '/classifieds/admin' && str_starts_with($currentPath, $href . '/'));
        $class = $active
            ? 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-2 text-sm font-medium text-cream'
            : 'inline-flex min-h-[44px] items-center rounded-lg px-3 py-2 text-sm font-medium text-ink/70 hover:bg-ink/5';
        $aria = $active ? ' aria-current="page"' : '';

        $items .= '<li><a class="' . $class . '" href="' . e($href) . '"' . $aria . '>'
            . e($label) . '</a></li>';
    }

    return '<nav aria-label="Экраны доски"><ul class="flex flex-wrap gap-2">' . $items . '</ul></nav>';
}

function classifiedsAdminNotice(): string
{
    return noticeForAdminScreen(CLASSIFIEDS_NOTICE_TEXT);
}

/** Причина отказа из сессионного flash вместе со стандартным заголовком ядра.
 * @return array{alertTitle: string, alertReason: string}
 */
function classifiedsAdminAlert(): array
{
    $reason = takeAdminFlashReason();

    return [
        'alertTitle' => adminFailureTitle($reason),
        'alertReason' => $reason,
    ];
}

/** @param array<string, string> $params */
function classifiedsAdminSummary(array $params = []): void
{
    $counts = [
        'categories' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_category')),
        'listings' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_listing')),
        'published' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_listing') . " WHERE status = 'PUBLISHED'"),
        'sellers' => (int) value('SELECT COUNT(*) FROM ' . t('user') . " WHERE role = 'seller'"),
    ];

    render('classifieds/admin/summary', array_merge([
        'nav' => classifiedsAdminNav(requestPath()),
        'notice' => classifiedsAdminNotice(),
        'counts' => $counts,
    ], classifiedsAdminAlert()), ['title' => 'Доска объявлений — обзор', 'noindex' => true]);
}

/** @param array<string, string> $params */
function classifiedsAdminCategories(array $params = []): void
{
    render('classifieds/admin/categories', array_merge([
        'nav' => classifiedsAdminNav(requestPath()),
        'notice' => classifiedsAdminNotice(),
        'rows' => classifiedsCategoryFlatTree(),
    ], classifiedsAdminAlert()), ['title' => 'Категории — доска', 'noindex' => true]);
}

/**
 * Действия с категориями: одна точка входа на POST, намерение приходит полем op.
 * Порядок правится обменом с соседом по своей ветке, родитель проверяет валидатором.
 *
 * @param array<string, string> $params
 */
function classifiedsCategoryAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, 26);

    $result = ['ok' => false, 'error' => 'Неизвестное действие с категориями.', 'notice' => ''];

    if ($op === 'create') {
        $check = classifiedsValidateCategory($_POST, '');
        if ($check['errors'] === []) {
            $result = classifiedsCreateCategory($check['values']);
        } else {
            $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
        }
    }

    if ($op === 'save') {
        $current = classifiedsCategoryById($id);

        if ($current === null) {
            $result = ['ok' => false, 'error' => 'Категории уже нет — обновите список.', 'notice' => ''];
        } else {
            $check = classifiedsValidateCategory($_POST, $id);

            if ($check['errors'] === []) {
                $result = classifiedsUpdateCategory($id, $check['values']);
            } else {
                $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
            }
        }
    }

    if ($op === 'up' || $op === 'down') {
        $current = classifiedsCategoryById($id);

        if ($current === null) {
            $result = ['ok' => false, 'error' => 'Категории уже нет — обновите список.', 'notice' => ''];
        } else {
            $parentId = (string) ($current['parent_id'] ?? '');
            $siblings = classifiedsCategoryChildrenOf($parentId);
            $position = null;

            foreach ($siblings as $index => $sibling) {
                if ((string) $sibling['id'] === $id) {
                    $position = $index;

                    break;
                }
            }

            $neighbour = $position !== null ? ($op === 'up' ? $position - 1 : $position + 1) : -1;

            if ($position === null || !isset($siblings[$neighbour])) {
                // Ехать некуда: строка уже на краю ветки. Тишина — так честнее, чем «сохранено».
                $result = ['ok' => true, 'error' => '', 'notice' => 'category_saved'];
            } else {
                $result = classifiedsSwapCategories($id, (string) $siblings[$neighbour]['id']);
            }
        }
    }

    if ($op === 'visible') {
        $result = classifiedsCategoryToggle($id);
    }

    if ($op === 'delete') {
        $result = classifiedsCategoryDelete($id);
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/classifieds/admin/categories', $notice);
}
