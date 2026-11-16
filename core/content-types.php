<?php

declare(strict_types=1);

// RELEASE 2.3: пользовательские типы записей, конструктор полей, связи и глобальные блоки.
// Модуль не меняет таблицы прошлых релизов: всё новое живёт в собственных таблицах, а в историю
// ревизий RELEASE 2.2 новые сущности входят типами entry и block. Публичный адрес типа —
// /{type-slug}, записи — /{type-slug}/{entry-slug}; прежние адреса переадресуются 301.
//
// Правила прав: управление типами, полями и блоками — только администратор (маршрут и проверка
// внутри операций), правка записи — editorialCan('edit'), публикация/архив — editorialCan('publish').
// Сохранение записи атомарно: поля, связи, старый адрес и ревизия пишутся одной транзакцией.

const CONTENT_SLUG_MIN = 3;
const CONTENT_SLUG_MAX = 80;
const CONTENT_FIELD_KEY_MAX = 64;
const CONTENT_FIELDS_LIMIT = 40;
const CONTENT_RELATIONS_LIMIT = 100;
const CONTENT_LIST_LIMIT = 200;
const CONTENT_TITLE_MAX = 190;

const CONTENT_STATUS_DRAFT = 'draft';
const CONTENT_STATUS_PUBLISHED = 'published';
const CONTENT_STATUS_SCHEDULED = 'scheduled';
const CONTENT_STATUS_ARCHIVED = 'archived';

/** @var list<string> */
const CONTENT_STATUSES = [
    CONTENT_STATUS_DRAFT,
    CONTENT_STATUS_PUBLISHED,
    CONTENT_STATUS_SCHEDULED,
    CONTENT_STATUS_ARCHIVED,
];

/** @var array<string, string> */
const CONTENT_STATUS_LABELS = [
    CONTENT_STATUS_DRAFT => 'Черновик',
    CONTENT_STATUS_PUBLISHED => 'Опубликовано',
    CONTENT_STATUS_SCHEDULED => 'Запланировано',
    CONTENT_STATUS_ARCHIVED => 'Архив',
];

// Виды полей. Набор намеренно короткий: каждый вид имеет серверную проверку и способ печати,
// поэтому «поле на будущее» не превращается в дыру, куда можно положить что угодно.
const CONTENT_FIELD_TEXT = 'text';
const CONTENT_FIELD_TEXTAREA = 'textarea';
const CONTENT_FIELD_RICHTEXT = 'richtext';
const CONTENT_FIELD_NUMBER = 'number';
const CONTENT_FIELD_CHECKBOX = 'checkbox';
const CONTENT_FIELD_SELECT = 'select';
const CONTENT_FIELD_IMAGE = 'image';
const CONTENT_FIELD_URL = 'url';
const CONTENT_FIELD_DATE = 'date';
const CONTENT_FIELD_RELATION = 'relation';

/** @var array<string, string> */
const CONTENT_FIELD_KIND_NAMES = [
    CONTENT_FIELD_TEXT => 'Строка',
    CONTENT_FIELD_TEXTAREA => 'Многострочный текст',
    CONTENT_FIELD_RICHTEXT => 'Текст с абзацами',
    CONTENT_FIELD_NUMBER => 'Число',
    CONTENT_FIELD_CHECKBOX => 'Флажок',
    CONTENT_FIELD_SELECT => 'Выбор из списка',
    CONTENT_FIELD_IMAGE => 'Картинка',
    CONTENT_FIELD_URL => 'Ссылка',
    CONTENT_FIELD_DATE => 'Дата',
    CONTENT_FIELD_RELATION => 'Связь с записями',
];

/** @var array<string, int> */
const CONTENT_FIELD_LIMITS = [
    CONTENT_FIELD_TEXT => 500,
    CONTENT_FIELD_TEXTAREA => 5000,
    CONTENT_FIELD_RICHTEXT => 20000,
    CONTENT_FIELD_NUMBER => 40,
    CONTENT_FIELD_SELECT => 190,
    CONTENT_FIELD_IMAGE => 255,
    CONTENT_FIELD_URL => 255,
    CONTENT_FIELD_DATE => 10,
];

const CONTENT_SEO_TITLE_MAX = 190;
const CONTENT_SEO_DESCRIPTION_MAX = 300;

// Глобальный блок: набор полей фиксирован ядром, поэтому отдельного конструктора у блока нет.
/** @var array<string, string> */
const GLOBAL_BLOCK_LAYOUTS = [
    'text' => 'Текст',
    'media-left' => 'Картинка слева',
    'media-right' => 'Картинка справа',
    'banner' => 'Баннер',
];

const GLOBAL_BLOCK_TITLE_MAX = 190;
const GLOBAL_BLOCK_SUBTITLE_MAX = 255;
const GLOBAL_BLOCK_TEXT_MAX = 20000;
const GLOBAL_BLOCK_IMAGE_MAX = 255;
const GLOBAL_BLOCK_IMAGE_ALT_MAX = 300;
const GLOBAL_BLOCK_LINK_MAX = 255;
const GLOBAL_BLOCK_LINK_LABEL_MAX = 120;

/** Проверка таблиц без падения на ненакатанной миграции: экраны и поиск спрашивают её заранее. */
function contentTableExists(string $table): bool
{
    try {
        return tableExists($table);
    } catch (Throwable $error) {
        return false;
    }
}

function contentTablesReady(): bool
{
    foreach (['content_type', 'content_field', 'content_entry', 'content_entry_relation', 'global_block'] as $table) {
        if (!contentTableExists($table)) {
            return false;
        }
    }

    return true;
}

/** Служебный сегмент: его нельзя занять ни типу, ни странице. */
function contentSlugReserved(string $slug): bool
{
    return in_array($slug, PAGES_RESERVED_SLUGS, true);
}

/**
 * Занятость адреса типа. Своя строка при правке не считается занятием; архивные типы адрес не
 * держат, поэтому после архива его может занять страница или другой тип.
 */
function contentTypeSlugTaken(string $slug, string $excludeId = ''): bool
{
    if ($slug === '' || contentSlugReserved($slug)) {
        return true;
    }

    $pageBusy = (int) value(
        'SELECT COUNT(*) FROM ' . t('page') . ' WHERE slug = ?',
        [$slug]
    ) > 0;

    if ($pageBusy) {
        return true;
    }

    if (!contentTableExists('content_type')) {
        return false;
    }

    $sql = 'SELECT COUNT(*) FROM ' . t('content_type') . ' WHERE slug = ? AND archived_at IS NULL'
        . ($excludeId !== '' ? ' AND id <> ?' : '');
    $args = $excludeId !== '' ? [$slug, $excludeId] : [$slug];

    return (int) value($sql, $args) > 0;
}

/**
 * План адреса типа: пустой ввод собирается из названия, вписанный руками не нумеруется.
 * Форма ответа та же, что у pageSlugPlan: экран печатает error, обработчик не сохраняет.
 *
 * @param callable(string): bool $exists
 * @return array{slug: string, error: string}
 */
