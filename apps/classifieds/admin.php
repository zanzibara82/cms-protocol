<?php

declare(strict_types=1);

// Экраны администратора доски. Гейт маршрута ('permission' => 'login') пускает за
// клавиатуру, POST-действия проверяют токен (csrfCheck) и свой результат отдают
// finishAppAction(): отказ гасит список с причиной, успех — с ключом уведомления.

const CLASSIFIEDS_NAV_PATHS = [
    '/classifieds/admin' => 'Обзор',
    '/classifieds/admin/categories' => 'Категории',
    '/classifieds/admin/attributes' => 'Характеристики',
    '/classifieds/admin/moderation' => 'Проверка',
    '/classifieds/admin/reports' => 'Жалобы',
];

const CLASSIFIEDS_NOTICE_TEXT = [
    'category_saved' => 'Категория сохранена.',
    'category_deleted' => 'Категория удалена.',
    'attribute_saved' => 'Характеристика сохранена.',
    'attribute_deleted' => 'Характеристика удалена.',
    'account_created' => 'Аккаунт создан — добро пожаловать на доску.',
    'listing_saved' => 'Объявление сохранено.',
    'listing_submitted' => 'Объявление отправлено на проверку — редактор скоро его посмотрит.',
    'listing_published' => 'Объявление опубликовано.',
    'listing_hidden' => 'Объявление снято с публикации.',
    'listing_deleted' => 'Объявление удалено.',
    'listing_extended' => 'Публикация продлена на 30 дней.',
    'listing_cloned' => 'Копия создана черновиком — добавьте фотографии и проверьте текст.',
    'profile_saved' => 'Профиль продавца сохранён.',
    'favorite_added' => 'Объявление в избранном.',
    'favorite_removed' => 'Объявление убрано из избранного.',
    'report_sent' => 'Жалоба отправлена — редактор её рассмотрит.',
    'report_duplicate' => 'Жалоба на это объявление уже отправлена и ждёт рассмотрения.',
    'report_resolved' => 'Жалоба закрыта.',
    'moderation_approved' => 'Объявление опубликовано.',
    'moderation_rejected' => 'Объявление отклонено, продавцу отправлена причина.',
    'message_sent' => 'Сообщение отправлено.',
    'notifications_read' => 'Уведомления отмечены прочитанными.',
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
        'attributes' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_attribute')),
        'listings' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_listing')),
        'published' => (int) value('SELECT COUNT(*) FROM ' . t('classifieds_listing') . " WHERE status = 'PUBLISHED'"),
        'pending' => classifiedsModerationPendingCount(),
        'reports' => classifiedsReportNewCount(),
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

/** @param array<string, string> $params */
function classifiedsAdminAttributes(array $params = []): void
{
    $categoryId = mb_substr(trim((string) queryString('category', '')), 0, 26);
    $category = $categoryId !== '' ? classifiedsCategoryById($categoryId) : null;

    if ($category === null) {
        $categoryId = '';
    }

    $own = $categoryId !== '' ? classifiedsAttributesOwn($categoryId) : [];
    $inherited = [];

    if ($categoryId !== '') {
        foreach (classifiedsAttributesForCategory($categoryId) as $attribute) {
            if ($attribute['category_id'] !== $categoryId) {
                $inherited[] = $attribute;
            }
        }
    }

    render('classifieds/admin/attributes', array_merge([
        'nav' => classifiedsAdminNav(requestPath()),
        'notice' => classifiedsAdminNotice(),
        'categories' => classifiedsCategoryFlatTree(),
        'category' => $category,
        'categoryId' => $categoryId,
        'attributes' => $own,
        'inherited' => $inherited,
        'kinds' => CLASSIFIEDS_ATTRIBUTE_KINDS,
    ], classifiedsAdminAlert()), ['title' => 'Характеристики — доска', 'noindex' => true]);
}

/**
 * Действия с характеристиками: создать, сохранить, удалить. Категория приходит полем формы,
 * но правка и удаление берут её из самой строки — подменить категорию в запросе нельзя.
 *
 * @param array<string, string> $params
 */
function classifiedsAttributeAction(array $params = []): void
{
    csrfCheck();
    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, 26);
    $categoryId = mb_substr(trim(postString('category')), 0, 26);
    $back = '/classifieds/admin/attributes' . ($categoryId !== '' ? '?category=' . rawurlencode($categoryId) : '');
    $result = ['ok' => false, 'error' => 'Неизвестное действие с характеристиками.', 'notice' => ''];

    if ($op === 'create') {
        $check = classifiedsValidateAttribute($_POST, $categoryId);

        if ($check['errors'] === []) {
            $result = classifiedsCreateAttribute($check['values']);
        } else {
            $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
        }
    }

    if ($op === 'save' || $op === 'delete') {
        $attribute = classifiedsAttributeById($id);

        if ($attribute === null) {
            $result = ['ok' => false, 'error' => 'Характеристики уже нет — обновите список.', 'notice' => ''];
        } elseif ($op === 'delete') {
            $result = classifiedsDeleteAttribute($id);
        } else {
            $currentCategory = (string) $attribute['category_id'];
            $back = '/classifieds/admin/attributes?category=' . rawurlencode($currentCategory);
            $check = classifiedsValidateAttribute($_POST, $currentCategory, $id);

            if ($check['errors'] === []) {
                $result = classifiedsUpdateAttribute($id, $check['values']);
            } else {
                $result = ['ok' => false, 'error' => implode(' ', array_values($check['errors'])), 'notice' => ''];
            }
        }
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, $back, $notice);
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

/** @param array<string, string> $params */
function classifiedsAdminModeration(array $params = []): void
{
    render('classifieds/admin/moderation', array_merge([
        'nav' => classifiedsAdminNav(requestPath()),
        'notice' => classifiedsAdminNotice(),
        'rows' => classifiedsModerationQueue(),
    ], classifiedsAdminAlert()), ['title' => 'Проверка объявлений — Доска', 'noindex' => true]);
}

/** @param array<string, string> $params */
function classifiedsModerationAction(array $params = []): void
{
    csrfCheck();
    $user = currentUser();
    $id = mb_substr(trim(postString('id')), 0, 26);
    $decision = postString('op');
    $note = postString('note');
    $result = $user === null
        ? ['ok' => false, 'error' => 'Нужен вход редактора.', 'notice' => '']
        : classifiedsModerationDecide($id, (string) $user['id'], $decision, $note);
    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/classifieds/admin/moderation', $notice);
}

/** @param array<string, string> $params */
function classifiedsAdminReports(array $params = []): void
{
    render('classifieds/admin/reports', array_merge([
        'nav' => classifiedsAdminNav(requestPath()),
        'notice' => classifiedsAdminNotice(),
        'rows' => classifiedsReportQueue(),
        'reasons' => CLASSIFIEDS_REPORT_REASONS,
    ], classifiedsAdminAlert()), ['title' => 'Жалобы — Доска', 'noindex' => true]);
}

/** @param array<string, string> $params */
function classifiedsAdminReportAction(array $params = []): void
{
    csrfCheck();
    $user = currentUser();
    $id = mb_substr(trim(postString('id')), 0, 26);
    $result = $user === null
        ? ['ok' => false, 'error' => 'Нужен вход редактора.', 'notice' => '']
        : classifiedsReportResolve($id, (string) $user['id']);
    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/classifieds/admin/reports', $notice);
}
