<?php

declare(strict_types=1);

// RELEASE 2.3: экраны управления типами записей, записями и глобальными блоками.
//
// Все POST-обработчики начинаются с csrfCheck(): страж CsrfCoverageTest требует сверки токена до
// первого обращения к базе, а currentUser() и доменные функции уже читают таблицы. Права проверяет
// и гейт маршрута, и доменная операция: обработчик не предполагает, что запрос пришёл именно из
// его формы, — каждое намерение проверяется заново. Ошибки валидации перерисовывают ту же форму
// с введёнными значениями, чтобы ни одно поле не терялось; подтверждённые действия идут через PRG
// с ключом уведомления из словаря.

/** @param array<string, mixed> $source
 *  @return array<string, string>
 */
function contentAdminTypeValues(array $source): array
{
    return [
        'name' => (string) ($source['name'] ?? ''),
        'slug' => (string) ($source['slug'] ?? ''),
        'description' => (string) ($source['description'] ?? ''),
        'sort' => (string) ($source['sort'] ?? '0'),
    ];
}

/** @return array<string, string> */
function contentAdminTypeValuesFromPost(): array
{
    return [
        'name' => postString('name'),
        'slug' => postString('slug'),
        'description' => postString('description'),
        'sort' => postString('sort'),
    ];
}

/**
 * Строки конструктора полей из POST: параллельные массивы формы превращаются в строки, которые
 * понимает contentFieldsPlan(). Отмеченная строка удаляется полностью — это архив поля, а не
 * попытка стереть его значения.
 *
 * @param array<string, mixed> $post
 * @return list<array<string, mixed>>
 */
function contentAdminFieldRowsFromPost(array $post): array
{
    $rows = [];
    $limit = CONTENT_FIELDS_LIMIT + 5;

    for ($index = 0; $index < $limit; $index++) {
        if (isOn($post['field_delete'][$index] ?? null)) {
            continue;
        }

        $label = is_string($post['field_label'][$index] ?? null) ? trim($post['field_label'][$index]) : '';
        $key = is_string($post['field_key'][$index] ?? null) ? trim($post['field_key'][$index]) : '';
        $original = is_string($post['field_original'][$index] ?? null) ? trim($post['field_original'][$index]) : '';
        $hint = is_string($post['field_hint'][$index] ?? null) ? $post['field_hint'][$index] : '';
        $options = is_string($post['field_options'][$index] ?? null) ? $post['field_options'][$index] : '';
        $kind = is_string($post['field_kind'][$index] ?? null) ? $post['field_kind'][$index] : '';
        $types = [];
        $rawTypes = $post['field_types'][$index] ?? null;

        if (is_array($rawTypes)) {
            foreach ($rawTypes as $typeId) {
                if (is_string($typeId) && $typeId !== '') {
                    $types[] = $typeId;
                }
            }
        }

        if ($label === '' && $key === '' && $original === '' && $hint === '' && $options === '' && $kind === '' && $types === []) {
            continue;
        }

        $rows[] = [
            'label' => $label,
            'key' => $key,
            'original' => $original,
            'kind' => $kind,
            'required' => isOn($post['field_required'][$index] ?? null),
            'hint' => $hint,
            'options' => $options,
            'types' => $types,
        ];
    }

    return $rows;
}