function contentTypeSlugPlan(string $typedSlug, string $name, callable $exists): array
{
    $typed = slugify($typedSlug, CONTENT_SLUG_MAX);

    if ($typed !== '') {
        if (contentSlugReserved($typed)) {
            return ['slug' => '', 'error' => 'Адрес «' . $typed . '» занят служебным разделом — выберите другой.'];
        }

        if (mb_strlen($typed) < CONTENT_SLUG_MIN) {
            return ['slug' => '', 'error' => 'Адрес короче ' . CONTENT_SLUG_MIN . ' символов — по нему открывают раздел.'];
        }

        if ($exists($typed)) {
            return ['slug' => '', 'error' => 'Адрес «' . $typed . '» уже занят — выберите другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($name, CONTENT_SLUG_MAX);

    if ($base === '') {
        return ['slug' => '', 'error' => 'Не удалось собрать адрес из названия — впишите адрес сами.'];
    }

    if (in_array($base, PAGES_RESERVED_SLUGS, true)) {
        return ['slug' => '', 'error' => 'Адрес «' . $base . '» занят служебным разделом — впишите другой.'];
    }

    return ['slug' => ensureUniqueSlug($base, $exists, 'razdel'), 'error' => ''];
}

/**
 * План адреса записи внутри типа. Уникальность считается только внутри типа: /statyi/novost
 * и /sotrudniki/novost — разные адреса и друг другу не мешают.
 *
 * @param callable(string): bool $exists
 * @return array{slug: string, error: string}
 */
function contentEntrySlugPlan(string $typedSlug, string $title, callable $exists): array
{
    $typed = slugify($typedSlug, CONTENT_SLUG_MAX);

    if ($typed !== '') {
        if (mb_strlen($typed) < CONTENT_SLUG_MIN) {
            return ['slug' => '', 'error' => 'Адрес записи короче ' . CONTENT_SLUG_MIN . ' символов — выберите длиннее.'];
        }

        if ($exists($typed)) {
            return ['slug' => '', 'error' => 'Адрес «' . $typed . '» уже занят записью этого типа — выберите другой.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($title, CONTENT_SLUG_MAX);

    if ($base === '') {
        return ['slug' => '', 'error' => 'Не удалось собрать адрес из заголовка — впишите адрес сами.'];
    }

    return ['slug' => ensureUniqueSlug($base, $exists, 'zapis'), 'error' => ''];
}

function contentEntrySlugTaken(string $typeId, string $slug, string $excludeId = ''): bool
{
    if ($slug === '' || !contentTableExists('content_entry')) {
        return $slug === '';
    }

    $sql = 'SELECT COUNT(*) FROM ' . t('content_entry') . ' WHERE type_id = ? AND slug = ?'
        . ($excludeId !== '' ? ' AND id <> ?' : '');
    $args = $excludeId !== '' ? [$typeId, $slug, $excludeId] : [$typeId, $slug];

    return (int) value($sql, $args) > 0;
}

/** @return array<string, mixed>|null */
function contentTypeById(string $id): ?array
{
    if ($id === '' || !contentTableExists('content_type')) {
        return null;
    }

    return one(
        'SELECT id, slug, name, description, sort, archived_at, created_at, updated_at FROM ' . t('content_type')
        . ' WHERE id = ? LIMIT 1',
        [$id]
    );
}

/** @return array<string, mixed>|null */
function contentTypeBySlug(string $slug, bool $includeArchived = false): ?array
{
    if ($slug === '' || !contentTableExists('content_type')) {
        return null;
    }

    return one(
        'SELECT id, slug, name, description, sort, archived_at, created_at, updated_at FROM ' . t('content_type')
        . ' WHERE slug = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' LIMIT 1',
        [$slug]
    );
}

/** @return list<array<string, mixed>> */
function contentTypesAll(bool $includeArchived = false): array
{
    if (!contentTableExists('content_type')) {
        return [];
    }

    return all(
        'SELECT id, slug, name, description, sort, archived_at, created_at, updated_at FROM ' . t('content_type')
        . ($includeArchived ? '' : ' WHERE archived_at IS NULL')
        . ' ORDER BY sort, name, id LIMIT ' . CONTENT_LIST_LIMIT
    );
}

/** @return list<array<string, mixed>> */
function contentTypeFields(string $typeId, bool $includeArchived = false): array
{
    if ($typeId === '' || !contentTableExists('content_field')) {
        return [];
    }

    return all(
        'SELECT id, type_id, field_key, label, kind, hint, required, options, sort, archived_at, created_at, updated_at'
        . ' FROM ' . t('content_field')
        . ' WHERE type_id = ?' . ($includeArchived ? '' : ' AND archived_at IS NULL')
        . ' ORDER BY sort, id LIMIT ' . (CONTENT_FIELDS_LIMIT * 2),
        [$typeId]
    );
}

/** @param array<string, mixed> $field
 *  @return array{choices: list<string>, types: list<string>}
 */
function contentFieldOptions(array $field): array
{
    $options = ['choices' => [], 'types' => []];
    $decoded = json_decode((string) ($field['options'] ?? ''), true);

    if (!is_array($decoded)) {
        return $options;
    }

    foreach (is_array($decoded['choices'] ?? null) ? $decoded['choices'] : [] as $choice) {
        if (is_string($choice) && trim($choice) !== '') {
            $options['choices'][] = trim($choice);
        }
    }

    foreach (is_array($decoded['types'] ?? null) ? $decoded['types'] : [] as $typeId) {
        if (is_string($typeId) && $typeId !== '') {
            $options['types'][] = $typeId;
        }
    }

    $options['choices'] = array_values(array_unique($options['choices']));
    $options['types'] = array_values(array_unique($options['types']));

    return $options;
}

/** Варианты выбора строками — так их правит конструктор полей.
 *  @param array<string, mixed> $field
 */
function contentFieldChoicesText(array $field): string
{
    return implode("\n", contentFieldOptions($field)['choices']);
}

function contentFieldKindValid(string $kind): bool
{
    return array_key_exists($kind, CONTENT_FIELD_KIND_NAMES);
}

/**
 * Ключ поля из ввода. Ключ неизменяем у существующего поля: форма присылает прежний ключ, а
 * новый собирается из подписи. Возвращает пустую строку, если собрать не удалось.
 */
function contentFieldKeyPlan(string $rawKey, string $label): string
{
    $key = strtolower(trim($rawKey));

    if ($key !== '') {
        $key = (string) preg_replace('/[^a-z0-9_]+/', '_', $key);
        $key = trim($key, '_');
    } else {
        $key = str_replace('-', '_', slugify($label, CONTENT_FIELD_KEY_MAX));
    }

    if ($key === '' || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
        return '';
    }

    return $key;
}

/**
 * Разбор конструктора полей. Возвращает нормализованные строки и ошибки; при ошибке ничего не
 * пишется. Убранные поля не удаляются, а уходят в мягкий архив: значения остаются в JSON.
 *
 * @param array<string, mixed> $rawRows индексированные поля формы (field_key[0], field_label[0], …)
 * @param list<array<string, mixed>> $existing активные поля типа
 * @param list<array<string, mixed>> $types все типы для проверки вариантов связи
 * @return array{rows: list<array<string, mixed>>, archive: list<string>, error: string}
 */
function contentFieldsPlan(array $rawRows, array $existing, array $types): array
{
    $fail = static fn (string $error, array $rows = []): array => [
        'rows' => $rows,
        'archive' => [],
        'error' => $error,
    ];

    $existingByKey = [];
    foreach ($existing as $field) {
        $existingByKey[(string) $field['field_key']] = $field;
    }

    $typeIds = [];
    foreach ($types as $type) {
        $typeIds[(string) $type['id']] = (string) $type['name'];
    }

    $rows = [];
    $seen = [];
    $archive = [];
    $count = 0;

    for ($index = 0; $index < CONTENT_FIELDS_LIMIT; $index++) {
        $rawLabel = is_string($rawRows['field_label'][$index] ?? null) ? trim($rawRows['field_label'][$index]) : '';
        $rawKey = is_string($rawRows['field_key'][$index] ?? null) ? trim($rawRows['field_key'][$index]) : '';
        $original = is_string($rawRows['field_original'][$index] ?? null) ? trim($rawRows['field_original'][$index]) : '';

        // Пустая новая строка — не ошибка: форма всегда печатает несколько заготовок.
        if ($rawLabel === '' && $rawKey === '' && $original === '') {
            continue;
        }

        $label = mb_substr($rawLabel, 0, 190);

        if ($label === '') {
            return $fail('У поля №' . ($count + 1) . ' не заполнена подпись.');
        }

        $key = $original !== '' ? $original : contentFieldKeyPlan($rawKey, $label);

        if ($key === '') {
            return $fail('Ключ поля «' . $label . '» собирается только из латинских букв, цифр и подчёркиваний.');
        }

        if ($original !== '' && $rawKey !== '' && $rawKey !== $original) {
            return $fail('Ключ поля «' . $label . '» менять нельзя: по нему уже сохранены значения.');
        }

        if (isset($seen[$key])) {
            return $fail('Ключ поля «' . $key . '» повторяется — у каждого поля ключ свой.');
        }

        $seen[$key] = true;
        $kind = is_string($rawRows['field_kind'][$index] ?? null) ? (string) $rawRows['field_kind'][$index] : '';

        if (!contentFieldKindValid($kind)) {
            return $fail('У поля «' . $label . '» выбран неизвестный вид.');
        }

        $required = isOn($rawRows['field_required'][$index] ?? null);
        $hint = is_string($rawRows['field_hint'][$index] ?? null) ? mb_substr(trim($rawRows['field_hint'][$index]), 0, 255) : '';
        $options = ['choices' => [], 'types' => []];

        if ($kind === CONTENT_FIELD_SELECT) {
            $rawChoices = is_string($rawRows['field_options'][$index] ?? null) ? $rawRows['field_options'][$index] : '';
            $choices = [];

            foreach (preg_split('/\R/u', $rawChoices) ?: [] as $choice) {
                $choice = mb_substr(trim($choice), 0, CONTENT_FIELD_LIMITS[CONTENT_FIELD_SELECT]);

                if ($choice !== '' && !in_array($choice, $choices, true)) {
                    $choices[] = $choice;
                }
            }

            if ($choices === []) {
                return $fail('У поля «' . $label . '» нет ни одного варианта выбора.');
            }

            if (count($choices) > 100) {
                return $fail('У поля «' . $label . '» больше 100 вариантов — сократите список.');
            }

            $options['choices'] = $choices;
        }

        if ($kind === CONTENT_FIELD_RELATION) {
            $rawTypes = is_array($rawRows['field_types'][$index] ?? null) ? $rawRows['field_types'][$index] : [];

            foreach ($rawTypes as $typeId) {
                if (!is_string($typeId) || !isset($typeIds[$typeId]) || in_array($typeId, $options['types'], true)) {
                    continue;
                }

                $options['types'][] = $typeId;
            }
        }

        $existingField = $existingByKey[$key] ?? null;
        $rows[] = [
            'id' => is_array($existingField) ? (string) $existingField['id'] : '',
            'field_key' => $key,
            'label' => $label,
            'kind' => $kind,
            'hint' => $hint,
            'required' => $required ? 1 : 0,
            'options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort' => $count,
            'archived_at' => null,
        ];
        $count++;
    }

    if ($rows === [] && $existing !== []) {
        return $fail('Нельзя убрать все поля типа: оставьте хотя бы одно или заархивируйте тип.');
    }

    if (count($rows) > CONTENT_FIELDS_LIMIT) {
        return $fail('Полей больше ' . CONTENT_FIELDS_LIMIT . ' — сократите конструктор.');
    }

    foreach ($existingByKey as $key => $field) {
        if (!isset($seen[$key])) {
            $archive[] = $key;
        }
    }

    return ['rows' => $rows, 'archive' => $archive, 'error' => ''];
}

/**
 * Сохранение типа и конструктора полей одной транзакцией. $id === null — создание. Тип без полей
 * допустим только при создании; на правке пустой конструктор отклоняется, чтобы не спрятать все
 * значения формы одним сохранением.
 *
 * @param array<string, mixed> $values name, slug, description, sort
 * @param array<string, mixed> $rawRows поля формы конструктора
 * @param array<string, mixed> $meta user, comment
 * @return array<string, mixed>
 */
function contentTypeSave(?string $id, array $values, array $rawRows, ?DateTimeImmutable $now = null, array $meta = []): array
{
    $fail = static fn (string $error, array $extra = []): array => array_merge([
        'ok' => false,
        'error' => $error,
        'id' => '',
        'slug' => '',
        'notice' => '',
    ], $extra);

    if (!contentTablesReady()) {
        return $fail('Таблицы RELEASE 2.3 не найдены — примените обновление ядра.');
    }

    if (!grantsAppManage()) {
        return $fail('Типами записей управляет только администратор.');
    }

    $existing = $id !== null ? contentTypeById($id) : null;

    if ($id !== null && $existing === null) {
        return $fail('Тип не найден — обновите список.');
    }

    $name = mb_substr(trim((string) ($values['name'] ?? '')), 0, 190);

    if (mb_strlen($name) < 2) {
        return $fail('Название типа короче двух символов.');
    }

    $plan = contentTypeSlugPlan(
        (string) ($values['slug'] ?? ''),
        $name,
        static fn (string $slug): bool => contentTypeSlugTaken($slug, $id ?? '')
    );

    if ($plan['error'] !== '') {
        return $fail($plan['error'], ['slug' => $plan['slug']]);
    }

    $slug = $plan['slug'];
    $description = mb_substr(trim((string) ($values['description'] ?? '')), 0, 500);
    $sort = (int) ($values['sort'] ?? ($existing['sort'] ?? 0));
    $fields = $existing !== null ? contentTypeFields((string) $existing['id']) : [];
    $fieldPlan = contentFieldsPlan($rawRows, $fields, contentTypesAll(true));

    if ($fieldPlan['error'] !== '') {
        return $fail($fieldPlan['error'], ['fields' => $fieldPlan['rows'], 'values' => $values]);
    }

    $moment = utcStamp($now ?? nowUtc());
    $typeId = $id ?? newId();

    try {
        tx(static function () use ($typeId, $existing, $name, $slug, $description, $sort, $fieldPlan, $moment): void {
            if ($existing === null) {
                insert('content_type', [
                    'id' => $typeId,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    'sort' => $sort,
                    'archived_at' => null,
                    'created_at' => $moment,
                    'updated_at' => $moment,
                ]);
            } else {
                $oldSlug = (string) $existing['slug'];

                run(
                    'UPDATE ' . t('content_type') . ' SET slug = ?, name = ?, description = ?, sort = ?, updated_at = ? WHERE id = ?',
                    [$slug, $name, $description, $sort, $moment, $typeId]
                );

                if ($oldSlug !== $slug) {
                    run('DELETE FROM ' . t('content_type_old_slug') . ' WHERE slug = ?', [$slug]);
                    insert('content_type_old_slug', [
                        'id' => newId(),
                        'type_id' => $typeId,
                        'slug' => $oldSlug,
                        'created_at' => $moment,
                    ]);
                }
            }

            foreach ($fieldPlan['rows'] as $row) {
                $rowId = (string) $row['id'];

                if ($rowId !== '') {
                    run(
                        'UPDATE ' . t('content_field') . ' SET label = ?, kind = ?, hint = ?, required = ?, options = ?,'
                        . ' sort = ?, archived_at = NULL, updated_at = ? WHERE id = ? AND type_id = ?',
                        [
                            (string) $row['label'],
                            (string) $row['kind'],
                            (string) $row['hint'],
                            (int) $row['required'],
                            (string) $row['options'],
                            (int) $row['sort'],
                            $moment,
                            $rowId,
                            $typeId,
                        ]
                    );
                } else {
                    insert('content_field', [
                        'id' => newId(),
                        'type_id' => $typeId,
                        'field_key' => (string) $row['field_key'],
                        'label' => (string) $row['label'],
                        'kind' => (string) $row['kind'],
                        'hint' => (string) $row['hint'],
                        'required' => (int) $row['required'],
                        'options' => (string) $row['options'],
                        'sort' => (int) $row['sort'],
                        'archived_at' => null,
                        'created_at' => $moment,
                        'updated_at' => $moment,
                    ]);
                }
            }

            foreach ($fieldPlan['archive'] as $key) {
                run(
                    'UPDATE ' . t('content_field') . ' SET archived_at = ?, updated_at = ?'
                    . ' WHERE type_id = ? AND field_key = ? AND archived_at IS NULL',
                    [$moment, $moment, $typeId, $key]
                );
            }
        });
    } catch (Throwable $error) {
        return $fail('Тип не сохранился — повторите попытку.', ['fields' => $fieldPlan['rows'], 'values' => $values]);
    }

    $created = $existing === null;
    auditLog(
        $created ? 'content.type_create' : 'content.type_save',
        'content_type',
        $typeId,
        ['slug' => $slug, 'fields' => count($fieldPlan['rows'])],
        $created ? 'Создан тип записей' : 'Сохранён тип записей'
    );
    logInfo('content.type_saved', ['id' => $typeId, 'slug' => $slug, 'created' => $created]);

    return [
        'ok' => true,
        'error' => '',
        'id' => $typeId,
        'slug' => $slug,
        'notice' => $created ? 'content_type_created' : 'content_type_saved',
    ];
}

/**
 * Архив и восстановление типа. Восстановление проверяет адрес: пока тип лежал в архиве, его
 * могла занять страница или другой тип.
 *
 * @return array<string, mixed>
 */
function contentTypeArchive(string $id, bool $archive, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'notice' => '', 'id' => $id];

    if (!grantsAppManage()) {
        return $fail('Типами записей управляет только администратор.');
    }

    $type = contentTypeById($id);

    if ($type === null) {
        return $fail('Тип не найден — обновите список.');
    }

    if ($archive === ($type['archived_at'] !== null)) {
        return ['ok' => true, 'error' => '', 'notice' => $archive ? 'content_type_archived' : 'content_type_restored', 'id' => $id];
    }

    if (!$archive && contentTypeSlugTaken((string) $type['slug'], $id)) {
        return $fail('Адрес «' . (string) $type['slug'] . '» уже занят — сначала смените адрес типа.');
    }

    $moment = utcStamp($now ?? nowUtc());
    run(
        'UPDATE ' . t('content_type') . ' SET archived_at = ?, updated_at = ? WHERE id = ?',
        [$archive ? $moment : null, $moment, $id]
    );

    auditLog(
        $archive ? 'content.type_archive' : 'content.type_restore',
        'content_type',
        $id,
        ['slug' => (string) $type['slug']],
        $archive ? 'Тип записей отправлен в архив' : 'Тип записей восстановлен'
    );

    return ['ok' => true, 'error' => '', 'notice' => $archive ? 'content_type_archived' : 'content_type_restored', 'id' => $id];
}

/** @return array<string, mixed>|null */
function contentEntryById(string $id): ?array
{
    if ($id === '' || !contentTableExists('content_entry')) {
        return null;
    }

    return one(
        'SELECT id, type_id, slug, title, status, data, seo_title, seo_description, created_by, updated_by,'
        . ' created_at, updated_at, published_at FROM ' . t('content_entry') . ' WHERE id = ? LIMIT 1',
        [$id]
    );
}

/** @return array<string, mixed>|null */
function contentEntryBySlug(string $typeId, string $slug): ?array
{
    if ($typeId === '' || $slug === '' || !contentTableExists('content_entry')) {
        return null;
    }

    return one(
        'SELECT id, type_id, slug, title, status, data, seo_title, seo_description, created_by, updated_by,'
        . ' created_at, updated_at, published_at FROM ' . t('content_entry')
        . ' WHERE type_id = ? AND slug = ? LIMIT 1',
        [$typeId, $slug]
    );
}

/** @return list<array<string, mixed>> */
function contentEntriesForType(string $typeId, string $status = '', int $limit = CONTENT_LIST_LIMIT): array
{
    if ($typeId === '' || !contentTableExists('content_entry')) {
        return [];
    }

    $withStatus = in_array($status, CONTENT_STATUSES, true);
    $sql = 'SELECT e.id, e.type_id, e.slug, e.title, e.status, e.seo_title, e.seo_description, e.created_at,'
        . ' e.updated_at, e.published_at FROM ' . t('content_entry') . ' e WHERE e.type_id = ?'
        . ($withStatus ? ' AND e.status = ?' : '')
        . ' ORDER BY e.updated_at DESC, e.title LIMIT ' . max(1, min(CONTENT_LIST_LIMIT, $limit));
    $args = $withStatus ? [$typeId, $status] : [$typeId];

    return all($sql, $args);
}

/** @return list<array<string, mixed>> */
function contentPublishedEntries(string $typeId, int $limit = CONTENT_LIST_LIMIT): array
{
    return contentEntriesForType($typeId, CONTENT_STATUS_PUBLISHED, $limit);
}

/** @param array<string, mixed> $entry
 *  @return array<string, string>
 */
function contentEntryData(array $entry): array
{
    $decoded = json_decode((string) ($entry['data'] ?? ''), true);
    $data = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        if (is_string($key) && is_scalar($value)) {
            $data[$key] = (string) $value;
        }
    }

    return $data;
}

/**
 * Связи записи, сгруппированные по ключу поля. При publishedOnly остаются только опубликованные
 * записи активных типов — публичная страница не показывает ссылку в черновик.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function contentEntryRelations(string $entryId, bool $publishedOnly = false): array
{
    if ($entryId === '' || !contentTableExists('content_entry_relation')) {
        return [];
    }

    $sql = 'SELECT r.field_key, r.sort, e.id, e.slug, e.title, e.status, e.published_at,'
        . ' ty.slug AS type_slug, ty.name AS type_name'
        . ' FROM ' . t('content_entry_relation') . ' r'
        . ' JOIN ' . t('content_entry') . ' e ON e.id = r.to_entry_id'
        . ' JOIN ' . t('content_type') . ' ty ON ty.id = e.type_id'
        . ' WHERE r.from_entry_id = ?'
        . ($publishedOnly ? ' AND e.status = ? AND ty.archived_at IS NULL' : '')
        . ' ORDER BY r.field_key, r.sort, e.title LIMIT ' . (CONTENT_RELATIONS_LIMIT * 5);
    $args = $publishedOnly ? [$entryId, CONTENT_STATUS_PUBLISHED] : [$entryId];
    $grouped = [];

    foreach (all($sql, $args) as $row) {
        $grouped[(string) $row['field_key']][] = $row;
    }

    return $grouped;
}

/**
 * Кандидаты для поля связи: записи разрешённых типов, включая черновики (редактор видит свои
 * материалы), кроме архивных. Пустой список типов в настройках поля означает «любой активный тип».
 *
 * @param array<string, mixed> $field
 *  @return list<array<string, mixed>>
 */
function contentRelationCandidates(array $field): array
{
    if (!contentTableExists('content_entry')) {
        return [];
    }

    $allowed = contentFieldOptions($field)['types'];
    $sql = 'SELECT e.id, e.slug, e.title, e.status, ty.slug AS type_slug, ty.name AS type_name'
        . ' FROM ' . t('content_entry') . ' e'
        . ' JOIN ' . t('content_type') . ' ty ON ty.id = e.type_id'
        . ' WHERE ty.archived_at IS NULL AND e.status <> ?'
        . ($allowed !== [] ? ' AND e.type_id IN (' . inPlaceholders(count($allowed)) . ')' : '')
        . ' ORDER BY ty.name, e.updated_at DESC LIMIT ' . (CONTENT_LIST_LIMIT * 2);
    $args = array_merge([CONTENT_STATUS_ARCHIVED], $allowed);

    return all($sql, $args);
}

/**
 * Значения полей из формы. Возвращает data (только скалярные поля), relations и ошибки по ключам.
 * Обязательность проверяется здесь, а не только атрибутом required: POST можно отправить руками.
 *
 * @param list<array<string, mixed>> $fields
 * @param array<string, mixed> $post
 * @return array{data: array<string, string>, relations: array<string, list<string>>, errors: array<string, string>}
 */
function contentEntryInput(array $fields, array $post): array
{
    $data = [];
    $relations = [];
    $errors = [];
    $raw = is_array($post['fields'] ?? null) ? $post['fields'] : [];

    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $kind = (string) $field['kind'];
        $label = (string) $field['label'];
        $required = (int) $field['required'] === 1;

        if ($kind === CONTENT_FIELD_RELATION) {
            $ids = [];
            $rawIds = is_array($raw[$key] ?? null) ? $raw[$key] : [];

            foreach ($rawIds as $rawId) {
                if (!is_string($rawId) || $rawId === '' || in_array($rawId, $ids, true)) {
                    continue;
                }

                $target = contentEntryById($rawId);

                if ($target === null) {
                    $errors[$key] = 'Связанная запись «' . $label . '» исчезла — обновите список.';

                    continue;
                }

                $allowed = contentFieldOptions($field)['types'];

                if ($allowed !== [] && !in_array((string) $target['type_id'], $allowed, true)) {
                    $errors[$key] = 'Связанная запись «' . $label . '» другого типа.';

                    continue;
                }

                if (count($ids) >= CONTENT_RELATIONS_LIMIT) {
                    $errors[$key] = 'У поля «' . $label . '» больше ' . CONTENT_RELATIONS_LIMIT . ' связей.';

                    break;
                }

                $ids[] = $rawId;
            }

            if ($required && $ids === [] && !isset($errors[$key])) {
                $errors[$key] = 'Поле «' . $label . '» обязательно: выберите хотя бы одну запись.';
            }

            $relations[$key] = $ids;

            continue;
        }

        $value = is_string($raw[$key] ?? null) ? trim($raw[$key]) : '';

        if ($kind === CONTENT_FIELD_CHECKBOX) {
            $value = isOn($raw[$key] ?? null) ? '1' : '0';

            if ($required && $value !== '1') {
                $errors[$key] = 'Поле «' . $label . '» обязательно: поставьте отметку.';
            }

            $data[$key] = $value;

            continue;
        }

        if ($value === '') {
            if ($required) {
                $errors[$key] = 'Поле «' . $label . '» обязательно.';
            }

            $data[$key] = '';

            continue;
        }

        $limit = CONTENT_FIELD_LIMITS[$kind] ?? CONTENT_FIELD_LIMITS[CONTENT_FIELD_TEXT];

        if (mb_strlen($value) > $limit) {
            $errors[$key] = 'Поле «' . $label . '» длиннее ' . $limit . ' символов.';

            continue;
        }

        if ($kind === CONTENT_FIELD_SELECT && !in_array($value, contentFieldOptions($field)['choices'], true)) {
            $errors[$key] = 'В поле «' . $label . '» выбран вариант, которого нет в списке.';

            continue;
        }

        if ($kind === CONTENT_FIELD_NUMBER && preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/', $value) !== 1) {
            $errors[$key] = 'Поле «' . $label . '» должно быть числом.';

            continue;
        }

        if ($kind === CONTENT_FIELD_DATE) {
            // Формат даты проверяется без DateTime: core/date.php — единственное место, где ядро
            // работает с объектами времени, а здесь нужна только календарная корректность строки.
            $parts = explode('-', $value);
            $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                && count($parts) === 3
                && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);

            if (!$valid) {
                $errors[$key] = 'Поле «' . $label . '» должно быть датой в формате ГГГГ-ММ-ДД.';

                continue;
            }
        }

        if (($kind === CONTENT_FIELD_URL || $kind === CONTENT_FIELD_IMAGE) && !contentSafeHref($value, $kind === CONTENT_FIELD_IMAGE)) {
            $errors[$key] = 'Поле «' . $label . '» содержит недопустимый адрес.';

            continue;
        }

        $data[$key] = $value;
    }

    return ['data' => $data, 'relations' => $relations, 'errors' => $errors];
}

/** Ссылка для href/src: только свой путь, якорь или http(s). javascript:/data: не проходят. */
function contentSafeHref(string $value, bool $imageOnly = false): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return false;
    }

    if (str_starts_with($value, '//')) {
        return false;
    }

    if (str_starts_with($value, '/')) {
        return true;
    }

    if (!$imageOnly && str_starts_with($value, '#')) {
        return true;
    }

    return preg_match('#^https?://[^\s]+$#iu', $value) === 1;
}

