<?php

declare(strict_types=1);

// Действия двух справочников. CSRF проверяется первым: до проверки токена нельзя ни менять
// базу, ни узнавать, существует ли рубрика по ответу.
//
// Возврат здесь не нужен: у справочника нет вкладок, фильтра и страниц, поэтому редирект ведёт
// на адрес этого же экрана.
//
// Идентификатор режется тем же пределом, что и в фильтрах списка: чужая строка в 400 символов
// не обязана уходить в `WHERE id = ?` целиком.
//
// Ключ уведомления читается в переменную $notice и только потом уходит вызову — так же, как в
// actions/item.php. Литерал-аргумент вида (string) ($result['notice'] ?? '') страж NoticeKeyTest
// прочёл бы как ключ ядра, а ключи справочников живут в словаре раздела (CATALOG_NOTICE_TEXT),
// не в CORE_NOTICE_TEXT.

/** @param array<string, string> $params */
function newsCategoriesAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);

    $result = match ($op) {
        'create' => createCategory(postString('title'), postString('description')),
        'rename' => renameCategory($id, postString('title')),
        'describe' => updateCategoryDescription($id, postString('title'), postString('description')),
        'show' => toggleCategoryVisibility($id),
        'hide' => toggleCategoryVisibility($id),
        'up' => moveCategory($id, 'up'),
        'down' => moveCategory($id, 'down'),
        'delete' => deleteCategory($id),
        default => catalogFailure(catalogUnknown('рубрикой')),
    };

    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, NEWS_ADMIN_CATEGORIES_PATH, $notice);
}

/** @param array<string, string> $params */
function newsTagsAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim(postString('id')), 0, NEWS_ID_MAX);

    $result = match ($op) {
        'rename' => renameTag($id, postString('label')),
        'delete' => deleteTag($id),
        default => catalogFailure(catalogUnknown('тегом')),
    };

    $notice = (string) ($result['notice'] ?? '');

    finishAppAction($result, NEWS_ADMIN_TAGS_PATH, $notice);
}

// Текст отказа печатается на экране и кладётся в журнал, поэтому в нём нет содержимого
// запроса: неизвестное намерение описывается общим словом.
function catalogUnknown(string $what): string
{
    return mb_substr('Неизвестное действие с ' . $what . '.', 0, NEWS_UNKNOWN_ACTION_MAX);
}