/**
 * Параллельные массивы для contentFieldsPlan(): домен читает field_label[], field_key[] и т.д., а
 * не список строк. Удалённые строки уже исключены contentAdminFieldRowsFromPost(), поэтому
 * недостающие поля попадут в архив. Индексы сжимаются, чтобы план не увидел пустых дыр.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function contentAdminDomainRowsFromPost(array $post): array
{
    $raw = [
        'field_label' => [],
        'field_key' => [],
        'field_original' => [],
        'field_kind' => [],
        'field_hint' => [],
        'field_options' => [],
        'field_required' => [],
        'field_types' => [],
    ];

    foreach (contentAdminFieldRowsFromPost($post) as $index => $row) {
        $raw['field_label'][$index] = (string) $row['label'];
        $raw['field_key'][$index] = (string) $row['key'];
        $raw['field_original'][$index] = (string) $row['original'];
        $raw['field_kind'][$index] = (string) $row['kind'];
        $raw['field_hint'][$index] = (string) $row['hint'];
        $raw['field_options'][$index] = (string) $row['options'];
        $raw['field_required'][$index] = $row['required'] ? '1' : '0';
        $raw['field_types'][$index] = $row['types'];
    }

    return $raw;
}

/**
 * Строки конструктора из базы: ключ существующего поля сохраняется в скрытом поле, чтобы домен понял,
 * что это правка, а не попытка завести поле заново.
 *
 * @param list<array<string, mixed>> $fields
 * @return list<array<string, mixed>>
 */