/** @param array<string, string> $data */
function contentEntryDataJson(array $data): string
{
    ksort($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

/**
 * Создание записи. Адрес приходит уже проверенным планом; пустой — собирается из заголовка.
 * Права проверяются здесь же, чтобы прямой вызов не обошёл редакционный гейт.
 *
 * @param array<string, mixed> $values title, slug, seo_title, seo_description
 * @param array<string, mixed> $post поля формы
 * @param array<string, mixed> $meta user, comment, idempotency_key
 * @return array<string, mixed>
 */
function contentEntryCreate(string $typeId, array $values, array $post, ?DateTimeImmutable $now = null, array $meta = []): array
{
    $fail = static fn (string $error, array $extra = []): array => array_merge([
        'ok' => false,
        'error' => $error,
        'id' => '',
        'slug' => '',
        'notice' => '',
        'version' => 0,
        'errors' => [],
    ], $extra);

    if (!editorialCan('edit', contentMetaUser($meta))) {
        return $fail('Недостаточно прав для создания записи.');
    }

    if (!contentTablesReady()) {
        return $fail('Таблицы RELEASE 2.3 не найдены — примените обновление ядра.');
    }

    $type = contentTypeById($typeId);

    if ($type === null || $type['archived_at'] !== null) {
        return $fail('Тип записей не найден или лежит в архиве.');
    }

    $title = mb_substr(trim((string) ($values['title'] ?? '')), 0, CONTENT_TITLE_MAX);

    if (mb_strlen($title) < 2) {
        return $fail('Заголовок записи короче двух символов.');
    }

    $plan = contentEntrySlugPlan(
        (string) ($values['slug'] ?? ''),
        $title,
        static fn (string $slug): bool => contentEntrySlugTaken($typeId, $slug)
    );

    if ($plan['error'] !== '') {
        return $fail($plan['error'], ['values' => $values]);
    }

    $fields = contentTypeFields($typeId);
    $input = contentEntryInput($fields, $post);

    if ($input['errors'] !== []) {
        return $fail((string) reset($input['errors']), [
            'errors' => $input['errors'],
            'values' => $values,
            'data' => $input['data'],
            'relations' => $input['relations'],
        ]);
    }

    $id = newId();
    $moment = utcStamp($now ?? nowUtc());
    $slug = $plan['slug'];
    $seoTitle = mb_substr(trim((string) ($values['seo_title'] ?? '')), 0, CONTENT_SEO_TITLE_MAX);
    $seoDescription = mb_substr(trim((string) ($values['seo_description'] ?? '')), 0, CONTENT_SEO_DESCRIPTION_MAX);
    $userId = contentMetaUserId($meta);
    $revisionMeta = ['operation' => 'initial', 'source' => 'editor', 'comment' => 'Создание записи'];
    $revisionMeta = array_merge($revisionMeta, $meta);

    try {
        tx(static function () use ($id, $typeId, $slug, $title, $input, $seoTitle, $seoDescription, $userId, $moment, $now, $revisionMeta): void {
            insert('content_entry', [
                'id' => $id,
                'type_id' => $typeId,
                'slug' => $slug,
                'title' => $title,
                'status' => CONTENT_STATUS_DRAFT,
                'data' => contentEntryDataJson($input['data']),
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
                'created_by' => $userId !== '' ? $userId : null,
                'updated_by' => $userId !== '' ? $userId : null,
                'created_at' => $moment,
                'updated_at' => $moment,
                'published_at' => null,
            ]);

            contentEntryRelationsReplace($id, $input['relations'], $moment);
            contentRevisionCommit('entry', $id, $revisionMeta, $now);
        });
    } catch (ContentVersionReplay $replay) {
        $row = contentRevisionFetch($replay->revisionId);

        return [
            'ok' => true,
            'error' => '',
            'id' => $id,
            'slug' => $slug,
            'notice' => 'content_entry_created',
            'version' => (int) ($row['version'] ?? 0),
            'errors' => [],
            'replayed' => true,
        ];
    } catch (Throwable $error) {
        return $fail(
            duplicateReason(
                $error,
                static fn (): string => contentEntrySlugTaken($typeId, $slug) ? 'Запись с таким адресом уже есть — выберите другой.' : '',
                'Запись не создалась — повторите попытку.'
            ),
            ['values' => $values, 'errors' => $input['errors']]
        );
    }

    auditLog('content.entry_create', 'entry', $id, ['type' => $typeId, 'slug' => $slug], 'Создана запись');
    logInfo('content.entry_created', ['id' => $id, 'type' => $typeId, 'slug' => $slug]);

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'slug' => $slug,
        'notice' => 'content_entry_created',
        'version' => contentRevisionVersion('entry', $id),
        'errors' => [],
    ];
}

/**
 * Правка записи. Поля, связи, прежний адрес и ревизия пишутся одной транзакцией; expected_version
 * и idempotency_key работают как в RELEASE 2.2. Архивная запись не правится — сначала восстановите.
 *
 * @param array<string, mixed> $values title, slug, seo_title, seo_description
 * @param array<string, mixed> $post поля формы
 * @param array<string, mixed> $meta expected_version, idempotency_key, user, comment, operation
 * @return array<string, mixed>
 */
function contentEntryUpdate(string $id, array $values, array $post, ?DateTimeImmutable $now = null, array $meta = []): array
{
    $fail = static fn (string $error, array $extra = []): array => array_merge([
        'ok' => false,
        'error' => $error,
        'id' => $id,
        'slug' => '',
        'notice' => '',
        'version' => 0,
        'errors' => [],
    ], $extra);

    if (!editorialCan('edit', contentMetaUser($meta))) {
        return $fail('Недостаточно прав для правки записи.');
    }

    $entry = contentEntryById($id);

    if ($entry === null) {
        return $fail('Запись исчезла — обновите список.');
    }

    if ((string) $entry['status'] === CONTENT_STATUS_ARCHIVED) {
        return $fail('Запись лежит в архиве — сначала восстановите её.');
    }

    $type = contentTypeById((string) $entry['type_id']);

    if ($type === null || $type['archived_at'] !== null) {
        return $fail('Тип записи не найден или лежит в архиве.');
    }

    $title = mb_substr(trim((string) ($values['title'] ?? '')), 0, CONTENT_TITLE_MAX);

    if (mb_strlen($title) < 2) {
        return $fail('Заголовок записи короче двух символов.', ['values' => $values]);
    }

    $typeId = (string) $entry['type_id'];
    $plan = contentEntrySlugPlan(
        (string) ($values['slug'] ?? ''),
        $title,
        static fn (string $slug): bool => contentEntrySlugTaken($typeId, $slug, $id)
    );

    if ($plan['error'] !== '') {
        return $fail($plan['error'], ['values' => $values]);
    }

    $fields = contentTypeFields($typeId);
    $input = contentEntryInput($fields, $post);

    if ($input['errors'] !== []) {
        return $fail((string) reset($input['errors']), [
            'errors' => $input['errors'],
            'values' => $values,
            'data' => $input['data'],
            'relations' => $input['relations'],
        ]);
    }

    $slug = $plan['slug'];
    $previousSlug = (string) $entry['slug'];
    $slugChanged = $slug !== $previousSlug;
    $seoTitle = mb_substr(trim((string) ($values['seo_title'] ?? '')), 0, CONTENT_SEO_TITLE_MAX);
    $seoDescription = mb_substr(trim((string) ($values['seo_description'] ?? '')), 0, CONTENT_SEO_DESCRIPTION_MAX);
    $moment = utcStamp($now ?? nowUtc());
    $userId = contentMetaUserId($meta);
    $revisionMeta = contentRevisionMeta(array_merge(['operation' => 'manual', 'source' => 'editor'], $meta));
    contentRevisionEnsureBaseline('entry', $id, $now);

    try {
        tx(static function () use ($id, $typeId, $slug, $title, $input, $seoTitle, $seoDescription, $userId, $moment, $slugChanged, $previousSlug, $revisionMeta, $now): void {
            contentRevisionGuard('entry', $id, $revisionMeta, $now);
            run(
                'UPDATE ' . t('content_entry') . ' SET slug = ?, title = ?, data = ?, seo_title = ?, seo_description = ?,'
                . ' updated_by = ?, updated_at = ? WHERE id = ?',
                [
                    $slug,
                    $title,
                    contentEntryDataJson($input['data']),
                    $seoTitle,
                    $seoDescription,
                    $userId !== '' ? $userId : null,
                    $moment,
                    $id,
                ]
            );

            contentEntryRelationsReplace($id, $input['relations'], $moment);

            if ($slugChanged) {
                run(
                    'DELETE FROM ' . t('content_entry_old_slug') . ' WHERE type_id = ? AND slug = ?',
                    [$typeId, $previousSlug]
                );
                insert('content_entry_old_slug', [
                    'id' => newId(),
                    'type_id' => $typeId,
                    'entry_id' => $id,
                    'slug' => $previousSlug,
                    'created_at' => $moment,
                ]);
            }

            contentRevisionCommit('entry', $id, $revisionMeta, $now);
        });
    } catch (ContentVersionConflict $conflict) {
        logWarn('content.entry_conflict', ['id' => $id, 'expected' => $conflict->expectedVersion, 'actual' => $conflict->actualVersion]);

        return [
            'ok' => false,
            'error' => $conflict->getMessage(),
            'id' => $id,
            'slug' => $previousSlug,
            'notice' => '',
            'errors' => [],
            'conflict' => [
                'expected' => $conflict->expectedVersion,
                'actual' => $conflict->actualVersion,
                'revision_id' => $conflict->currentRevisionId,
            ],
            'version' => $conflict->actualVersion,
        ];
    } catch (ContentVersionReplay $replay) {
        $row = contentRevisionFetch($replay->revisionId);

        return [
            'ok' => true,
            'error' => '',
            'id' => $id,
            'slug' => $previousSlug,
            'notice' => 'content_entry_saved',
            'errors' => [],
            'replayed' => true,
            'version' => (int) ($row['version'] ?? 0),
        ];
    } catch (Throwable $error) {
        return $fail(
            duplicateReason(
                $error,
                static fn (): string => contentEntrySlugTaken($typeId, $slug, $id) ? 'Запись с таким адресом уже есть — выберите другой.' : '',
                'Запись не сохранилась — повторите попытку.'
            ),
            ['values' => $values, 'errors' => $input['errors'], 'slug' => $previousSlug]
        );
    }

    auditLog('content.entry_save', 'entry', $id, ['type' => $typeId, 'slug' => $slug], 'Сохранена запись');
    logInfo('content.entry_saved', ['id' => $id, 'type' => $typeId, 'slug' => $slug]);

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'slug' => $slug,
        'notice' => 'content_entry_saved',
        'version' => contentRevisionVersion('entry', $id),
        'errors' => [],
    ];
}

