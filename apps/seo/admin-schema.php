<?php

declare(strict_types=1);

// Экран «Разметка Schema.org» раздела «SEO и статистика» (S3). Справочник наборов объектов
// по адресу страницы и форма правки.
//
// Гейт маршрута ('permission' => 'login') пускает за клавиатуру, POST-действия проверяют
// токен (csrfCheck) и отдают свой результат finishAppAction(): отказ гасит список с
// причиной, успех — с ключом уведомления.

/**
 * Экран «Разметка»: список наборов и форма. Правка выбранного набора открывается по
 * идентификатору из адреса.
 *
 * @param array<string, string> $params
 */
function seoSchemaPage(array $params = []): void
{
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $row = $id === '' ? null : seoSchemaById($id);

    if ($id !== '' && $row === null) {
        finishAppAction(['ok' => false, 'error' => 'Такой записи уже нет — обновите список.'], '/seo/admin/schema', '');

        return;
    }

    render('seo/admin/schema', array_merge([
        'nav' => seoAdminNav(requestPath()),
        'notice' => seoAdminNotice(),
        'rows' => seoSchemaList(),
        'mode' => $row !== null ? 'edit' : 'new',
        'row' => $row,
        'types' => SEO_SCHEMA_TYPES,
        'fields' => SEO_SCHEMA_FIELDS,
        'form' => [
            'path' => $row !== null ? (string) $row['path'] : '',
            'schema_type' => $row !== null ? (string) $row['schema_type'] : 'WebPage',
            'name' => $row !== null ? (string) $row['name'] : '',
            'description' => $row !== null ? (string) ($row['description'] ?? '') : '',
            'image' => $row !== null ? (string) ($row['image'] ?? '') : '',
            'author' => $row !== null ? (string) ($row['author'] ?? '') : '',
            'date_published' => $row !== null ? (string) ($row['date_published'] ?? '') : '',
            'date_modified' => $row !== null ? (string) ($row['date_modified'] ?? '') : '',
        ],
        'errors' => [],
    ], seoAdminAlert()), ['title' => 'Разметка Schema.org — SEO', 'noindex' => true]);
}

/**
 * Действия экрана: завести набор, изменить, удалить. Правка и удаление смотрят в саму строку
 * по идентификатору из адреса — подменить чужой адрес в запросе нельзя.
 *
 * @param array<string, string> $params
 */
function seoSchemaAction(array $params = []): void
{
    csrfCheck();

    $op = postString('op');
    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $result = ['ok' => false, 'error' => 'Неизвестное действие.', 'notice' => ''];

    if ($op === 'create') {
        $check = seoSchemaValidate($_POST);

        if ($check['errors'] === []) {
            $result = seoSchemaSave($check['values']);
        } else {
            $result = ['ok' => false, 'error' => implode(' ', $check['errors']), 'notice' => ''];
        }
    }

    if ($op === 'save' || $op === 'delete') {
        $row = seoSchemaById($id);

        if ($row === null) {
            $result = ['ok' => false, 'error' => 'Такой записи уже нет — обновите список.', 'notice' => ''];
        } elseif ($op === 'delete') {
            $result = seoSchemaDelete($id);
        } else {
            $check = seoSchemaValidate($_POST);

            if ($check['errors'] === []) {
                $result = seoSchemaSave($check['values']);
            } else {
                $result = ['ok' => false, 'error' => implode(' ', $check['errors']), 'notice' => ''];
            }
        }
    }

    $notice = (string) ($result['notice'] ?? '');
    finishAppAction($result, '/seo/admin/schema', $notice);
}
