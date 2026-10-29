<?php

declare(strict_types=1);

// Разметка Schema.org раздела «SEO и статистика» (S3). Администратор заводит набор объектов
// по адресу страницы, а раздел печатает их в голове страницы одним скриптом JSON-LD.
//
// Правило честности: печатается ровно то, что заведено, — без догадок о содержимом страницы.
// Схема, которой нет в базе, на странице не появляется; набор типов ограничен теми, чьи
// поля мы правда умеем заполнить.

const SEO_SCHEMA_TYPES = [
    'WebPage' => 'Страница сайта',
    'Article' => 'Статья',
    'NewsArticle' => 'Новостная статья',
    'Organization' => 'Организация',
];

/**
 * Поля по типам. У организации картинка — это логотип, а у статьи — автор и даты: одна
 * форма на все типы означала бы пустые поля в разметке.
 */
const SEO_SCHEMA_FIELDS = [
    'WebPage' => ['name', 'description', 'image'],
    'Article' => ['name', 'description', 'image', 'author', 'date_published', 'date_modified'],
    'NewsArticle' => ['name', 'description', 'image', 'author', 'date_published', 'date_modified'],
    'Organization' => ['name', 'description', 'image'],
];

const SEO_SCHEMA_NAME_MAX = 190;
const SEO_SCHEMA_DESCRIPTION_MAX = 500;

/**
 * @return list<array<string, mixed>>
 */
function seoSchemaList(): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = all(
        'SELECT id, path, schema_type, name, description, image, author, date_published, date_modified,'
        . ' created_at, updated_at FROM ' . t('seo_schema') . ' ORDER BY path'
    );

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function seoSchemaByPath(string $path): ?array
{
    $clean = seoMetaPath($path);

    if ($clean === '') {
        return null;
    }

    return one(
        'SELECT id, path, schema_type, name, description, image, author, date_published, date_modified'
        . ' FROM ' . t('seo_schema') . ' WHERE path = ?',
        [$clean]
    );
}

/**
 * @return array<string, mixed>|null
 */
function seoSchemaById(string $id): ?array
{
    $clean = mb_substr(trim($id), 0, 26);

    if ($clean === '') {
        return null;
    }

    return one(
        'SELECT id, path, schema_type, name, description, image, author, date_published, date_modified'
        . ' FROM ' . t('seo_schema') . ' WHERE id = ?',
        [$clean]
    );
}

/**
 * Картинка приводится к пути от корня: полный адрес нашего домена — это тот же самый путь,
 * а хранить его в разметке незачем — в разметку картинка печатается полным адресом сама.
 */
function seoSchemaImagePath(string $image): string
{
    $clean = trim($image);

    if ($clean === '' || str_starts_with($clean, '/')) {
        return $clean;
    }

    $base = rtrim((string) configValue('SITE_URL', ''), '/');

    if ($base !== '' && str_starts_with($clean, $base)) {
        $clean = substr($clean, strlen($base));
    }

    return seoMetaPath($clean);
}

/**
 * Проверка формы разметки.
 *
 * @param array<string, mixed> $post
 * @return array{errors: array<string, string>, values: array{path: string, schema_type: string, name: string, description: string, image: string, author: string, date_published: string, date_modified: string}}
 */