/** @param array<string, list<string>> $relations */
function contentEntryRelationsReplace(string $entryId, array $relations, string $moment): void
{
    run('DELETE FROM ' . t('content_entry_relation') . ' WHERE from_entry_id = ?', [$entryId]);

    foreach ($relations as $fieldKey => $ids) {
        $sort = 0;

        foreach ($ids as $targetId) {
            insert('content_entry_relation', [
                'id' => newId(),
                'from_entry_id' => $entryId,
                'field_key' => mb_substr($fieldKey, 0, CONTENT_FIELD_KEY_MAX),
                'to_entry_id' => $targetId,
                'sort' => $sort,
                'created_at' => $moment,
            ]);
            $sort++;
        }
    }
}

/**
 * Смена статуса записи. Публикация и архив требуют права publish, черновик — edit, расписание —
 * schedule. Смена фиксируется ревизией, поэтому история видит публикацию как событие.
 *
 * @param array<string, mixed> $meta user, expected_version, comment, system
 * @return array<string, mixed>
 */
function contentEntryStatus(string $id, string $status, ?DateTimeImmutable $now = null, array $meta = []): array
{
    $fail = static fn (string $error): array => [
        'ok' => false,
        'error' => $error,
        'id' => $id,
        'status' => '',
        'notice' => '',
        'version' => 0,
    ];

    if (!in_array($status, CONTENT_STATUSES, true)) {
        return $fail('Неизвестный статус записи.');
    }

    $entry = contentEntryById($id);

    if ($entry === null) {
        return $fail('Запись исчезла — обновите список.');
    }

    $user = contentMetaUser($meta);
    $system = ($meta['system'] ?? false) === true;
    $currentStatus = (string) $entry['status'];
    // Снятие с публикации и восстановление из архива — те же права, что публикация: редактор
    // правит черновик, но не решает, что видит посетитель.
    $right = match (true) {
        $status === CONTENT_STATUS_PUBLISHED, $status === CONTENT_STATUS_ARCHIVED => 'publish',
        $status === CONTENT_STATUS_SCHEDULED => 'schedule',
        $currentStatus === CONTENT_STATUS_PUBLISHED, $currentStatus === CONTENT_STATUS_ARCHIVED => 'publish',
        default => 'edit',
    };

    if (!$system && !editorialCan($right, $user)) {
        return $fail('Недостаточно прав для этого действия.');
    }

    if ($status === CONTENT_STATUS_PUBLISHED && $entry['status'] === CONTENT_STATUS_ARCHIVED) {
        return $fail('Запись в архиве — сначала восстановите её черновиком.');
    }

    $now ??= nowUtc();
    $moment = utcStamp($now);
    $revisionMeta = contentRevisionMeta(array_merge([
        'operation' => in_array($status, [CONTENT_STATUS_PUBLISHED], true) ? 'publish' : 'manual',
        'source' => 'editor',
        'comment' => 'Статус записи: ' . CONTENT_STATUS_LABELS[$status],
    ], $meta));
    contentRevisionEnsureBaseline('entry', $id, $now);

    try {
        tx(static function () use ($id, $status, $entry, $moment, $now, $revisionMeta): void {
            contentRevisionGuard('entry', $id, $revisionMeta, $now);
            $publishedAt = $entry['published_at'];

            if ($status === CONTENT_STATUS_PUBLISHED && $publishedAt === null) {
                $publishedAt = $moment;
            }

            run(
                'UPDATE ' . t('content_entry') . ' SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
                [$status, $publishedAt, $moment, $id]
            );
            contentRevisionCommit('entry', $id, $revisionMeta, $now);
        });
    } catch (ContentVersionConflict $conflict) {
        return [
            'ok' => false,
            'error' => $conflict->getMessage(),
            'id' => $id,
            'status' => (string) $entry['status'],
            'notice' => '',
            'version' => $conflict->actualVersion,
            'conflict' => [
                'expected' => $conflict->expectedVersion,
                'actual' => $conflict->actualVersion,
                'revision_id' => $conflict->currentRevisionId,
            ],
        ];
    } catch (ContentVersionReplay $replay) {
        $row = contentRevisionFetch($replay->revisionId);

        return [
            'ok' => true,
            'error' => '',
            'id' => $id,
            'status' => $status,
            'notice' => contentStatusNotice($status),
            'version' => (int) ($row['version'] ?? 0),
            'replayed' => true,
        ];
    } catch (Throwable $error) {
        return $fail('Статус не изменился — повторите попытку.');
    }

    auditLog(
        'content.entry_status',
        'entry',
        $id,
        ['from' => (string) $entry['status'], 'to' => $status],
        'Статус записи: ' . CONTENT_STATUS_LABELS[$status]
    );

    return [
        'ok' => true,
        'error' => '',
        'id' => $id,
        'status' => $status,
        'notice' => contentStatusNotice($status),
        'version' => contentRevisionVersion('entry', $id),
    ];
}

