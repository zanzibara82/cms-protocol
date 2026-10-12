<?php

declare(strict_types=1);

// Контроллеры экранов админки раздела. Право на вход проверяет гейт маршрута (permission => 'login'),
// поэтому повторной проверки доступа здесь нет: она была бы второй копией того же правила, а
// расхождение между двумя формулировками — это дыра.
//
// Один `nowUtc()` на экран: вкладки, закрепление и «ещё не время» разбираются по одному моменту,
// иначе счётчики разошлись бы между собой на границе срока.
//
// Счётчик «на сайте» — отдельный (`onSiteItemCount()`): вкладка «Опубликованные» считает строки
// по статусу в базе и держит материал снятой с показа рубрики, а на сайте его нет.

/** @param array<string, string> $params */
function newsAdminSummary(array $params = []): void
{
    $now = nowUtc();
    $counts = adminItemCounts($now);
    $pinned = pinnedVisibleCount($now);
    $alertReason = takeAdminFlashReason();

    render('news/admin/summary', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'counts' => $counts,
        'onSite' => onSiteItemCount($now),
        'pinned' => $pinned,
        'waiting' => $counts['scheduled'],
        'categories' => count(adminCategoryRows()),
        'tags' => count(adminTagRows()),
    ], ['title' => 'Новости — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function newsAdminItems(array $params = []): void
{
    $filters = parseAdminFilters([
        'status' => (string) queryString('status', ''),
        'cat' => (string) queryString('cat', ''),
        'q' => (string) queryString('q', ''),
        'page' => (string) queryString('page', '1'),
    ]);

    $now = nowUtc();
    $alertReason = takeAdminFlashReason();

    render('news/admin/items', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(NEWS_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'filters' => $filters,
        'listing' => adminItemPage($filters, $now),
        'counts' => adminItemCounts($now),
        'onSite' => onSiteItemCount($now),
        'pinned' => pinnedVisibleCount($now),
        'categories' => categoryOptions(),
    ], ['title' => 'Материалы — админка', 'noindex' => true]);
}

// Экран формы. Создание и правка различаются адресом, поэтому `$params['id']` — единственное,
// что говорит контроллеру, новую строку показывать или сохранённую.
//
// Право на переход проверяет гейт маршрута (`permission => 'login'`). Перечитывает право
// POST-действие формы материала (`actions/item.php`): роль и блокировка могли измениться посреди
// открытой формы, и имя автора обязано соответствовать тому, кто сейчас за клавиатурой. Остальные
// действия раздела держит гейт — форма у них не живёт между открытием и отправкой.

/** @param array<string, string> $params */
function newsItemNew(array $params = []): void
{
    renderNewsItemForm([], [], [], newsAdminBackHref((string) queryString('back', '')));
}

/** @param array<string, string> $params */
function newsItemEdit(array $params = []): void
{
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, NEWS_ID_MAX);
    $item = itemForForm($id);
    $back = newsAdminBackHref((string) queryString('back', ''));

    if ($item === null) {
        // Строки нет — в форму вести нечего: человек оказывается в списке с тем же текстом отказа,
        // что даёт кнопка строки удалённого материала. Канал один на весь раздел: причина в flash,
        // в адрес — только ключ словаря (тот же `finishAppAction()`, что и кнопки списка).
        finishAppAction(itemFailure('Материала уже нет — обновите страницу.'), $back, '');
    }

    renderNewsItemForm($item, [], [], $back);
}

/**
 * Один экран в трёх исходах: чистая форма, форма после отказа разбора, форма после отказа записи.
 * Везде один и тот же набор имён полей, поэтому `render()` вызывается отсюда, а не из трёх мест:
 * расхождение между ними — это экран, который в одном из исходов теряет половину полей.
 *
 * @param array<string, mixed> $item пустой массив — новый материал
 * @param array<string, string> $raw введённые поля (пусто — значит брать из `$item`)
 * @param array<string, string> $errors отказы по именам полей
 */
function renderNewsItemForm(array $item, array $raw, array $errors, string $back, string $error = ''): void
{
    $isNew = $item === [];
    $status = (string) ($item['status'] ?? '');

    render('news/admin/item-form', [
        'nav' => newsAdminNav(NEWS_ADMIN_ITEMS_PATH),
        'notice' => (string) (noticeOfRequest(NEWS_NOTICE_TEXT) ?? ''),
        'error' => $error,
        'errors' => $errors,
        'values' => newsFormValues($item, $raw),
        'buttons' => newsFormButtons($status),
        'action' => $isNew ? NEWS_ITEM_NEW_PATH : (string) $item['editHref'],
        'back' => $back,
        'isNew' => $isNew,
        'statusLabel' => (string) ($item['statusLabel'] ?? ''),
        'publicHref' => (string) ($item['href'] ?? ''),
        'updated' => (string) ($item['updated'] ?? ''),
        'oldSlugs' => count((array) ($item['oldSlugs'] ?? [])),
        'categories' => categoryOptions(),
    ], [
        'title' => ($isNew ? 'Новый материал' : 'Правка материала') . ' — админка',
        'noindex' => true,
    ]);
}

// Справочники: экран показывает ровно то, что посчитал lib/catalog.php. Права на вход здесь
// не проверяются — гейт маршрута (permission => 'login') делает это раньше, и вторая проверка
// была бы вторым описанием того же правила.

/** @param array<string, string> $params */
function newsAdminCategories(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('news/admin/categories', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(CATALOG_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => adminCategoryRows(),
        'titleMax' => fieldLimit(CATEGORY_FIELDS, 'title', 'max'),
        'descriptionMax' => fieldLimit(CATEGORY_FIELDS, 'description', 'max'),
    ], ['title' => 'Рубрики — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function newsAdminTags(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('news/admin/tags', [
        'nav' => newsAdminNav(requestPath()),
        'notice' => noticeForAdminScreen(CATALOG_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
        'rows' => adminTagRows(),
        'labelMax' => fieldLimit(TAG_FIELDS, 'label', 'max'),
    ], ['title' => 'Теги — админка', 'noindex' => true]);
}