function contentAdminFieldRows(array $fields): array
{
    $rows = [];

    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $rows[] = [
            'label' => (string) $field['label'],
            'key' => $key,
            'original' => $key,
            'kind' => (string) $field['kind'],
            'required' => (int) $field['required'] === 1,
            'hint' => (string) $field['hint'],
            'options' => contentFieldChoicesText($field),
            'types' => contentFieldOptions($field)['types'],
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function contentAdminFieldRowsPadded(array $rows): array
{
    while (count($rows) < 3) {
        $rows[] = [
            'label' => '',
            'key' => '',
            'original' => '',
            'kind' => CONTENT_FIELD_TEXT,
            'required' => false,
            'hint' => '',
            'options' => '',
            'types' => [],
        ];
    }

    return $rows;
}

/**
 * Экран типа: создать и править — одна форма, разница только в адрете и в заголовке. При отказе
 * обработчик передаёт исходные POST-строки, поэтому человек видит свои значения, а не пустую форму.
 *
 * @param array<string, mixed>|null $type
 * @param array<string, string> $values
 * @param list<array<string, mixed>> $rows
 */
function contentAdminTypeScreen(?array $type, array $values, array $rows, string $alertReason = '', string $notice = ''): void
{
    $isNew = $type === null;
    $heading = $isNew ? 'Новый тип записей' : 'Тип записей «' . (string) $type['name'] . '»';

    render('admin/content-type', [
        'type' => $type,
        'values' => $values,
        'rows' => contentAdminFieldRowsPadded($rows),
        'types' => contentTypesAll(true),
        'notice' => $notice,
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], ['title' => $heading . ' — админка', 'noindex' => true]);
}

/** @param array<string, mixed> $entry
 *  @return array<string, string>
 */
function contentAdminEntryValues(array $entry): array
{
    return [
        'title' => (string) ($entry['title'] ?? ''),
        'slug' => (string) ($entry['slug'] ?? ''),
        'seo_title' => (string) ($entry['seo_title'] ?? ''),
        'seo_description' => (string) ($entry['seo_description'] ?? ''),
    ];
}

/** @return array<string, string> */
function contentAdminEntryValuesFromPost(): array
{
    return [
        'title' => postString('title'),
        'slug' => postString('slug'),
        'seo_title' => postString('seo_title'),
        'seo_description' => postString('seo_description'),
    ];
}

/**
 * Резервные значения полей формы: домен возвращает очищенные данные только после успешной
 * проверки, а при отказе на заголовке или адресе их ещё нет. Введённое человеком обязано
 * остаться на экране и в этом случае.
 *
 * @param array<string, mixed> $post
 * @return array{data: array<string, string>, relations: array<string, list<string>>}
 */
function contentAdminPostedFields(array $post): array
{
    $data = [];
    $relations = [];
    $raw = is_array($post['fields'] ?? null) ? $post['fields'] : [];

    foreach ($raw as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        if (is_string($value)) {
            $data[$key] = $value;

            continue;
        }

        if (is_array($value)) {
            $ids = [];
            foreach ($value as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
            $relations[$key] = $ids;
        }
    }

    return ['data' => $data, 'relations' => $relations];
}

/**
 * Картинки полей приходят одним полем file-инпута с индексами по ключам полей. Сервер сам реша­ет
 * имя и формат файла, поэтому принятый путь перекрывает вписанный вручную — как в конструкторе
 * страниц.
 *
 * @param array<string, mixed> $post
 * @param list<array<string, mixed>> $fields
 * @return array{post: array<string, mixed>, error: string}
 */
function contentAdminEntryPostWithUploads(array $post, array $fields): array
{
    $files = $_FILES['field_files'] ?? null;

    if (!is_array($files) || !is_array($files['name'] ?? null)) {
        return ['post' => $post, 'error' => ''];
    }

    $imageKeys = [];
    foreach ($fields as $field) {
        if ((string) $field['kind'] === CONTENT_FIELD_IMAGE) {
            $imageKeys[(string) $field['field_key']] = true;
        }
    }

    foreach ($files['name'] as $key => $name) {
        if (!is_string($key) || !is_string($name) || $name === '' || !isset($imageKeys[$key])) {
            continue;
        }

        $file = [
            'name' => $name,
            'type' => is_string($files['type'][$key] ?? null) ? $files['type'][$key] : '',
            'tmp_name' => is_string($files['tmp_name'][$key] ?? null) ? $files['tmp_name'][$key] : '',
            'error' => (int) ($files['error'][$key] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$key] ?? 0),
        ];
        $stored = pageImageStore($file);

        if (($stored['ok'] ?? false) !== true) {
            return ['post' => $post, 'error' => (string) ($stored['error'] ?? 'Файл не принят.')];
        }

        if (!is_array($post['fields'] ?? null)) {
            $post['fields'] = [];
        }
        $post['fields'][$key] = (string) $stored['href'];
    }

    return ['post' => $post, 'error' => ''];
}

/**
 * @param array<string, mixed> $type
 * @param array<string, mixed>|null $entry
 * @param list<array<string, mixed>> $fields
 * @param array<string, string> $values
 * @param array<string, string> $data
 * @param array<string, list<string>> $relationIds
 * @param array<string, string> $errors
 * @param list<array<string, mixed>> $schedules
 */
function contentAdminEntryScreen(
    array $type,
    ?array $entry,
    array $fields,
    array $values,
    array $data,
    array $relationIds,
    array $errors,
    array $schedules,
    string $alertReason = '',
    string $notice = ''
): void {
    $isNew = $entry === null;
    $entryId = $isNew ? '' : (string) $entry['id'];

    if (!$isNew) {
        contentRevisionEnsureBaseline('entry', $entryId);
    }

    $user = currentUser();
    $heading = $isNew
        ? 'Новая запись в «' . (string) $type['name'] . '»'
        : 'Запись «' . (string) $entry['title'] . '»';

    render('admin/content-entry', [
        'type' => $type,
        'entry' => $entry,
        'fields' => $fields,
        'values' => $values,
        'data' => $data,
        'relationIds' => $relationIds,
        'errors' => $errors,
        'schedules' => $schedules,
        'version' => $isNew ? 0 : contentRevisionVersion('entry', $entryId),
        'canEdit' => editorialCan('edit', is_array($user) ? $user : null),
        'canPublish' => editorialCan('publish', is_array($user) ? $user : null),
        'canSchedule' => editorialCan('schedule', is_array($user) ? $user : null),
        'notice' => $notice,
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], ['title' => $heading . ' — админка', 'noindex' => true]);
}

/** @param array<string, mixed> $source
 *  @return array<string, mixed>
 */
function contentAdminBlockValues(array $source): array
{
    return [
        'title' => (string) ($source['title'] ?? ''),
        'slug' => (string) ($source['slug'] ?? ''),
        'subtitle' => (string) ($source['subtitle'] ?? ''),
        'text' => (string) ($source['text'] ?? ''),
        'image' => (string) ($source['image'] ?? ''),
        'image_alt' => (string) ($source['image_alt'] ?? ''),
        'link' => (string) ($source['link'] ?? ''),
        'link_label' => (string) ($source['link_label'] ?? ''),
        'layout' => (string) ($source['layout'] ?? 'text'),
        'active' => (int) ($source['active'] ?? 1) === 1,
    ];
}

/** @return array<string, mixed> */
function contentAdminBlockValuesFromPost(): array
{
    return [
        'title' => postString('title'),
        'slug' => postString('slug'),
        'subtitle' => postString('subtitle'),
        'text' => postString('text'),
        'image' => postString('image'),
        'image_alt' => postString('image_alt'),
        'link' => postString('link'),
        'link_label' => postString('link_label'),
        'layout' => postString('layout'),
        'active' => isOn($_POST['active'] ?? null),
    ];
}

/** @param array<string, mixed>|null $block
 *  @param array<string, mixed> $values
 */
function contentAdminBlockScreen(?array $block, array $values, string $alertReason = '', string $notice = ''): void
{
    $isNew = $block === null;
    $blockId = $isNew ? '' : (string) $block['id'];

    if (!$isNew) {
        contentRevisionEnsureBaseline('block', $blockId);
    }

    render('admin/content-block', [
        'block' => $block,
        'values' => $values,
        'version' => $isNew ? 0 : contentRevisionVersion('block', $blockId),
        'notice' => $notice,
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], [
        'title' => ($isNew ? 'Новый глобальный блок' : 'Блок «' . (string) $block['title'] . '»') . ' — админка',
        'noindex' => true,
    ]);
}

// ---------------------------------------------------------------------------
// Экраны
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function coreAdminContent(array $params = []): void
{
    $alertReason = takeAdminFlashReason();
    $rows = [];

    // Экран открывается и на копии без миграции 0005: тогда он показывает пустые списки вместо
    // фатала на отсутствующей таблице. Доменные операции всё равно откажутся от записи.
    if (!contentTablesReady()) {
        $alertReason = 'Таблицы RELEASE 2.3 не найдены — примените обновление ядра.';
    }

    foreach (contentTablesReady() ? contentTypesAll(true) : [] as $type) {
        $rows[] = [
            'id' => (string) $type['id'],
            'slug' => (string) $type['slug'],
            'name' => (string) $type['name'],
            'description' => (string) $type['description'],
            'archived' => $type['archived_at'] !== null,
            'entries' => (int) value(
                'SELECT COUNT(*) FROM ' . t('content_entry') . ' WHERE type_id = ?',
                [(string) $type['id']]
            ),
        ];
    }

    render('admin/content', [
        'types' => $rows,
        'blocks' => grantsAppManage() ? globalBlocksAll(true) : [],
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], ['title' => 'Контент — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreAdminContentTypeNew(array $params = []): void
{
    $alertReason = takeAdminFlashReason();
    contentAdminTypeScreen(null, contentAdminTypeValues([]), [], $alertReason, noticeForAdminScreen(CORE_NOTICE_TEXT));
}

/** @param array<string, string> $params */
function coreAdminContentTypeEdit(array $params = []): void
{
    $type = contentTypeById((string) ($params['id'] ?? ''));

    if ($type === null) {
        redirect('/admin/content');
    }

    $alertReason = takeAdminFlashReason();
    contentAdminTypeScreen($type, contentAdminTypeValues($type), contentAdminFieldRows(contentTypeFields((string) $type['id'], true)), $alertReason, noticeForAdminScreen(CORE_NOTICE_TEXT));
}

/** @param array<string, string> $params */
function coreAdminContentEntries(array $params = []): void
{
    $type = contentTypeById((string) ($params['id'] ?? ''));

    if ($type === null) {
        redirect('/admin/content');
    }

    $status = queryString('status', '');
    $status = is_string($status) && in_array($status, CONTENT_STATUSES, true) ? $status : '';
    $alertReason = takeAdminFlashReason();

    render('admin/content-entries', [
        'type' => $type,
        'status' => $status,
        'rows' => contentEntriesForType((string) $type['id'], $status),
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], ['title' => 'Записи «' . (string) $type['name'] . '» — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreAdminContentEntryNew(array $params = []): void
{
    $type = contentTypeById((string) ($params['id'] ?? ''));

    if ($type === null || $type['archived_at'] !== null) {
        redirect('/admin/content');
    }

    $alertReason = takeAdminFlashReason();
    contentAdminEntryScreen(
        $type,
        null,
        contentTypeFields((string) $type['id']),
        contentAdminEntryValues([]),
        [],
        [],
        [],
        [],
        $alertReason,
        noticeForAdminScreen(CORE_NOTICE_TEXT)
    );
}

/** @param array<string, string> $params */
function coreAdminContentEntryEdit(array $params = []): void
{
    $entry = contentEntryById((string) ($params['id'] ?? ''));

    if ($entry === null) {
        redirect('/admin/content');
    }

    $type = contentTypeById((string) $entry['type_id']);

    if ($type === null) {
        redirect('/admin/content');
    }

    $relations = contentEntryRelations((string) $entry['id']);
    $relationIds = [];
    foreach ($relations as $key => $rows) {
        $relationIds[$key] = [];
        foreach ($rows as $row) {
            $relationIds[$key][] = (string) $row['id'];
        }
    }

    $alertReason = takeAdminFlashReason();
    contentAdminEntryScreen(
        $type,
        $entry,
        contentTypeFields((string) $type['id']),
        contentAdminEntryValues($entry),
        contentEntryData($entry),
        $relationIds,
        [],
        contentEntrySchedules((string) $entry['id']),
        $alertReason,
        noticeForAdminScreen(CORE_NOTICE_TEXT)
    );
}

/** @param array<string, string> $params */
function coreAdminContentBlocks(array $params = []): void
{
    $alertReason = takeAdminFlashReason();

    render('admin/content-blocks', [
        'blocks' => globalBlocksAll(true),
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'alertTitle' => adminFailureTitle($alertReason),
        'alertReason' => $alertReason,
    ], ['title' => 'Глобальные блоки — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function coreAdminContentBlockNew(array $params = []): void
{
    $alertReason = takeAdminFlashReason();
    contentAdminBlockScreen(null, contentAdminBlockValues([]), $alertReason, noticeForAdminScreen(CORE_NOTICE_TEXT));
}

/** @param array<string, string> $params */
function coreAdminContentBlockEdit(array $params = []): void
{
    $block = globalBlockById((string) ($params['id'] ?? ''));

    if ($block === null) {
        redirect('/admin/content/blocks');
    }

    $alertReason = takeAdminFlashReason();
    contentAdminBlockScreen($block, contentAdminBlockValues($block), $alertReason, noticeForAdminScreen(CORE_NOTICE_TEXT));
}

// ---------------------------------------------------------------------------
// Действия
// ---------------------------------------------------------------------------

/** @param array<string, string> $params */
function coreAdminContentTypeSave(array $params = []): void
{
    csrfCheck();

    $values = contentAdminTypeValuesFromPost();
    $rows = contentAdminFieldRowsFromPost(postAll());
    $meta = ['user' => currentUser(), 'comment' => postString('comment')];
    $result = contentTypeSave(null, $values, contentAdminDomainRowsFromPost(postAll()), null, $meta);

    if (($result['ok'] ?? false) !== true) {
        contentAdminTypeScreen(null, $values, $rows, (string) ($result['error'] ?? ''));

        return;
    }

    unset($_SESSION['admin_error']);
    redirectWithNotice('/admin/content/types/' . rawurlencode((string) $result['id']), 'content_type_created');
}

/** @param array<string, string> $params */
function coreAdminContentTypeAction(array $params = []): void
{
    csrfCheck();

    $typeId = (string) ($params['id'] ?? '');
    $op = postString('op');
    $to = '/admin/content/types/' . rawurlencode($typeId);

    if ($op === 'save-type') {
        $values = contentAdminTypeValuesFromPost();
        $rows = contentAdminFieldRowsFromPost(postAll());
        $meta = ['user' => currentUser(), 'comment' => postString('comment')];
        $result = contentTypeSave($typeId, $values, contentAdminDomainRowsFromPost(postAll()), null, $meta);

        if (($result['ok'] ?? false) !== true) {
            $type = contentTypeById($typeId);
            contentAdminTypeScreen($type, $values, $rows, (string) ($result['error'] ?? ''));

            return;
        }

        finishAppAction($result, $to, 'content_type_saved');

        return;
    }

    $result = match ($op) {
        'archive-type' => contentTypeArchive($typeId, true),
        'restore-type' => contentTypeArchive($typeId, false),
        default => ['ok' => false, 'error' => 'Неизвестное действие с типом записей.', 'id' => $typeId],
    };
    $notice = match ($op) {
        'archive-type' => 'content_type_archived',
        'restore-type' => 'content_type_restored',
        default => '',
    };

    finishAppAction($result, $op === 'archive-type' ? '/admin/content' : $to, $notice);
}

/** @param array<string, string> $params */
function coreAdminContentEntriesAction(array $params = []): void
{
    csrfCheck();

    $typeId = (string) ($params['id'] ?? '');
    $op = postString('bulk_op');
    $to = '/admin/content/types/' . rawurlencode($typeId) . '/entries';
    $meta = ['user' => currentUser(), 'comment' => 'Массовое изменение записей'];
    $status = $op === 'bulk-archive' ? CONTENT_STATUS_ARCHIVED : ($op === 'bulk-restore' ? CONTENT_STATUS_DRAFT : '');
    $done = 0;
    $failed = '';

    if ($status !== '' && contentTypeById($typeId) !== null) {
        foreach (postList('ids') as $entryId) {
            $entry = contentEntryById($entryId);

            if ($entry === null || (string) $entry['type_id'] !== $typeId) {
                continue;
            }

            $result = contentEntryStatus($entryId, $status, null, $meta);

            if (($result['ok'] ?? false) === true) {
                $done++;
            } elseif ($failed === '') {
                $failed = (string) ($result['error'] ?? '');
            }
        }
    }

    $result = $status === '' || $failed !== ''
        ? ['ok' => false, 'error' => $failed !== '' ? $failed : 'Неизвестное действие со списком записей.']
        : ['ok' => true, 'error' => ''];

    finishAppAction($result, $to, $done > 0 ? 'content_entries_bulk_done' : '');
}

/** @param array<string, string> $params */
function coreAdminContentEntryCreate(array $params = []): void
{
    csrfCheck();

    $typeId = (string) ($params['id'] ?? '');
    $type = contentTypeById($typeId);
    $values = contentAdminEntryValuesFromPost();
    $post = postAll();
    $fields = $type === null ? [] : contentTypeFields($typeId);
    $uploaded = contentAdminEntryPostWithUploads($post, $fields);
    $post = $uploaded['post'];
    $fallback = contentAdminPostedFields($post);

    if ($uploaded['error'] !== '' && $type !== null) {
        contentAdminEntryScreen(
            $type,
            null,
            $fields,
            $values,
            $fallback['data'],
            $fallback['relations'],
            [],
            [],
            $uploaded['error']
        );

        return;
    }

    $meta = ['user' => currentUser(), 'comment' => postString('comment')];
    $result = contentEntryCreate($typeId, $values, $post, null, $meta);

    if (($result['ok'] ?? false) !== true || $type === null) {
        $error = $uploaded['error'] !== ''
            ? $uploaded['error']
            : ($type === null ? 'Тип записей не найден.' : (string) ($result['error'] ?? ''));
        $data = is_array($result['data'] ?? null) ? $result['data'] : $fallback['data'];
        $relations = is_array($result['relations'] ?? null) ? $result['relations'] : $fallback['relations'];

        if ($type === null) {
            redirect('/admin/content');
        }

        contentAdminEntryScreen(
            $type,
            null,
            $fields,
            is_array($result['values'] ?? null) ? $result['values'] : $values,
            $data + $fallback['data'],
            $relations,
            is_array($result['errors'] ?? null) ? $result['errors'] : [],
            [],
            $error
        );

        return;
    }

    unset($_SESSION['admin_error']);
    redirectWithNotice('/admin/content/entries/' . rawurlencode((string) $result['id']), 'content_entry_created');
}

/** @param array<string, string> $params */
function coreAdminContentEntryAction(array $params = []): void
{
    csrfCheck();

    $entryId = (string) ($params['id'] ?? '');
    $entry = contentEntryById($entryId);
    $to = '/admin/content/entries/' . rawurlencode($entryId);
    $op = postString('op');

    if ($entry === null) {
        finishAppAction(['ok' => false, 'error' => 'Запись исчезла — обновите список.'], '/admin/content', '');

        return;
    }

    $type = contentTypeById((string) $entry['type_id']);

    if ($type === null) {
        finishAppAction(['ok' => false, 'error' => 'Тип записей не найден.'], '/admin/content', '');

        return;
    }

    $user = currentUser();
    $meta = [
        'user' => is_array($user) ? $user : null,
        'comment' => postString('comment'),
        'expected_version' => postString('expected_version'),
        'idempotency_key' => newId(),
    ];
    $fields = contentTypeFields((string) $type['id']);
    $fallback = contentAdminPostedFields(postAll());

    if ($op === 'save-entry') {
        $values = contentAdminEntryValuesFromPost();
        $post = postAll();
        $uploaded = contentAdminEntryPostWithUploads($post, $fields);
        $post = $uploaded['post'];
        $fallback = contentAdminPostedFields($post);

        if ($uploaded['error'] !== '') {
            contentAdminEntryScreen(
                $type,
                $entry,
                $fields,
                $values,
                $fallback['data'],
                $fallback['relations'],
                [],
                contentEntrySchedules($entryId),
                $uploaded['error']
            );

            return;
        }

        $result = contentEntryUpdate($entryId, $values, $post, null, $meta);

        if (($result['ok'] ?? false) !== true) {
            $data = is_array($result['data'] ?? null) ? $result['data'] : $fallback['data'];
            $relations = is_array($result['relations'] ?? null) ? $result['relations'] : $fallback['relations'];
            $error = (string) ($result['error'] ?? '');
            $schedules = contentEntrySchedules($entryId);

            contentAdminEntryScreen(
                $type,
                contentEntryById($entryId) ?? $entry,
                $fields,
                is_array($result['values'] ?? null) ? $result['values'] : $values,
                $data + $fallback['data'],
                $relations,
                is_array($result['errors'] ?? null) ? $result['errors'] : [],
                $schedules,
                $error
            );

            return;
        }

        finishAppAction($result, $to, 'content_entry_saved');

        return;
    }

    if ($op === 'schedule') {
        $userArray = is_array($user) ? $user : [];
        $result = contentEntrySchedule(
            $entryId,
            postString('publish_at'),
            scheduleTimezone(postString('timezone')),
            $userArray
        );
        $scheduled = ($result['ok'] ?? false) === true;
        finishAppAction($result, $to, $scheduled ? 'content_entry_scheduled' : '');

        return;
    }

    if ($op === 'cancel-schedule') {
        $result = contentEntryScheduleCancel(postString('schedule'), is_array($user) ? $user : []);
        $cancelled = ($result['ok'] ?? false) === true;
        finishAppAction($result, $to, $cancelled ? 'content_schedule_cancelled' : '');

        return;
    }

    if ($op === 'status') {
        $status = postString('status');

        // Запланированная публикация убирается только явной отменой: смена статуса не должна
        // оставлять в планировщике задачу, которая потом перезапишет свежее решение редакции.
        if ($status === CONTENT_STATUS_SCHEDULED || contentEntrySchedules($entryId) !== []) {
            finishAppAction(
                ['ok' => false, 'error' => 'Сначала отмените запланированную публикацию.'],
                $to,
                ''
            );

            return;
        }

        $result = contentEntryStatus($entryId, $status, null, $meta);
        $notice = match ($status) {
            CONTENT_STATUS_PUBLISHED => 'content_entry_published',
            CONTENT_STATUS_ARCHIVED => 'content_entry_archived',
            CONTENT_STATUS_SCHEDULED => 'content_entry_scheduled',
            CONTENT_STATUS_DRAFT => 'content_entry_unpublished',
            default => '',
        };
        finishAppAction($result, $to, $notice);

        return;
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие с записью.'], $to, '');
}

/** @param array<string, string> $params */
function coreAdminContentBlockSave(array $params = []): void
{
    csrfCheck();

    $values = contentAdminBlockValuesFromPost();
    $file = uploadedFile('image_file');

    if ($file !== null) {
        $stored = pageImageStore($file);

        if (($stored['ok'] ?? false) !== true) {
            contentAdminBlockScreen(null, $values, (string) ($stored['error'] ?? 'Файл не принят.'));

            return;
        }

        $values['image'] = (string) $stored['href'];
    }

    $result = globalBlockSave(null, $values, null, ['user' => currentUser(), 'comment' => postString('comment')]);

    if (($result['ok'] ?? false) !== true) {
        contentAdminBlockScreen(null, $values, (string) ($result['error'] ?? ''));

        return;
    }

    unset($_SESSION['admin_error']);
    redirectWithNotice('/admin/content/blocks/' . rawurlencode((string) $result['id']), 'content_block_created');
}

/** @param array<string, string> $params */
function coreAdminContentBlockAction(array $params = []): void
{
    csrfCheck();

    $blockId = (string) ($params['id'] ?? '');
    $op = postString('op');
    $to = '/admin/content/blocks/' . rawurlencode($blockId);

    if ($op === 'save-block') {
        $values = contentAdminBlockValuesFromPost();
        $file = uploadedFile('image_file');
        $uploadError = '';

        if ($file !== null) {
            $stored = pageImageStore($file);

            if (($stored['ok'] ?? false) !== true) {
                $uploadError = (string) ($stored['error'] ?? 'Файл не принят.');
            } else {
                $values['image'] = (string) $stored['href'];
            }
        }

        $meta = [
            'user' => currentUser(),
            'comment' => postString('comment'),
            'expected_version' => postString('expected_version'),
            'idempotency_key' => newId(),
        ];
        $result = globalBlockSave($blockId, $values, null, $meta);

        if ($uploadError !== '' || ($result['ok'] ?? false) !== true) {
            $block = globalBlockById($blockId);
            contentAdminBlockScreen(
                $block,
                $values,
                $uploadError !== '' ? $uploadError : (string) ($result['error'] ?? '')
            );

            return;
        }

        finishAppAction($result, $to, 'content_block_saved');

        return;
    }

    $result = match ($op) {
        'archive-block' => globalBlockSetActive($blockId, false),
        'restore-block' => globalBlockSetActive($blockId, true),
        default => ['ok' => false, 'error' => 'Неизвестное действие с блоком.', 'id' => $blockId],
    };
    $notice = match ($op) {
        'archive-block' => 'content_block_archived',
        'restore-block' => 'content_block_restored',
        default => '',
    };

    finishAppAction($result, $op === 'archive-block' ? '/admin/content/blocks' : $to, $notice);
}