function contentStatusNotice(string $status): string
{
    return match ($status) {
        CONTENT_STATUS_PUBLISHED => 'content_entry_published',
        CONTENT_STATUS_ARCHIVED => 'content_entry_archived',
        CONTENT_STATUS_SCHEDULED => 'content_entry_scheduled',
        default => 'content_entry_unpublished',
    };
}

/**
 * Публикация/снятие записи из редакционного центра или планировщика. Вызывается внутри уже
 * открытой транзакции после contentRevisionGuard, поэтому сама guard не повторяет и ревизию
 * фиксирует как событие.
 *
 * @param array<string, mixed> $meta
 */
function contentEntryApplyPublication(string $key, bool $publish, array $meta = [], ?DateTimeImmutable $now = null): int
{
    $entry = contentEntryById($key);

    if ($entry === null) {
        return 0;
    }

    $moment = utcStamp($now ?? nowUtc());
    $publishedAt = $entry['published_at'];

    if ($publish && $publishedAt === null) {
        $publishedAt = $moment;
    }

    run(
        'UPDATE ' . t('content_entry') . ' SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
        [$publish ? CONTENT_STATUS_PUBLISHED : CONTENT_STATUS_DRAFT, $publishedAt, $moment, $key]
    );

    return contentRevisionCommit('entry', $key, $meta, $now)['version'];
}