function seoSchemaValidate(array $post): array
{
    $errors = [];
    $rawPath = trim((string) ($post['path'] ?? ''));
    $path = seoMetaPath($rawPath);
    $type = trim((string) ($post['schema_type'] ?? ''));
    $name = trim((string) ($post['name'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));
    $image = trim((string) ($post['image'] ?? ''));
    $author = trim((string) ($post['author'] ?? ''));
    $datePublished = trim((string) ($post['date_published'] ?? ''));
    $dateModified = trim((string) ($post['date_modified'] ?? ''));

    if ($rawPath === '') {
        $errors['path'] = 'Укажите адрес страницы — разметка печатается по адресу.';
    } elseif (!str_starts_with($rawPath, '/')) {
        $errors['path'] = 'Адрес начинается с «/» — так он выглядит в строке браузера.';
    } elseif (str_contains($rawPath, '?') || str_contains($rawPath, '#')) {
        $errors['path'] = 'Адрес указывается без параметров и якоря.';
    } elseif (preg_match('/\s/u', $rawPath) === 1) {
        $errors['path'] = 'В адресе не должно быть пробелов.';
    } elseif (mb_strlen($path) > SEO_META_PATH_MAX) {
        $errors['path'] = 'Адрес длиннее ' . SEO_META_PATH_MAX . ' знаков.';
    } elseif (isRobotsDisallowed($path)) {
        $errors['path'] = 'Служебный адрес в разметку не попадает.';
    }

    if (!isset(SEO_SCHEMA_TYPES[$type])) {
        $errors['schema_type'] = 'Выберите тип объекта из списка.';
    }

    if ($name === '') {
        $errors['name'] = 'Название обязательно: без него объект в разметке бессмыслен.';
    } elseif (mb_strlen($name) > SEO_SCHEMA_NAME_MAX) {
        $errors['name'] = 'Название длиннее ' . SEO_SCHEMA_NAME_MAX . ' знаков.';
    }

    if (mb_strlen($description) > SEO_SCHEMA_DESCRIPTION_MAX) {
        $errors['description'] = 'Описание длиннее ' . SEO_SCHEMA_DESCRIPTION_MAX . ' знаков.';
    }

    if ($image !== '') {
        if (!seoMetaIsOurAddress($image)) {
            $errors['image'] = 'Картинка должна лежать на нашем сайте: путь от корня или полный адрес нашего домена.';
        } else {
            $image = seoSchemaImagePath($image);
        }
    }

    if (mb_strlen($author) > SEO_SCHEMA_NAME_MAX) {
        $errors['author'] = 'Автор длиннее ' . SEO_SCHEMA_NAME_MAX . ' знаков.';
    }

    foreach (['date_published' => $datePublished, 'date_modified' => $dateModified] as $field => $value) {
        if ($value === '') {
            continue;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) !== 1
            || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])
        ) {
            $errors[$field] = 'Дата — в виде ГГГГ-ММ-ДД, и такой даты в календаре нет.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'path' => $path,
            'schema_type' => $type,
            'name' => $name,
            'description' => $description,
            'image' => $image,
            'author' => $author,
            'date_published' => $datePublished,
            'date_modified' => $dateModified,
        ],
    ];
}

/**
 * Сохранение разметки: адрес уникален, поэтому вторая запись на тот же адрес обновляет
 * первую, а не заводит вторую разметку одной страницы.
 *
 * @param array{path: string, schema_type: string, name: string, description: string, image: string, author: string, date_published: string, date_modified: string} $values
 * @return array{ok: bool, error: string, notice: string}
 */
function seoSchemaSave(array $values): array
{
    $existing = seoSchemaByPath((string) $values['path']);
    $stamp = utcStamp(nowUtc());

    $row = [
        'schema_type' => (string) $values['schema_type'],
        'name' => (string) $values['name'],
        'description' => $values['description'] !== '' ? $values['description'] : null,
        'image' => $values['image'] !== '' ? $values['image'] : null,
        'author' => $values['author'] !== '' ? $values['author'] : null,
        'date_published' => $values['date_published'] !== '' ? $values['date_published'] : null,
        'date_modified' => $values['date_modified'] !== '' ? $values['date_modified'] : null,
        'updated_at' => $stamp,
    ];

    if ($existing !== null) {
        seoSchemaRowUpdate($existing, $row);

        return ['ok' => true, 'error' => '', 'notice' => 'schema_saved'];
    }

    // Select-then-insert гонится в отказ двумя одновременными сохранениями: оба прочли «нет
    // строки», и проигравший INSERT получает отказ уникального ключа. Семантика экрана —
    // «вторая запись на тот же адрес обновляет первую», поэтому проигравший гонку дообновляет
    // строку победителя; если строку меж тем удалили — честная причина, а не 500.
    try {
        insert('seo_schema', array_merge($row, [
            'id' => newId(),
            'path' => (string) $values['path'],
            'created_at' => $stamp,
        ]));
    } catch (Throwable $insertError) {
        if (!duplicateKeyException($insertError)) {
            throw $insertError;
        }

        $raced = seoSchemaByPath((string) $values['path']);

        if ($raced === null) {
            return ['ok' => false, 'error' => 'Разметку на этот адрес только что правят — обновите список и повторите.', 'notice' => ''];
        }

        seoSchemaRowUpdate($raced, $row);
    }

    return ['ok' => true, 'error' => '', 'notice' => 'schema_saved'];
}