/**
 * Планирование публикации записи. Задача ложится в общий tbl_scheduled_publications, статус
 * записи становится scheduled; публикует её bin/cron.php, а не просмотр страницы.
 *
 * @param array<string, mixed> $user
 * @return array<string, mixed>
 */
function contentEntrySchedule(string $id, string $whenRaw, string $timezone, array $user, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'id' => '', 'publish_at' => ''];
    $entry = contentEntryById($id);
    $type = $entry !== null ? contentTypeById((string) $entry['type_id']) : null;

    if ($entry === null || $type === null || $type['archived_at'] !== null) {
        return $fail('Запись не найдена или её тип лежит в архиве.');
    }

    if (!editorialCan('schedule', $user)) {
        return $fail('Недостаточно прав для планирования публикации.');
    }

    if ($entry['status'] === CONTENT_STATUS_ARCHIVED) {
        return $fail('Запись в архиве — сначала восстановите её черновиком.');
    }

    $now ??= nowUtc();

    try {
        /** @var array<string, mixed> $scheduled */
        $scheduled = tx(static function () use ($id, $whenRaw, $timezone, $user, $now): array {
            $created = scheduledPublicationCreate('entry', $id, 'publish', $whenRaw, $timezone, $user, $now);

            if (($created['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($created['error'] ?? 'Не удалось запланировать публикацию.'));
            }

            $status = contentEntryStatus($id, CONTENT_STATUS_SCHEDULED, $now, [
                'user' => $user,
                'operation' => 'manual',
                'comment' => 'Публикация запланирована',
            ]);

            if (($status['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($status['error'] ?? 'Не удалось изменить статус записи.'));
            }

            return $created;
        });
    } catch (Throwable $error) {
        return $fail($error->getMessage());
    }

    return ['ok' => true, 'error' => '', 'id' => (string) ($scheduled['id'] ?? ''), 'publish_at' => (string) ($scheduled['publish_at'] ?? '')];
}

/** @param array<string, mixed> $user
 *  @return array<string, mixed>
 */
function contentEntryScheduleCancel(string $scheduleId, array $user, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => ''];

    if (!contentRevisionTableExists('scheduled_publications')) {
        return ['ok' => false, 'error' => 'Планировщик ещё не установлен.'];
    }

    $row = one(
        'SELECT id, content_type, content_id, status FROM ' . t('scheduled_publications') . ' WHERE id = ? LIMIT 1',
        [$scheduleId]
    );

    if ($row === null || (string) $row['content_type'] !== 'entry' || (string) $row['status'] !== 'pending') {
        return $fail('Расписание не найдено или уже выполнено.');
    }

    $entryId = (string) $row['content_id'];

    try {
        tx(static function () use ($scheduleId, $entryId, $user, $now): void {
            $cancelled = scheduledPublicationCancel($scheduleId, $user, $now);

            if (($cancelled['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($cancelled['error'] ?? 'Расписание не отменилось.'));
            }

            $pending = one(
                'SELECT id FROM ' . t('scheduled_publications')
                . ' WHERE content_type = ? AND content_id = ? AND action = ? AND status = ? LIMIT 1',
                ['entry', $entryId, 'publish', 'pending']
            );

            if ($pending === null) {
                contentEntryStatus($entryId, CONTENT_STATUS_DRAFT, $now, [
                    'user' => $user,
                    'operation' => 'manual',
                    'comment' => 'Расписание отменено',
                ]);
            }
        });
    } catch (Throwable $error) {
        return $fail($error->getMessage());
    }

    return ['ok' => true, 'error' => ''];
}

/** @return list<array<string, mixed>> */
function contentEntrySchedules(string $entryId): array
{
    if (!contentRevisionTableExists('scheduled_publications')) {
        return [];
    }

    return all(
        'SELECT id, action, publish_at, timezone, status, attempts, last_error FROM ' . t('scheduled_publications')
        . ' WHERE content_type = ? AND content_id = ? AND status IN (?, ?) ORDER BY publish_at LIMIT 20',
        ['entry', $entryId, 'pending', 'processing']
    );
}

/** @return array<string, mixed>|null */
function contentEntryOldSlug(string $typeId, string $slug): ?array
{
    if (!contentTableExists('content_entry_old_slug')) {
        return null;
    }

    $row = one(
        'SELECT entry_id FROM ' . t('content_entry_old_slug')
        . ' WHERE type_id = ? AND slug = ? ORDER BY created_at DESC LIMIT 1',
        [$typeId, $slug]
    );

    return $row === null ? null : contentEntryById((string) $row['entry_id']);
}

/** @return array<string, mixed>|null */
function contentTypeOldSlug(string $slug): ?array
{
    if (!contentTableExists('content_type_old_slug')) {
        return null;
    }

    $row = one(
        'SELECT type_id FROM ' . t('content_type_old_slug') . ' WHERE slug = ? ORDER BY created_at DESC LIMIT 1',
        [$slug]
    );

    return $row === null ? null : contentTypeById((string) $row['type_id']);
}

/** @param array<string, mixed> $type */
function contentTypeHref(array $type): string
{
    return '/' . (string) $type['slug'];
}

/** @param array<string, mixed> $type
 *  @param array<string, mixed> $entry
 */
function contentEntryHref(array $type, array $entry): string
{
    return '/' . (string) $type['slug'] . '/' . (string) $entry['slug'];
}

/** Первый осмысленный текст записи — для карточки списка и описания SEO.
 *  @param list<array<string, mixed>> $fields
 *  @param array<string, string> $data
 */
function contentEntryExcerpt(array $fields, array $data, int $limit = 160): string
{
    foreach ($fields as $field) {
        $kind = (string) $field['kind'];

        if (!in_array($kind, [CONTENT_FIELD_TEXT, CONTENT_FIELD_TEXTAREA, CONTENT_FIELD_RICHTEXT], true)) {
            continue;
        }

        $value = trim((string) ($data[(string) $field['field_key']] ?? ''));

        if ($value !== '') {
            return excerpt($value, $limit);
        }
    }

    return '';
}

/** @return array<string, mixed>|null */
function globalBlockById(string $id): ?array
{
    if ($id === '' || !contentTableExists('global_block')) {
        return null;
    }

    return one(
        'SELECT id, slug, title, subtitle, text, image, image_alt, link, link_label, layout, active,'
        . ' created_by, updated_by, created_at, updated_at FROM ' . t('global_block') . ' WHERE id = ? LIMIT 1',
        [$id]
    );
}

/** @return array<string, mixed>|null */
function globalBlockBySlug(string $slug, bool $activeOnly = true): ?array
{
    if ($slug === '' || !contentTableExists('global_block')) {
        return null;
    }

    return one(
        'SELECT id, slug, title, subtitle, text, image, image_alt, link, link_label, layout, active,'
        . ' created_by, updated_by, created_at, updated_at FROM ' . t('global_block')
        . ' WHERE slug = ?' . ($activeOnly ? ' AND active = 1' : '') . ' LIMIT 1',
        [$slug]
    );
}

/** @return list<array<string, mixed>> */
function globalBlocksAll(bool $includeArchived = false): array
{
    if (!contentTableExists('global_block')) {
        return [];
    }

    return all(
        'SELECT id, slug, title, subtitle, text, image, image_alt, link, link_label, layout, active,'
        . ' created_by, updated_by, created_at, updated_at FROM ' . t('global_block')
        . ($includeArchived ? '' : ' WHERE active = 1')
        . ' ORDER BY title, id LIMIT ' . CONTENT_LIST_LIMIT
    );
}

/** @return array{slug: string, error: string} */
function globalBlockSlugPlan(string $typedSlug, string $title, string $excludeId = ''): array
{
    $typed = slugify($typedSlug, CONTENT_SLUG_MAX);
    $exists = static function (string $slug) use ($excludeId): bool {
        $sql = 'SELECT COUNT(*) FROM ' . t('global_block') . ' WHERE slug = ?'
            . ($excludeId !== '' ? ' AND id <> ?' : '');
        $args = $excludeId !== '' ? [$slug, $excludeId] : [$slug];

        return (int) value($sql, $args) > 0;
    };

    if ($typed !== '') {
        if (mb_strlen($typed) < CONTENT_SLUG_MIN) {
            return ['slug' => '', 'error' => 'Адрес блока короче ' . CONTENT_SLUG_MIN . ' символов.'];
        }

        if ($exists($typed)) {
            return ['slug' => '', 'error' => 'Блок с адресом «' . $typed . '» уже есть.'];
        }

        return ['slug' => $typed, 'error' => ''];
    }

    $base = slugify($title, CONTENT_SLUG_MAX);

    if ($base === '') {
        return ['slug' => '', 'error' => 'Не удалось собрать адрес блока — впишите его сами.'];
    }

    return ['slug' => ensureUniqueSlug($base, $exists, 'blok'), 'error' => ''];
}

/**
 * Создание и правка глобального блока. Адрес после создания не меняется: на него ссылаются
 * настройки секций страниц, и молчаливая смена сломала бы вставки.
 *
 * @param array<string, mixed> $values
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function globalBlockSave(?string $id, array $values, ?DateTimeImmutable $now = null, array $meta = []): array
{
    $fail = static fn (string $error, array $extra = []): array => array_merge([
        'ok' => false,
        'error' => $error,
        'id' => '',
        'slug' => '',
        'notice' => '',
    ], $extra);

    if (!grantsAppManage()) {
        return $fail('Глобальными блоками управляет только администратор.');
    }

    if (!contentTableExists('global_block')) {
        return $fail('Таблицы RELEASE 2.3 не найдены — примените обновление ядра.');
    }

    $existing = $id !== null ? globalBlockById($id) : null;

    if ($id !== null && $existing === null) {
        return $fail('Блок не найден — обновите список.');
    }

    $title = mb_substr(trim((string) ($values['title'] ?? '')), 0, GLOBAL_BLOCK_TITLE_MAX);

    if (mb_strlen($title) < 2) {
        return $fail('Заголовок блока короче двух символов.');
    }

    $slug = $existing !== null ? (string) $existing['slug'] : '';

    if ($existing === null) {
        $plan = globalBlockSlugPlan((string) ($values['slug'] ?? ''), $title);

        if ($plan['error'] !== '') {
            return $fail($plan['error']);
        }

        $slug = $plan['slug'];
    }

    $subtitle = mb_substr(trim((string) ($values['subtitle'] ?? '')), 0, GLOBAL_BLOCK_SUBTITLE_MAX);
    $text = mb_substr(trim((string) ($values['text'] ?? '')), 0, GLOBAL_BLOCK_TEXT_MAX);
    $image = mb_substr(trim((string) ($values['image'] ?? '')), 0, GLOBAL_BLOCK_IMAGE_MAX);
    $imageAlt = mb_substr(trim((string) ($values['image_alt'] ?? '')), 0, GLOBAL_BLOCK_IMAGE_ALT_MAX);
    $link = mb_substr(trim((string) ($values['link'] ?? '')), 0, GLOBAL_BLOCK_LINK_MAX);
    $linkLabel = mb_substr(trim((string) ($values['link_label'] ?? '')), 0, GLOBAL_BLOCK_LINK_LABEL_MAX);
    $layout = (string) ($values['layout'] ?? 'text');

    if ($image !== '' && !contentSafeHref($image, true)) {
        return $fail('Адрес картинки блока недопустим.');
    }

    if ($link !== '' && !contentSafeHref($link)) {
        return $fail('Ссылка блока недопустима.');
    }

    if (!array_key_exists($layout, GLOBAL_BLOCK_LAYOUTS)) {
        $layout = 'text';
    }

    $active = array_key_exists('active', $values) ? (isOn($values['active']) ? 1 : 0) : 1;
    $moment = utcStamp($now ?? nowUtc());
    $blockId = $id ?? newId();
    $userId = contentMetaUserId($meta);
    $revisionMeta = contentRevisionMeta(array_merge([
        'operation' => $existing === null ? 'initial' : 'manual',
        'source' => 'editor',
    ], $meta));

    try {
        tx(static function () use ($blockId, $existing, $slug, $title, $subtitle, $text, $image, $imageAlt, $link, $linkLabel, $layout, $active, $userId, $moment, $now, $revisionMeta): void {
            if ($existing === null) {
                insert('global_block', [
                    'id' => $blockId,
                    'slug' => $slug,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'text' => $text,
                    'image' => $image,
                    'image_alt' => $imageAlt,
                    'link' => $link,
                    'link_label' => $linkLabel,
                    'layout' => $layout,
                    'active' => $active,
                    'created_by' => $userId !== '' ? $userId : null,
                    'updated_by' => $userId !== '' ? $userId : null,
                    'created_at' => $moment,
                    'updated_at' => $moment,
                ]);
                contentRevisionCommit('block', $blockId, $revisionMeta, $now);
            } else {
                contentRevisionEnsureBaseline('block', $blockId, $now);
                contentRevisionGuard('block', $blockId, $revisionMeta, $now);
                run(
                    'UPDATE ' . t('global_block') . ' SET title = ?, subtitle = ?, text = ?, image = ?, image_alt = ?,'
                    . ' link = ?, link_label = ?, layout = ?, active = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                    [
                        $title,
                        $subtitle,
                        $text,
                        $image,
                        $imageAlt,
                        $link,
                        $linkLabel,
                        $layout,
                        $active,
                        $userId !== '' ? $userId : null,
                        $moment,
                        $blockId,
                    ]
                );
                contentRevisionCommit('block', $blockId, $revisionMeta, $now);
            }
        });
    } catch (ContentVersionConflict $conflict) {
        return [
            'ok' => false,
            'error' => $conflict->getMessage(),
            'id' => $blockId,
            'slug' => $slug,
            'notice' => '',
            'version' => $conflict->actualVersion,
            'conflict' => [
                'expected' => $conflict->expectedVersion,
                'actual' => $conflict->actualVersion,
                'revision_id' => $conflict->currentRevisionId,
            ],
        ];
    } catch (ContentVersionReplay $replay) {
        $row = contentRevisionFetch($replay->revisionId);

        return [
            'ok' => true,
            'error' => '',
            'id' => $blockId,
            'slug' => $slug,
            'notice' => 'content_block_saved',
            'replayed' => true,
            'version' => (int) ($row['version'] ?? 0),
        ];
    } catch (Throwable $error) {
        return $fail('Блок не сохранился — повторите попытку.');
    }

    auditLog(
        $existing === null ? 'content.block_create' : 'content.block_save',
        'global_block',
        $blockId,
        ['slug' => $slug],
        $existing === null ? 'Создан глобальный блок' : 'Сохранён глобальный блок'
    );

    return [
        'ok' => true,
        'error' => '',
        'id' => $blockId,
        'slug' => $slug,
        'notice' => $existing === null ? 'content_block_created' : 'content_block_saved',
        'version' => contentRevisionVersion('block', $blockId),
    ];
}

/** @return array<string, mixed> */
function globalBlockSetActive(string $id, bool $active, ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'id' => $id, 'notice' => ''];
    $block = globalBlockById($id);

    if ($block === null) {
        return $fail('Блок не найден — обновите список.');
    }

    if (!grantsAppManage()) {
        return $fail('Глобальными блоками управляет только администратор.');
    }

    $moment = utcStamp($now ?? nowUtc());
    $revisionMeta = contentRevisionMeta([
        'operation' => 'manual',
        'source' => 'editor',
        'comment' => $active ? 'Блок восстановлен' : 'Блок отправлен в архив',
    ]);

    try {
        tx(static function () use ($id, $active, $moment, $now, $revisionMeta): void {
            contentRevisionEnsureBaseline('block', $id, $now);
            contentRevisionGuard('block', $id, $revisionMeta, $now);
            run(
                'UPDATE ' . t('global_block') . ' SET active = ?, updated_at = ? WHERE id = ?',
                [$active ? 1 : 0, $moment, $id]
            );
            contentRevisionCommit('block', $id, $revisionMeta, $now);
        });
    } catch (Throwable $error) {
        return $fail('Состояние блока не изменилось — повторите попытку.');
    }

    auditLog(
        $active ? 'content.block_restore' : 'content.block_archive',
        'global_block',
        $id,
        ['slug' => (string) $block['slug']],
        $active ? 'Глобальный блок восстановлен' : 'Глобальный блок отправлен в архив'
    );

    return ['ok' => true, 'error' => '', 'id' => $id, 'notice' => $active ? 'content_block_restored' : 'content_block_archived'];
}

/** Разметка глобального блока: единственное место, где его поля превращаются в HTML.
 *  @param array<string, mixed> $block
 */
function globalBlockHtml(array $block): string
{
    if ((int) ($block['active'] ?? 0) !== 1) {
        return '';
    }

    return partial('content/global-block', ['block' => $block]);
}

/**
 * Вклад конструктора: вид global_block со слагом блока. Ядро хранит настройку как строку и не
 * проверяет её при сохранении страницы; печать проверяет блок заново.
 *
 * @param list<array<string, mixed>> $kinds
 * @return list<array<string, mixed>>
 */
function contentPagesBlockKinds(array $kinds): array
{
    return array_merge($kinds, [[
        'kind' => 'global_block',
        'name' => 'Глобальный блок',
        'fields' => [
            [
                'key' => 'block',
                'label' => 'Блок',
                'hint' => 'Слаг блока из раздела «Контент → Глобальные блоки».',
            ],
        ],
    ]]);
}

/**
 * Печать вида global_block в конструкторе страниц. Пустой, неизвестный и архивный блок не
 * печатают ничего: страница не обрастает пустой рамкой.
 *
 * @param array<string, string> $settings
 * @param array<string, string> $head
 */
function contentGlobalBlockSectionHtml(string $html, array $settings, array $head): string
{
    $slug = trim((string) ($settings['block'] ?? ''));

    if ($slug === '') {
        return $html;
    }

    $block = globalBlockBySlug($slug, true);

    return $block === null ? $html : globalBlockHtml($block);
}

/** @param list<array<string, mixed>> $urls
 *  @return list<array<string, mixed>>
 */
function contentSitemapUrls(array $urls): array
{
    if (!contentTablesReady()) {
        return $urls;
    }

    foreach (contentTypesAll() as $type) {
        $typeId = (string) $type['id'];
        $urls[] = ['loc' => contentTypeHref($type)];

        foreach (contentPublishedEntries($typeId) as $entry) {
            $urls[] = [
                'loc' => contentEntryHref($type, $entry),
                'lastmod' => (string) $entry['updated_at'],
                'priority' => 0.6,
            ];
        }
    }

    return $urls;
}

/** @param array<string, mixed> $meta
 *  @return array<string, mixed>|null
 */
function contentMetaUser(array $meta): ?array
{
    $user = $meta['user'] ?? null;

    if (is_array($user)) {
        return $user;
    }

    return currentUser();
}

/** @param array<string, mixed> $meta */
function contentMetaUserId(array $meta): string
{
    $user = contentMetaUser($meta);

    return is_array($user) ? (string) ($user['id'] ?? '') : '';
}

// ---------------------------------------------------------------------------
// Снимки и восстановление для истории RELEASE 2.2
// ---------------------------------------------------------------------------

/** @return array<string, mixed> */
function contentEntryRevisionSnapshot(string $key): array
{
    $entry = contentEntryById($key);

    if ($entry === null) {
        return ['available' => false];
    }

    $type = contentTypeById((string) $entry['type_id']);
    $relations = [];

    foreach (contentEntryRelations($key) as $fieldKey => $rows) {
        foreach ($rows as $row) {
            $relations[] = ['field_key' => $fieldKey, 'entry_id' => (string) $row['id']];
        }
    }

    return [
        'available' => true,
        'type' => 'entry',
        'id' => $key,
        'type_id' => (string) $entry['type_id'],
        'type_slug' => $type === null ? '' : (string) $type['slug'],
        'title' => (string) $entry['title'],
        'slug' => (string) $entry['slug'],
        'status' => (string) $entry['status'],
        'data' => contentEntryData($entry),
        'relations' => $relations,
        'seo_title' => (string) $entry['seo_title'],
        'seo_description' => (string) $entry['seo_description'],
        'published_at' => (string) ($entry['published_at'] ?? ''),
        'created_at' => (string) $entry['created_at'],
        'updated_at' => (string) $entry['updated_at'],
    ];
}

/** @param array<string, mixed> $payload */
function contentEntryApplyRevision(string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    $entry = contentEntryById($key);

    if ($entry === null) {
        throw new RuntimeException('Запись уже удалена — восстановить её нельзя.');
    }

    $typeId = (string) $entry['type_id'];
    $slug = trim((string) ($payload['slug'] ?? ''));

    if ($slug === '' || contentEntrySlugTaken($typeId, $slug, $key)) {
        throw new RuntimeException('Адрес записи занят другой записью — восстановление отменено.');
    }

    $data = [];
    foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $fieldKey => $value) {
        if (is_string($fieldKey) && is_scalar($value)) {
            $data[$fieldKey] = (string) $value;
        }
    }

    $relations = [];

    foreach (is_array($payload['relations'] ?? null) ? $payload['relations'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $fieldKey = (string) ($row['field_key'] ?? '');
        $entryId = (string) ($row['entry_id'] ?? '');

        if ($fieldKey !== '' && $entryId !== '') {
            $relations[$fieldKey][] = $entryId;
        }
    }

    $moment = utcStamp($now ?? nowUtc());
    $previousSlug = (string) $entry['slug'];

    if ($previousSlug !== $slug) {
        run('DELETE FROM ' . t('content_entry_old_slug') . ' WHERE type_id = ? AND slug = ?', [$typeId, $previousSlug]);
        insert('content_entry_old_slug', [
            'id' => newId(),
            'type_id' => $typeId,
            'entry_id' => $key,
            'slug' => $previousSlug,
            'created_at' => $moment,
        ]);
    }

    run(
        'UPDATE ' . t('content_entry') . ' SET slug = ?, title = ?, data = ?, seo_title = ?, seo_description = ?,'
        . ' status = ?, published_at = ?, updated_at = ? WHERE id = ?',
        [
            $slug,
            (string) ($payload['title'] ?? ''),
            contentEntryDataJson($data),
            (string) ($payload['seo_title'] ?? ''),
            (string) ($payload['seo_description'] ?? ''),
            in_array($payload['status'] ?? '', CONTENT_STATUSES, true) ? (string) $payload['status'] : CONTENT_STATUS_DRAFT,
            (string) ($payload['published_at'] ?? '') !== '' ? (string) $payload['published_at'] : null,
            $moment,
            $key,
        ]
    );
    contentEntryRelationsReplace($key, $relations, $moment);
}

/** @return array<string, mixed> */
function contentBlockRevisionSnapshot(string $key): array
{
    $block = globalBlockById($key);

    if ($block === null) {
        return ['available' => false];
    }

    unset($block['updated_at']);

    return ['available' => true, 'type' => 'block'] + $block;
}

/** @param array<string, mixed> $payload */
function contentBlockApplyRevision(string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    if (globalBlockById($key) === null) {
        throw new RuntimeException('Блок уже удалён — восстановить его нельзя.');
    }

    $layout = (string) ($payload['layout'] ?? 'text');
    $moment = utcStamp($now ?? nowUtc());
    run(
        'UPDATE ' . t('global_block') . ' SET title = ?, subtitle = ?, text = ?, image = ?, image_alt = ?,'
        . ' link = ?, link_label = ?, layout = ?, active = ?, updated_at = ? WHERE id = ?',
        [
            (string) ($payload['title'] ?? ''),
            (string) ($payload['subtitle'] ?? ''),
            (string) ($payload['text'] ?? ''),
            (string) ($payload['image'] ?? ''),
            (string) ($payload['image_alt'] ?? ''),
            (string) ($payload['link'] ?? ''),
            (string) ($payload['link_label'] ?? ''),
            array_key_exists($layout, GLOBAL_BLOCK_LAYOUTS) ? $layout : 'text',
            (int) ($payload['active'] ?? 1) === 1 ? 1 : 0,
            $moment,
            $key,
        ]
    );
}

// ---------------------------------------------------------------------------
// Подключение к истории и редакционному центру RELEASE 2.2
// ---------------------------------------------------------------------------

/**
 * Новые сущности входят в историю теми же типами, что страницы и новости. Снимок и применение
 * ревизии ядро зовёт фильтром и действием, поэтому таблицы ядра не знают о полях RELEASE 2.3.
 *
 * @param list<string> $types
 * @return list<string>
 */
function contentRevisionTypesFilter(array $types): array
{
    return array_values(array_unique(array_merge($types, ['entry', 'block'])));
}

/** @param mixed $payload */
function contentRevisionPayloadFilter(mixed $payload, string $type, string $key): mixed
{
    if ($type === 'entry') {
        return contentEntryRevisionSnapshot($key);
    }

    if ($type === 'block') {
        return contentBlockRevisionSnapshot($key);
    }

    return $payload;
}

/** @param array<string, mixed> $payload */
function contentRevisionApplyHook(string $type, string $key, array $payload): void
{
    if ($type === 'entry') {
        contentEntryApplyRevision($key, $payload);

        return;
    }

    if ($type === 'block') {
        contentBlockApplyRevision($key, $payload);
    }
}

/**
 * Редакционный центр: существование, заголовок, адрес и подпись новых типов. Фильтры-запасные
 * ветки ядра вызываются только для типов, которых ядро не знает.
 */
function contentEditorialExistsFilter(mixed $exists, string $type, string $key): mixed
{
    return $type === 'entry' ? contentEntryById($key) !== null : $exists;
}

function contentEditorialTitleFilter(mixed $title, string $type, string $key): mixed
{
    if ($type !== 'entry') {
        return $title;
    }

    $entry = contentEntryById($key);

    return $entry === null ? 'Запись' : (string) $entry['title'];
}

function contentEditorialUrlFilter(mixed $url, string $type, string $key): mixed
{
    return $type === 'entry' ? '/admin/content/entries/' . rawurlencode($key) : $url;
}

function contentEditorialLabelFilter(mixed $label, string $type): mixed
{
    return $type === 'entry' ? 'Запись' : $label;
}

/**
 * Публикация и снятие записи из редакционного центра. Ядро уже открыло транзакцию и проверило
 * версию, поэтому запись меняет строку и фиксирует ревизию события.
 *
 * @param array<string, mixed> $meta
 */
function contentEditorialPublicationFilter(mixed $version, string $type, string $key, bool $publish, array $meta, ?DateTimeImmutable $now): mixed
{
    return $type === 'entry' ? contentEntryApplyPublication($key, $publish, $meta, $now) : $version;
}

/** Группы сквозного поиска редакции: записи и глобальные блоки.
 *  @param array{groups: list<array{title: string, items: list<array{title: string, subtitle: string, href: string, date: string}>}>, total: int, query: string} $result
 *  @return array{groups: list<array{title: string, items: list<array{title: string, subtitle: string, href: string, date: string}>}>, total: int, query: string}
 */
function contentSearchGroupsFilter(array $result, string $query, string $like, string $escape): array
{
    if (!contentTablesReady()) {
        return $result;
    }

    $entries = all(
        'SELECT e.id, e.slug, e.title, e.status, e.updated_at, ty.slug AS type_slug, ty.name AS type_name'
        . ' FROM ' . t('content_entry') . ' e'
        . ' JOIN ' . t('content_type') . ' ty ON ty.id = e.type_id'
        . ' WHERE e.title LIKE ? ESCAPE ? OR e.slug LIKE ? ESCAPE ?'
        . ' ORDER BY e.updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
        [$like, $escape, $like, $escape]
    );
    $items = [];

    foreach ($entries as $row) {
        $items[] = [
            'title' => (string) $row['title'],
            'subtitle' => '/' . (string) $row['type_slug'] . '/' . (string) $row['slug']
                . ' · ' . (CONTENT_STATUS_LABELS[(string) $row['status']] ?? (string) $row['status']),
            'href' => '/admin/content/entries/' . (string) $row['id'],
            'date' => (string) $row['updated_at'],
        ];
    }

    searchAddGroup($result, 'Записи', $items);

    $blocks = all(
        'SELECT id, slug, title, active, updated_at FROM ' . t('global_block')
        . ' WHERE title LIKE ? ESCAPE ? OR slug LIKE ? ESCAPE ?'
        . ' ORDER BY updated_at DESC LIMIT ' . SEARCH_PER_SOURCE,
        [$like, $escape, $like, $escape]
    );
    $items = [];

    foreach ($blocks as $row) {
        $items[] = [
            'title' => (string) $row['title'],
            'subtitle' => 'Блок /' . (string) $row['slug'] . ' · ' . ((int) $row['active'] === 1 ? 'активен' : 'архив'),
            'href' => '/admin/content/blocks/' . (string) $row['id'],
            'date' => (string) $row['updated_at'],
        ];
    }

    searchAddGroup($result, 'Глобальные блоки', $items);

    return $result;
}

/**
 * Подписки ядра на точки расширения. Вынесено в функцию не ради красоты: тесты вызывают
 * hooksReset(), чтобы проверить свои подписки, и после этого типы entry/block перестают
 * существовать для ревизий. Функцию можно вызвать повторно — она не дублирует живые подписки.
 */
function contentHooksRegister(): void
{
    $registry = &hookRegistry();

    foreach ($registry as $hooks) {
        foreach ($hooks as $hook) {
            if (($hook['fn'] ?? null) === 'contentRevisionTypesFilter') {
                return;
            }
        }
    }

    addFilter('content_revision_types', 'contentRevisionTypesFilter');
    addFilter('content_revision_payload', 'contentRevisionPayloadFilter');
    addAction('content_revision_apply', 'contentRevisionApplyHook');
    addFilter('editorial_content_exists', 'contentEditorialExistsFilter');
    addFilter('editorial_content_title', 'contentEditorialTitleFilter');
    addFilter('editorial_content_url', 'contentEditorialUrlFilter');
    addFilter('editorial_type_label', 'contentEditorialLabelFilter');
    addFilter('editorial_apply_publication', 'contentEditorialPublicationFilter');
    addFilter('search_groups', 'contentSearchGroupsFilter');
    addFilter('pages_block_kinds', 'contentPagesBlockKinds');
    addFilter('pages_block_global_block', 'contentGlobalBlockSectionHtml');
    addFilter('sitemap_urls', 'contentSitemapUrls');
}

contentHooksRegister();