/**
 * Обновление строки разметки значениями формы: одна рука для спокойной правки и для
 * дообновления после проигранной гонки вставки.
 *
 * @param array<string, mixed> $existing
 * @param array{schema_type: string, name: string, description: ?string, image: ?string, author: ?string, date_published: ?string, date_modified: ?string, updated_at: string} $row
 */
function seoSchemaRowUpdate(array $existing, array $row): void
{
    run(
        'UPDATE ' . t('seo_schema') . ' SET schema_type = ?, name = ?, description = ?, image = ?,'
        . ' author = ?, date_published = ?, date_modified = ?, updated_at = ? WHERE id = ?',
        [
            (string) $row['schema_type'],
            (string) $row['name'],
            $row['description'],
            $row['image'],
            $row['author'],
            $row['date_published'],
            $row['date_modified'],
            (string) $row['updated_at'],
            (string) $existing['id'],
        ]
    );
}

/**
 * @return array{ok: bool, error: string, notice: string}
 */
function seoSchemaDelete(string $id): array
{
    $row = seoSchemaById($id);

    if ($row === null) {
        return ['ok' => false, 'error' => 'Такой записи уже нет — обновите список.', 'notice' => ''];
    }

    run('DELETE FROM ' . t('seo_schema') . ' WHERE id = ?', [(string) $row['id']]);

    return ['ok' => true, 'error' => '', 'notice' => 'schema_deleted'];
}

/**
 * Объект разметки: ровно те поля, которые заведены, и никаких догадок.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function seoSchemaItem(array $row): array
{
    $type = (string) ($row['schema_type'] ?? '');
    $fields = SEO_SCHEMA_FIELDS[$type] ?? ['name', 'description'];
    $item = [
        '@context' => 'https://schema.org',
        '@type' => $type,
    ];

    // Набор полей типа — срез: у статьи есть автор и даты, у организации их нет. Проверка идёт
    // через замыкание, потому что поле ищется по имени, а не по позиции в списке.
    $has = static fn (string $field): bool => in_array($field, $fields, true);

    if ($has('name')) {
        $item['name'] = (string) ($row['name'] ?? '');
    }

    if ($has('description')) {
        $description = trim((string) ($row['description'] ?? ''));

        if ($description !== '') {
            $item['description'] = $description;
        }
    }

    if ($has('image')) {
        $image = trim((string) ($row['image'] ?? ''));

        if ($image !== '') {
            // У организации картинка — это логотип: тот же файл, другое поле схемы.
            $item[$type === 'Organization' ? 'logo' : 'image'] = seoMetaAbsoluteAddress($image);
        }
    }

    if ($has('author')) {
        $author = trim((string) ($row['author'] ?? ''));

        if ($author !== '') {
            $item['author'] = ['@type' => 'Person', 'name' => $author];
        }
    }

    if ($has('date_published')) {
        $date = trim((string) ($row['date_published'] ?? ''));

        if ($date !== '') {
            $item['datePublished'] = $date;
        }
    }

    if ($has('date_modified')) {
        $date = trim((string) ($row['date_modified'] ?? ''));

        if ($date !== '') {
            $item['dateModified'] = $date;
        }
    }

    $item['url'] = absoluteUrl(seoMetaPath((string) ($row['path'] ?? '')));

    return $item;
}

/**
 * Предпросмотр разметки: тот же JSON, который уйдёт в страницу, только читаемый человеком.
 *
 * @param array<string, mixed> $row
 */
function seoSchemaPreview(array $row): string
{
    $json = json_encode(
        seoSchemaItem($row),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP
    );

    return is_string($json) ? $json : '';
}

/**
 * Фильтр page_meta: разметка печатается в голове той самой страницы, адрес которой задан.
 * На других страницах разметки нет — печатать чужую схему на чужой странице было бы враньём.
 *
 * @param array<string, mixed> $meta
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function seoSchemaPageMeta(array $meta, string $template = '', array $data = []): array
{
    if (!isAppActive('seo')) {
        return $meta;
    }

    $row = seoSchemaByPath(requestPath());

    if ($row === null) {
        return $meta;
    }

    $script = jsonLdScript(seoSchemaItem($row));

    if ($script === '') {
        return $meta;
    }

    $meta['head'] = (string) ($meta['head'] ?? '') . "\n" . $script;

    return $meta;
}
