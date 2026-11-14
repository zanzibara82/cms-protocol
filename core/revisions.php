<?php

declare(strict_types=1);

// RELEASE 2.2: история версий контента. Ревизия — полный снимок материала, а не только текст:
// страница сохраняет поля, все секции, их порядок, настройки и SEO-представление; новость —
// поля, абзацы, теги и автора; товар — поля, рубрики и атрибуты.
//
// Запись устроена так: вызывающий уже внутри tx(), сначала зовёт contentRevisionGuard() до
// изменения материала, затем меняет материал и зовёт contentRevisionCommit(). Guard блокирует
// строку головы (SELECT ... FOR UPDATE) и проверяет ожидаемую версию; Commit пишет снимок и
// двигает голову. Любая ошибка откатывает и материал, и ревизию — расхождения не остаётся.
//
// Если таблиц редакции ещё нет (миграция не применена), запись материала не ломается: снимок
// пропускается с записью в журнал ошибок. Так обновление кода не блокирует работу старой базы.

const CONTENT_REVISION_OPERATIONS = ['initial', 'manual', 'autosave', 'publish', 'restore', 'bulk', 'system'];

const CONTENT_REVISION_OPERATION_LABELS = [
    'initial' => 'Первоначальное сохранение',
    'manual' => 'Ручное сохранение',
    'autosave' => 'Автосохранение',
    'publish' => 'Публикация',
    'restore' => 'Восстановление',
    'bulk' => 'Массовое изменение',
    'system' => 'Системное изменение',
];

const CONTENT_REVISION_SOURCES = ['editor', 'api', 'autosave', 'scheduler', 'import', 'system'];

const CONTENT_REVISION_PAGE_SIZE = 25;

const CONTENT_REVISION_DEFAULT_LIMIT = 100;
const CONTENT_REVISION_DEFAULT_DAYS = 365;

/** Конфликт версий: материал изменился после того, как редактор получил свою версию. */
final class ContentVersionConflict extends RuntimeException
{
    public function __construct(
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
        public readonly string $currentRevisionId = '',
        public readonly string $contentType = '',
        public readonly string $contentId = ''
    ) {
        parent::__construct('Материал был изменён другим пользователем. Необходимо сравнить версии перед сохранением.');
    }
}

/** Повтор уже выполненной операции: ответ возвращается без второй записи и второй ревизии. */
final class ContentVersionReplay extends RuntimeException
{
    public function __construct(public readonly string $revisionId)
    {
        parent::__construct('Операция уже выполнена.');
    }
}

/** @return list<string> */
function contentRevisionTypes(): array
{
    $types = applyFilters('content_revision_types', ['page', 'news', 'product']);

    return is_array($types) ? array_values(array_filter($types, 'is_string')) : ['page', 'news', 'product'];
}

function contentRevisionTypeValid(string $type): bool
{
    return in_array($type, contentRevisionTypes(), true);
}

/** Главная страница живёт без строки tbl_page: её ключ в истории — home. */
function contentRevisionKeyId(string $type, string $id): string
{
    $id = trim($id);

    return $type === 'page' && $id === PAGES_HOME_ID ? 'home' : $id;
}

function contentRevisionTablesReady(): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        one('SELECT content_type FROM ' . t('content_heads') . ' LIMIT 1');
        $ready = true;
    } catch (Throwable $error) {
        logWarn('revisions.tables_missing', ['message' => $error->getMessage()]);
        $ready = false;
    }

    return $ready;
}

function contentRevisionTableExists(string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        one('SELECT 1 FROM ' . t($table) . ' LIMIT 1');
        $cache[$table] = true;
    } catch (Throwable $error) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

/** @param array<string, mixed>|null $user
 *  @return array<string, string>
 */
function contentRevisionAuthor(?array $user = null): array
{
    $user ??= currentUser();

    if (!is_array($user)) {
        return ['id' => '', 'email' => ''];
    }

    return [
        'id' => (string) ($user['id'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
    ];
}

/**
 * Нормализация метаданных операции. Старые вызовы без метаданных получают ручное сохранение.
 *
 * @param array<string, mixed> $meta
 * @param array<string, mixed>|null $user
 * @return array{operation: string, source: string, comment: string, idempotency_key: string, expected_version: ?int, author_id: string, author_email: string}
 */
function contentRevisionMeta(array $meta = [], ?array $user = null): array
{
    $author = contentRevisionAuthor($user ?? (is_array($meta['author'] ?? null) ? $meta['author'] : null));
    $operation = (string) ($meta['operation'] ?? 'manual');
    $source = (string) ($meta['source'] ?? 'editor');
    $expected = $meta['expected_version'] ?? null;

    if (!in_array($operation, CONTENT_REVISION_OPERATIONS, true)) {
        $operation = 'manual';
    }

    if (!in_array($source, CONTENT_REVISION_SOURCES, true)) {
        $source = 'editor';
    }

    return [
        'operation' => $operation,
        'source' => $source,
        'comment' => mb_substr(trim((string) ($meta['comment'] ?? '')), 0, 500),
        'idempotency_key' => mb_substr(trim((string) ($meta['idempotency_key'] ?? '')), 0, 80),
        'expected_version' => is_int($expected) || (is_string($expected) && $expected !== '' && ctype_digit($expected))
            ? (int) $expected
            : null,
        'author_id' => (string) ($meta['author_id'] ?? $author['id']),
        'author_email' => (string) ($meta['author_email'] ?? $author['email']),
    ];
}

/** @return array<string, mixed> */
function contentRevisionHead(string $type, string $id): array
{
    $key = contentRevisionKeyId($type, $id);
    $row = one(
        'SELECT content_type, content_id, version, revision_id, updated_by, updated_at FROM ' . t('content_heads')
        . ' WHERE content_type = ? AND content_id = ? LIMIT 1',
        [$type, $key]
    );

    return $row ?? [
        'content_type' => $type,
        'content_id' => $key,
        'version' => 0,
        'revision_id' => null,
        'updated_by' => null,
        'updated_at' => '',
    ];
}

/** Версия для формы и API: без головы материал считается нулевой версией. */
function contentRevisionVersion(string $type, string $id): int
{
    if (!contentRevisionTableExists('content_heads')) {
        return 0;
    }

    return (int) contentRevisionHead($type, $id)['version'];
}

/** @return array{content: array<string, mixed>, available: bool} */
function contentRevisionSnapshot(string $type, string $id): array
{
    $key = contentRevisionKeyId($type, $id);

    $payload = match ($type) {
        'page' => contentRevisionPageSnapshot($key),
        'news' => contentRevisionNewsSnapshot($key),
        'product' => contentRevisionProductSnapshot($key),
        default => applyFilters('content_revision_payload', null, $type, $key),
    };

    if (!is_array($payload) || ($payload['available'] ?? true) !== true) {
        return ['content' => [], 'available' => false];
    }

    unset($payload['available']);

    return ['content' => $payload, 'available' => true];
}

/** @return array<string, mixed> */
function contentRevisionPageSnapshot(string $key): array
{
    if ($key !== 'home' && !contentRevisionTableExists('page')) {
        return ['available' => false];
    }

    $page = $key === 'home' ? null : pageById($key);

    if ($key !== 'home' && $page === null) {
        return ['available' => false];
    }

    $sections = [];
    $rows = all(
        'SELECT id, sec_key, kind, layout, anchor, badge, title, lead_text, note, image, image_alt,'
        . ' buttons, items, settings, sort, visible, updated_at FROM ' . t('page_section')
        . ' WHERE page_id = ? ORDER BY sort, id LIMIT ' . PAGES_SECTIONS_LIMIT,
        [$key === 'home' ? PAGES_HOME_ID : $key]
    );

    foreach ($rows as $row) {
        $sections[] = pagesDecodeRow($row);
    }

    $title = $page === null ? 'Главная' : (string) $page['title'];
    $description = $page === null ? '' : (string) $page['description'];
    $slug = $page === null ? '' : (string) $page['slug'];
    $status = $page === null ? PAGES_STATUS_PUBLISHED : (string) $page['status'];
    $noindex = $page === null ? 0 : (int) $page['noindex'];

    return [
        'available' => true,
        'type' => 'page',
        'id' => $key,
        'is_home' => $key === 'home',
        'title' => $title,
        'slug' => $slug,
        'description' => $description,
        'status' => $status,
        'noindex' => $noindex,
        'created_at' => $page === null ? '' : (string) $page['created_at'],
        'updated_at' => $page === null ? '' : (string) $page['updated_at'],
        'sections' => $sections,
        'seo' => [
            'title' => $title,
            'description' => $description,
            'canonical' => '',
            'robots' => $noindex === 1 ? 'noindex, nofollow' : 'index, follow',
            'url' => $slug === '' ? '/' : '/' . $slug,
            'schema' => [],
            'open_graph' => [],
            'images' => contentRevisionPageImages($sections),
        ],
    ];
}

/** @param list<array<string, mixed>> $sections
 *  @return list<string>
 */
function contentRevisionPageImages(array $sections): array
{
    $images = [];

    foreach ($sections as $section) {
        $image = trim((string) ($section['image'] ?? ''));

        if ($image !== '') {
            $images[] = $image;
        }
    }

    return array_values(array_unique($images));
}

/** @return array<string, mixed> */
function contentRevisionNewsSnapshot(string $key): array
{
    if (!contentRevisionTableExists('news_item')) {
        return ['available' => false];
    }

    $row = one(
        'SELECT id, slug, title, lead_text, cover, category_id, body, status, published_at, pinned,'
        . ' seo_title, seo_description, og_image, noindex, author_id, author_email, created_at, updated_at'
        . ' FROM ' . t('news_item') . ' WHERE id = ? LIMIT 1',
        [$key]
    );

    if ($row === null) {
        return ['available' => false];
    }

    $body = json_decode((string) ($row['body'] ?? '[]'), true);
    $tags = [];
    $category = null;

    if (contentRevisionTableExists('news_item_tag') && contentRevisionTableExists('news_tag')) {
        $tags = all(
            'SELECT tg.id, tg.slug, tg.label FROM ' . t('news_item_tag') . ' it'
            . ' JOIN ' . t('news_tag') . ' tg ON tg.id = it.tag_id'
            . ' WHERE it.item_id = ? ORDER BY tg.label, tg.id LIMIT 100',
            [$key]
        );
    }

    if (contentRevisionTableExists('news_category')) {
        $category = one(
            'SELECT id, slug, title FROM ' . t('news_category') . ' WHERE id = ? LIMIT 1',
            [(string) $row['category_id']]
        );
    }

    return [
        'available' => true,
        'type' => 'news',
        'id' => $key,
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'lead_text' => (string) ($row['lead_text'] ?? ''),
        'cover' => (string) ($row['cover'] ?? ''),
        'category_id' => (string) $row['category_id'],
        'category' => $category,
        'body' => is_array($body) ? $body : [],
        'status' => (string) $row['status'],
        'published_at' => $row['published_at'] === null ? '' : (string) $row['published_at'],
        'pinned' => (int) $row['pinned'],
        'seo_title' => (string) ($row['seo_title'] ?? ''),
        'seo_description' => (string) ($row['seo_description'] ?? ''),
        'og_image' => (string) ($row['og_image'] ?? ''),
        'noindex' => (int) $row['noindex'],
        'author_id' => (string) ($row['author_id'] ?? ''),
        'author_email' => (string) ($row['author_email'] ?? ''),
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'tags' => $tags,
        'seo' => [
            'title' => (string) ($row['seo_title'] ?? ''),
            'description' => (string) ($row['seo_description'] ?? ''),
            'canonical' => '',
            'robots' => (int) $row['noindex'] === 1 ? 'noindex, nofollow' : 'index, follow',
            'url' => '/news/' . (string) $row['slug'],
            'schema' => [],
            'open_graph' => ['image' => (string) ($row['og_image'] ?? '')],
            'images' => array_values(array_filter([(string) ($row['cover'] ?? ''), (string) ($row['og_image'] ?? '')])),
        ],
    ];
}

/** @return array<string, mixed> */
function contentRevisionProductSnapshot(string $key): array
{
    if (!contentRevisionTableExists('commerce_product') || !function_exists('commerceProductById')) {
        return ['available' => false];
    }

    $row = commerceProductById($key);

    if ($row === null) {
        return ['available' => false];
    }

    $categories = contentRevisionTableExists('commerce_product_category') && function_exists('commerceProductCategories')
        ? commerceProductCategories($key)
        : [];
    $attributes = function_exists('commerceProductAttributes') ? commerceProductAttributes($key) : [];
    $body = json_decode((string) ($row['body'] ?? '[]'), true);

    return [
        'available' => true,
        'type' => 'product',
        'id' => $key,
        'slug' => (string) $row['slug'],
        'sku' => (string) $row['sku'],
        'title' => (string) $row['title'],
        'lead_text' => (string) ($row['lead_text'] ?? ''),
        'body' => is_array($body) ? $body : [],
        'brand' => (string) ($row['brand'] ?? ''),
        'barcode' => (string) ($row['barcode'] ?? ''),
        'price_retail' => (float) $row['price_retail'],
        'price_promo' => $row['price_promo'] === null ? null : (float) $row['price_promo'],
        'price_cost' => $row['price_cost'] === null ? null : (float) $row['price_cost'],
        'currency' => (string) $row['currency'],
        'stock' => (int) $row['stock'],
        'status' => (string) $row['status'],
        'seo_title' => (string) ($row['seo_title'] ?? ''),
        'seo_description' => (string) ($row['seo_description'] ?? ''),
        'noindex' => (int) $row['noindex'],
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'categories' => $categories,
        'attributes' => $attributes,
        'seo' => [
            'title' => (string) ($row['seo_title'] ?? ''),
            'description' => (string) ($row['seo_description'] ?? ''),
            'canonical' => '',
            'robots' => (int) $row['noindex'] === 1 ? 'noindex, nofollow' : 'index, follow',
            'url' => '/shop/' . (string) $row['slug'],
            'schema' => [],
            'open_graph' => [],
            'images' => [],
        ],
    ];
}

/**
 * Снимок без служебных меток времени. `updated_at` меняется при каждом сохранении, поэтому
 * сравнивать по нему нельзя: иначе пустое сохранение всегда выглядело бы новой ревизией.
 *
 * @param array<string, mixed> $payload
 */
function contentRevisionStableJson(array $payload): string
{
    /** @param mixed $value */
    $strip = static function ($value) use (&$strip) {
        if (!is_array($value)) {
            return $value;
        }

        unset($value['updated_at']);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $strip($item);
            }
        }

        return $value;
    };

    return contentRevisionCanonicalJson($strip($payload));
}

/** Канонический JSON: ключи объектов отсортированы, списки сохраняют порядок.
 *  @param array<string, mixed> $payload
 */
function contentRevisionCanonicalJson(array $payload): string
{
    /** @param mixed $value */
    $normalize = static function ($value) use (&$normalize) {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($normalize, $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $normalize($item);
        }

        return $value;
    };

    return (string) json_encode($normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed>|null */
function contentRevisionFetch(string $id): ?array
{
    $row = one(
        'SELECT id, content_type, content_id, version, parent_id, author_id, author_email, operation,'
        . ' source, comment, checksum, idempotency_key, payload_bytes, created_at FROM ' . t('content_revisions')
        . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    return $row;
}

/** @return array<string, mixed>|null полная ревизия с разобранным payload */
function contentRevisionGet(string $id): ?array
{
    $row = contentRevisionFetch($id);

    if ($row === null) {
        return null;
    }

    $row['payload'] = contentRevisionDecode((string) $id);

    return $row;
}

/** @return array<string, mixed> */
function contentRevisionDecode(string $id): array
{
    $raw = one('SELECT payload FROM ' . t('content_revisions') . ' WHERE id = ? LIMIT 1', [$id]);
    $decoded = json_decode((string) ($raw['payload'] ?? '{}'), true);

    return is_array($decoded) ? $decoded : [];
}

/** @return array<string, mixed>|null */
function contentRevisionByIdempotency(string $type, string $id, string $key): ?array
{
    if ($key === '') {
        return null;
    }

    return one(
        'SELECT id, version, created_at FROM ' . t('content_revisions')
        . ' WHERE content_type = ? AND content_id = ? AND idempotency_key = ? LIMIT 1',
        [$type, contentRevisionKeyId($type, $id), $key]
    );
}

/** Резервирует голову и проверяет ожидаемую версию. Вызывается до изменения материала.
 *  @param array<string, mixed> $meta
 */
function contentRevisionGuard(string $type, string $id, array $meta = [], ?DateTimeImmutable $now = null): void
{
    if (!contentRevisionTablesReady()) {
        return;
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $moment = utcStamp($now);

    run(
        'INSERT IGNORE INTO ' . t('content_heads') . ' (content_type, content_id, version, revision_id, updated_by, updated_at)'
        . ' VALUES (?, ?, 0, NULL, ?, ?)',
        [$type, $key, $meta['author_id'] ?? null, $moment]
    );

    $head = one(
        'SELECT version, revision_id FROM ' . t('content_heads')
        . ' WHERE content_type = ? AND content_id = ? FOR UPDATE',
        [$type, $key]
    );

    $actual = (int) ($head['version'] ?? 0);
    $revisionId = (string) ($head['revision_id'] ?? '');
    $idempotency = (string) ($meta['idempotency_key'] ?? '');

    if ($idempotency !== '') {
        $existing = contentRevisionByIdempotency($type, $id, $idempotency);

        if ($existing !== null) {
            throw new ContentVersionReplay((string) $existing['id']);
        }
    }

    $expected = $meta['expected_version'] ?? null;

    if ($expected !== null && (int) $expected !== $actual) {
        throw new ContentVersionConflict(
            (int) $expected,
            $actual,
            $revisionId,
            $type,
            $key
        );
    }
}

/**
 * Записывает снимок и двигает голову. Вызывается внутри той же транзакции, что и изменение.
 *
 * @param array<string, mixed> $meta
 * @return array{created: bool, revision_id: string, version: int, checksum: string}
 */
function contentRevisionCommit(string $type, string $id, array $meta = [], ?DateTimeImmutable $now = null): array
{
    $empty = ['created' => false, 'revision_id' => '', 'version' => 0, 'checksum' => ''];

    if (!contentRevisionTablesReady() || !contentRevisionTypeValid($type)) {
        return $empty;
    }

    $key = contentRevisionKeyId($type, $id);
    $meta = contentRevisionMeta($meta);
    $snapshot = contentRevisionSnapshot($type, $key);

    if (!$snapshot['available']) {
        return $empty;
    }

    $now ??= nowUtc();
    $moment = utcStamp($now);
    $json = contentRevisionCanonicalJson($snapshot['content']);
    $checksum = hash('sha256', $json);
    // Голова могла ещё не существовать: создание материала пишет сразу ревизию, не вызывая
    // guard. Без строки головы UPDATE ниже не сработал бы, и следующая правка получила бы
    // повтор версии 1. INSERT IGNORE делает commit самодостаточным.
    run(
        'INSERT IGNORE INTO ' . t('content_heads') . ' (content_type, content_id, version, revision_id, updated_by, updated_at)'
        . ' VALUES (?, ?, 0, NULL, ?, ?)',
        [$type, $key, $meta['author_id'] !== '' ? $meta['author_id'] : null, $moment]
    );
    $head = contentRevisionHead($type, $key);
    $version = (int) $head['version'];
    $parentId = $head['revision_id'] === null ? null : (string) $head['revision_id'];

    // Одинаковый снимок при ручном сохранении не плодит пустые ревизии. Публикация и
    // восстановление фиксируются всегда: это значимые события, даже если текст не изменился.
    if ($version > 0 && $parentId !== null && !in_array($meta['operation'], ['publish', 'restore', 'initial'], true)) {
        $parent = contentRevisionFetch($parentId);

        if ($parent !== null && contentRevisionStableJson($snapshot['content']) === contentRevisionStableJson(contentRevisionDecode($parentId))) {
            return [
                'created' => false,
                'revision_id' => $parentId,
                'version' => $version,
                'checksum' => $checksum,
            ];
        }
    }

    $revisionId = newId();
    $next = $version + 1;

    try {
        insert('content_revisions', [
            'id' => $revisionId,
            'content_type' => $type,
            'content_id' => $key,
            'version' => $next,
            'parent_id' => $parentId,
            'author_id' => $meta['author_id'] !== '' ? $meta['author_id'] : null,
            'author_email' => mb_substr($meta['author_email'], 0, 190),
            'operation' => $meta['operation'],
            'source' => $meta['source'],
            'comment' => $meta['comment'],
            'checksum' => $checksum,
            'idempotency_key' => $meta['idempotency_key'] !== '' ? $meta['idempotency_key'] : null,
            'payload' => $json,
            'payload_bytes' => strlen($json),
            'created_at' => $moment,
        ]);
    } catch (Throwable $error) {
        if (duplicateKeyException($error) && $meta['idempotency_key'] !== '') {
            $existing = contentRevisionByIdempotency($type, $id, $meta['idempotency_key']);

            if ($existing !== null) {
                throw new ContentVersionReplay((string) $existing['id']);
            }
        }

        throw $error;
    }

    run(
        'UPDATE ' . t('content_heads') . ' SET version = ?, revision_id = ?, updated_by = ?, updated_at = ?'
        . ' WHERE content_type = ? AND content_id = ?',
        [$next, $revisionId, $meta['author_id'] !== '' ? $meta['author_id'] : null, $moment, $type, $key]
    );

    return ['created' => true, 'revision_id' => $revisionId, 'version' => $next, 'checksum' => $checksum];
}

/**
 * Базовая ревизия старого материала: снимок текущего состояния с пометкой «импортировано».
 * Повторный вызов ничего не делает.
 */
function contentRevisionEnsureBaseline(string $type, string $id, ?DateTimeImmutable $now = null): void
{
    if (!contentRevisionTablesReady() || !contentRevisionTypeValid($type)) {
        return;
    }

    $key = contentRevisionKeyId($type, $id);

    if (contentRevisionVersion($type, $key) > 0) {
        return;
    }

    // Пустая главная не имеет состояния, которое стоило бы импортировать: базовая ревизия
    // появится только у главной с секциями. Иначе первый же блок получал бы версию 2 и
    // историю с пустым «состоянием до RELEASE 2.2».
    if ($key === 'home' && $type === 'page' && contentRevisionTableExists('page_section')) {
        $homeSections = (int) value('SELECT COUNT(*) FROM ' . t('page_section') . " WHERE page_id = ''");

        if ($homeSections === 0) {
            return;
        }
    }

    $snapshot = contentRevisionSnapshot($type, $key);

    if (!$snapshot['available']) {
        return;
    }

    try {
        tx(static function () use ($type, $key, $now): void {
            contentRevisionCommit($type, $key, [
                'operation' => 'initial',
                'source' => 'import',
                'comment' => 'Состояние до RELEASE 2.2',
            ], $now);
        });
    } catch (ContentVersionReplay) {
        // Параллельный запрос уже создал базовую ревизию.
    }
}

/** @return array{created: int, skipped: int} */
function contentRevisionBaselineAll(?DateTimeImmutable $now = null): array
{
    $created = 0;
    $skipped = 0;

    if (!contentRevisionTablesReady()) {
        return ['created' => 0, 'skipped' => 0];
    }

    $ids = [];

    if (contentRevisionTableExists('page')) {
        foreach (all('SELECT id FROM ' . t('page') . ' ORDER BY id LIMIT 10000') as $row) {
            $ids[] = ['page', (string) $row['id']];
        }
    }

    if (contentRevisionTableExists('page_section')) {
        $home = one('SELECT id FROM ' . t('page_section') . " WHERE page_id = '' LIMIT 1");

        if ($home !== null) {
            $ids[] = ['page', 'home'];
        }
    }

    if (contentRevisionTableExists('news_item')) {
        foreach (all('SELECT id FROM ' . t('news_item') . ' ORDER BY id LIMIT 10000') as $row) {
            $ids[] = ['news', (string) $row['id']];
        }
    }

    if (contentRevisionTableExists('commerce_product')) {
        foreach (all('SELECT id FROM ' . t('commerce_product') . ' ORDER BY id LIMIT 10000') as $row) {
            $ids[] = ['product', (string) $row['id']];
        }
    }

    foreach ($ids as [$type, $id]) {
        $before = contentRevisionVersion($type, $id);
        contentRevisionEnsureBaseline($type, $id, $now);

        if ($before === 0 && contentRevisionVersion($type, $id) > 0) {
            $created++;
        } else {
            $skipped++;
        }
    }

    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Список ревизий с фильтрами и постраничной загрузкой. Полный payload здесь не читается:
 * история больших материалов не тянет мегабайты JSON на каждый экран.
 *
 * @param array{author?: string, operation?: string, from?: string, to?: string} $filters
 * @return list<array<string, mixed>>
 */
function contentRevisionList(string $type, string $id, array $filters = [], int $limit = CONTENT_REVISION_PAGE_SIZE, int $offset = 0): array
{
    if (!contentRevisionTablesReady()) {
        return [];
    }

    $where = ['content_type = ?', 'content_id = ?'];
    $args = [$type, contentRevisionKeyId($type, $id)];

    $author = trim((string) ($filters['author'] ?? ''));
    if ($author !== '') {
        $where[] = 'author_id = ?';
        $args[] = $author;
    }

    $operation = trim((string) ($filters['operation'] ?? ''));
    if (in_array($operation, CONTENT_REVISION_OPERATIONS, true)) {
        $where[] = 'operation = ?';
        $args[] = $operation;
    }

    $from = parseUtcDay((string) ($filters['from'] ?? ''));
    if ($from !== null) {
        $where[] = 'created_at >= ?';
        $args[] = utcStamp($from);
    }

    $to = parseUtcDay((string) ($filters['to'] ?? ''));
    if ($to !== null) {
        $where[] = 'created_at < ?';
        $args[] = utcStamp(shiftDays($to, 1));
    }

    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $sql = sprintf(
        'SELECT id, content_type, content_id, version, parent_id, author_id, author_email, operation,'
        . ' source, comment, checksum, payload_bytes, created_at FROM ' . t('content_revisions')
        . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY version DESC LIMIT %d OFFSET %d',
        $limit,
        $offset
    );

    return all($sql, $args);
}

/** @param array{author?: string, operation?: string, from?: string, to?: string} $filters */
function contentRevisionCount(string $type, string $id, array $filters = []): int
{
    if (!contentRevisionTablesReady()) {
        return 0;
    }

    $where = ['content_type = ?', 'content_id = ?'];
    $args = [$type, contentRevisionKeyId($type, $id)];

    $operation = trim((string) ($filters['operation'] ?? ''));
    if (in_array($operation, CONTENT_REVISION_OPERATIONS, true)) {
        $where[] = 'operation = ?';
        $args[] = $operation;
    }

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('content_revisions') . ' WHERE ' . implode(' AND ', $where),
        $args
    );
}

/** @return array<string, mixed>|null последняя ревизия без payload */
function contentRevisionLatest(string $type, string $id): ?array
{
    if (!contentRevisionTablesReady()) {
        return null;
    }

    return one(
        'SELECT id, content_type, content_id, version, parent_id, author_id, author_email, operation,'
        . ' source, comment, checksum, payload_bytes, created_at FROM ' . t('content_revisions')
        . ' WHERE content_type = ? AND content_id = ? ORDER BY version DESC LIMIT 1',
        [$type, contentRevisionKeyId($type, $id)]
    );
}

/** @return array<string, mixed>|null последняя опубликованная ревизия */
function contentRevisionLastPublished(string $type, string $id): ?array
{
    if (!contentRevisionTablesReady()) {
        return null;
    }

    return one(
        'SELECT id, version, created_at FROM ' . t('content_revisions')
        . ' WHERE content_type = ? AND content_id = ? AND operation = ? ORDER BY version DESC LIMIT 1',
        [$type, contentRevisionKeyId($type, $id), 'publish']
    );
}

/**
 * Восстанавливает ревизию как новое состояние. История не удаляется: создаётся ещё одна
 * ревизия с операцией restore, а выбранная остаётся на месте.
 *
 * @param array<string, mixed> $meta
 * @return array{ok: bool, error: string, revision_id: string, version: int}
 */
function contentRevisionRestore(string $type, string $id, string $revisionId, array $meta = [], ?DateTimeImmutable $now = null): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'error' => $error, 'revision_id' => '', 'version' => 0];

    if (!contentRevisionTablesReady()) {
        return $fail('История версий ещё не установлена — примените миграции RELEASE 2.2.');
    }

    $revision = contentRevisionGet($revisionId);

    if ($revision === null) {
        return $fail('Версия не найдена — возможно, её уже удалили.');
    }

    if ((string) $revision['content_type'] !== $type || (string) $revision['content_id'] !== contentRevisionKeyId($type, $id)) {
        return $fail('Эта версия принадлежит другому материалу.');
    }

    $key = contentRevisionKeyId($type, $id);
    $now ??= nowUtc();
    $meta = contentRevisionMeta($meta);
    $meta['operation'] = 'restore';
    $meta['comment'] = $meta['comment'] !== '' ? $meta['comment'] : 'Восстановлена версия ' . (string) $revision['version'];
    $payload = $revision['payload'];

    try {
        $result = tx(static function () use ($type, $key, $payload, $meta, $now): array {
            contentRevisionGuard($type, $key, $meta, $now);
            contentRevisionApply($type, $key, $payload, $now);

            return contentRevisionCommit($type, $key, $meta, $now);
        });
    } catch (ContentVersionConflict $conflict) {
        return $fail($conflict->getMessage());
    } catch (ContentVersionReplay $replay) {
        $row = contentRevisionFetch($replay->revisionId);

        return ['ok' => true, 'error' => '', 'revision_id' => $replay->revisionId, 'version' => (int) ($row['version'] ?? 0)];
    }

    return ['ok' => true, 'error' => '', 'revision_id' => (string) $result['revision_id'], 'version' => (int) $result['version']];
}

/** Применяет payload ревизии к живой таблице. Вызывается внутри транзакции после guard.
 *  @param array<string, mixed> $payload
 */
function contentRevisionApply(string $type, string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    if ($type === 'page') {
        contentRevisionApplyPage($key, $payload, $now);
    } elseif ($type === 'news') {
        contentRevisionApplyNews($key, $payload, $now);
    } elseif ($type === 'product') {
        contentRevisionApplyProduct($key, $payload, $now);
    } else {
        doAction('content_revision_apply', $type, $key, $payload);
    }
}

/** @param array<string, mixed> $payload */
function contentRevisionApplyPage(string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    $moment = utcStamp($now ?? nowUtc());
    $slug = trim((string) ($payload['slug'] ?? ''));

    if ($key !== 'home') {
        if (pageById($key) === null) {
            throw new RuntimeException('Страница уже удалена — сначала восстановите её из корзины.');
        }

        if ($slug === '') {
            throw new RuntimeException('В выбранной версии нет адреса страницы.');
        }

        if (pageSlugTaken($slug, $key)) {
            throw new RuntimeException('Адрес «' . $slug . '» уже занят другой страницей — освободите его и повторите восстановление.');
        }

        run(
            'UPDATE ' . t('page') . ' SET slug = ?, title = ?, description = ?, status = ?, noindex = ?, updated_at = ? WHERE id = ?',
            [
                $slug,
                (string) ($payload['title'] ?? ''),
                (string) ($payload['description'] ?? ''),
                (string) ($payload['status'] ?? PAGES_STATUS_DRAFT),
                (int) ($payload['noindex'] ?? 0) === 1 ? 1 : 0,
                $moment,
                $key,
            ]
        );
    }

    $pageId = $key === 'home' ? PAGES_HOME_ID : $key;

    // Секции пересоздаются с прежними идентификаторами: порядок, ключи и связи комментариев
    // остаются осмысленными. Промежуточное состояние уже сохранено в ревизии, потери нет.
    run('DELETE FROM ' . t('page_section') . ' WHERE page_id = ?', [$pageId]);

    foreach (is_array($payload['sections'] ?? null) ? $payload['sections'] : [] as $order => $section) {
        if (!is_array($section)) {
            continue;
        }

        $id = trim((string) ($section['id'] ?? ''));

        if ($id === '') {
            continue;
        }

        insert('page_section', [
            'id' => $id,
            'page_id' => $pageId,
            'sec_key' => (string) ($section['sec_key'] ?? ''),
            'kind' => (string) ($section['kind'] ?? ''),
            'layout' => (string) ($section['layout'] ?? ''),
            'anchor' => (string) ($section['anchor'] ?? ''),
            'badge' => (string) ($section['badge'] ?? ''),
            'title' => (string) ($section['title'] ?? ''),
            'lead_text' => (string) ($section['lead_text'] ?? ''),
            'note' => (string) ($section['note'] ?? ''),
            'image' => (string) ($section['image'] ?? ''),
            'image_alt' => (string) ($section['image_alt'] ?? ''),
            'buttons' => contentRevisionJsonColumn($section['buttons'] ?? [], '[]'),
            'items' => contentRevisionJsonColumn($section['items'] ?? [], '[]'),
            'settings' => contentRevisionJsonColumn($section['settings'] ?? [], '{}'),
            'sort' => (int) ($section['sort'] ?? $order),
            'visible' => contentRevisionFlag($section['visible'] ?? 1),
            'updated_at' => $moment,
        ]);
    }
}

/** @param array<string, mixed> $payload */
function contentRevisionApplyNews(string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    $existing = contentRevisionTableExists('news_item')
        ? one('SELECT id FROM ' . t('news_item') . ' WHERE id = ? LIMIT 1', [$key])
        : null;

    if ($existing === null) {
        throw new RuntimeException('Материал уже удалён — восстановить его историю нельзя.');
    }

    $moment = utcStamp($now ?? nowUtc());
    $slug = trim((string) ($payload['slug'] ?? ''));

    if ($slug === '') {
        throw new RuntimeException('В выбранной версии нет адреса материала.');
    }

    $slugBusy = one(
        'SELECT id FROM ' . t('news_item') . ' WHERE slug = ? AND id <> ? LIMIT 1',
        [$slug, $key]
    );

    if ($slugBusy !== null) {
        throw new RuntimeException('Адрес «' . $slug . '» уже занят другим материалом.');
    }

    run(
        'UPDATE ' . t('news_item') . ' SET slug = ?, title = ?, lead_text = ?, cover = ?, category_id = ?, body = ?,'
        . ' status = ?, published_at = ?, pinned = ?, seo_title = ?, seo_description = ?, og_image = ?, noindex = ?,'
        . ' author_id = ?, author_email = ?, updated_at = ? WHERE id = ?',
        [
            $slug,
            (string) ($payload['title'] ?? ''),
            (string) ($payload['lead_text'] ?? '') !== '' ? (string) $payload['lead_text'] : null,
            (string) ($payload['cover'] ?? '') !== '' ? (string) $payload['cover'] : null,
            (string) ($payload['category_id'] ?? ''),
            contentRevisionJsonColumn($payload['body'] ?? [], '[]'),
            (string) ($payload['status'] ?? 'DRAFT'),
            (string) ($payload['published_at'] ?? '') !== '' ? (string) $payload['published_at'] : null,
            (int) ($payload['pinned'] ?? 0) === 1 ? 1 : 0,
            (string) ($payload['seo_title'] ?? '') !== '' ? (string) $payload['seo_title'] : null,
            (string) ($payload['seo_description'] ?? '') !== '' ? (string) $payload['seo_description'] : null,
            (string) ($payload['og_image'] ?? '') !== '' ? (string) $payload['og_image'] : null,
            (int) ($payload['noindex'] ?? 0) === 1 ? 1 : 0,
            (string) ($payload['author_id'] ?? '') !== '' ? (string) $payload['author_id'] : null,
            (string) ($payload['author_email'] ?? '') !== '' ? (string) $payload['author_email'] : null,
            $moment,
            $key,
        ]
    );

    if (contentRevisionTableExists('news_item_tag')) {
        run('DELETE FROM ' . t('news_item_tag') . ' WHERE item_id = ?', [$key]);

        foreach (is_array($payload['tags'] ?? null) ? $payload['tags'] : [] as $tag) {
            if (is_array($tag) && (string) ($tag['id'] ?? '') !== '') {
                insert('news_item_tag', ['item_id' => $key, 'tag_id' => (string) $tag['id']]);
            }
        }
    }
}

/** @param array<string, mixed> $payload */
function contentRevisionApplyProduct(string $key, array $payload, ?DateTimeImmutable $now = null): void
{
    if (!contentRevisionTableExists('commerce_product') || !function_exists('commerceProductById') || commerceProductById($key) === null) {
        throw new RuntimeException('Товар уже удалён — восстановить его историю нельзя.');
    }

    $moment = utcStamp($now ?? nowUtc());
    $slug = trim((string) ($payload['slug'] ?? ''));

    if ($slug === '') {
        throw new RuntimeException('В выбранной версии нет адреса товара.');
    }

    $slugBusy = one(
        'SELECT id FROM ' . t('commerce_product') . ' WHERE slug = ? AND id <> ? LIMIT 1',
        [$slug, $key]
    );

    if ($slugBusy !== null) {
        throw new RuntimeException('Адрес «' . $slug . '» уже занят другим товаром.');
    }

    run(
        'UPDATE ' . t('commerce_product') . ' SET slug = ?, sku = ?, title = ?, lead_text = ?, body = ?, brand = ?,'
        . ' barcode = ?, price_retail = ?, price_promo = ?, price_cost = ?, currency = ?, stock = ?, status = ?,'
        . ' seo_title = ?, seo_description = ?, noindex = ?, updated_at = ? WHERE id = ?',
        [
            $slug,
            (string) ($payload['sku'] ?? ''),
            (string) ($payload['title'] ?? ''),
            (string) ($payload['lead_text'] ?? '') !== '' ? (string) $payload['lead_text'] : null,
            contentRevisionJsonColumn($payload['body'] ?? [], '[]'),
            (string) ($payload['brand'] ?? '') !== '' ? (string) $payload['brand'] : null,
            (string) ($payload['barcode'] ?? '') !== '' ? (string) $payload['barcode'] : null,
            (float) ($payload['price_retail'] ?? 0),
            isset($payload['price_promo']) ? (float) $payload['price_promo'] : null,
            isset($payload['price_cost']) ? (float) $payload['price_cost'] : null,
            (string) ($payload['currency'] ?? 'RUB'),
            (int) ($payload['stock'] ?? 0),
            (string) ($payload['status'] ?? 'DRAFT'),
            (string) ($payload['seo_title'] ?? '') !== '' ? (string) $payload['seo_title'] : null,
            (string) ($payload['seo_description'] ?? '') !== '' ? (string) $payload['seo_description'] : null,
            (int) ($payload['noindex'] ?? 0) === 1 ? 1 : 0,
            $moment,
            $key,
        ]
    );

    if (contentRevisionTableExists('commerce_product_category')) {
        run('DELETE FROM ' . t('commerce_product_category') . ' WHERE product_id = ?', [$key]);

        foreach (is_array($payload['categories'] ?? null) ? $payload['categories'] : [] as $category) {
            $categoryId = is_array($category) ? (string) ($category['id'] ?? '') : '';

            if ($categoryId !== '') {
                insert('commerce_product_category', ['product_id' => $key, 'category_id' => $categoryId]);
            }
        }
    }
}

/** @param mixed $value */
function contentRevisionJsonColumn($value, string $empty): string
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $value : $empty;
    }

    if (!is_array($value) || $value === []) {
        return $empty;
    }

    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** @param mixed $value */
function contentRevisionFlag($value): int
{
    return (string) $value === '0' || $value === false ? 0 : 1;
}

/**
 * Очистка старых ревизий. По умолчанию не удаляются: последняя ревизия, все публикации,
 * последняя опубликованная версия и вся история материала, находящегося в активном согласовании.
 *
 * @return array{deleted: int, kept: int}
 */
function contentRevisionCleanup(bool $dryRun = false, ?DateTimeImmutable $now = null): array
{
    if (!contentRevisionTablesReady()) {
        return ['deleted' => 0, 'kept' => 0];
    }

    $maxPerContent = max(1, (int) getOption('REVISION_MAX_PER_CONTENT', (string) CONTENT_REVISION_DEFAULT_LIMIT));
    $days = max(1, (int) getOption('REVISION_RETENTION_DAYS', (string) CONTENT_REVISION_DEFAULT_DAYS));
    $now ??= nowUtc();
    $olderThan = utcStamp(shiftDays($now, -$days));
    $deleted = 0;
    $kept = 0;

    $contents = all(
        'SELECT content_type, content_id FROM ' . t('content_revisions')
        . ' GROUP BY content_type, content_id ORDER BY content_type, content_id LIMIT 5000'
    );

    foreach ($contents as $content) {
        $type = (string) $content['content_type'];
        $id = (string) $content['content_id'];
        $state = contentRevisionTableExists('editorial_states')
            ? one('SELECT status FROM ' . t('editorial_states') . ' WHERE content_type = ? AND content_id = ? LIMIT 1', [$type, $id])
            : null;

        if ($state !== null && in_array((string) $state['status'], ['review', 'approved', 'scheduled'], true)) {
            $kept++;
            continue;
        }

        $rows = all(
            'SELECT id, version, operation, created_at FROM ' . t('content_revisions')
            . ' WHERE content_type = ? AND content_id = ? ORDER BY version DESC LIMIT 10000',
            [$type, $id]
        );

        $keepIds = [];
        $latest = $rows[0] ?? null;

        if ($latest !== null) {
            $keepIds[(string) $latest['id']] = true;
        }

        foreach ($rows as $row) {
            if ((string) $row['operation'] === 'publish') {
                $keepIds[(string) $row['id']] = true;
            }
        }

        $lastPublished = contentRevisionLastPublished($type, $id);

        if ($lastPublished !== null) {
            $keepIds[(string) $lastPublished['id']] = true;
        }

        $seen = 0;
        foreach ($rows as $row) {
            $seen++;

            if (isset($keepIds[(string) $row['id']])) {
                continue;
            }

            $tooMany = $seen > $maxPerContent;
            $tooOld = (string) $row['created_at'] < $olderThan;

            if (!$tooMany && !$tooOld) {
                continue;
            }

            if ($dryRun) {
                $deleted++;
                continue;
            }

            run('DELETE FROM ' . t('content_revisions') . ' WHERE id = ?', [(string) $row['id']]);
            $deleted++;
        }
    }

    return ['deleted' => $deleted, 'kept' => $kept];
}

/**
 * Удаление одной ревизии администратором. Актуальная ревизия, любая публикация и последняя
 * опубликованная версия неприкосновенны: это точка возврата, а не черновик. Дети удаляемой
 * ревизии переподвешиваются к её родителю, чтобы цепочка истории не рвалась.
 *
 * @param array<string, mixed>|null $user
 * @return array{ok: bool, error: string}
 */
function contentRevisionDelete(string $type, string $id, string $revisionId, ?array $user = null): array
{
    if (!contentRevisionTablesReady() || !contentRevisionTypeValid($type)) {
        return ['ok' => false, 'error' => 'История версий ещё не установлена — примените миграции RELEASE 2.2.'];
    }

    $key = contentRevisionKeyId($type, $id);
    $revision = contentRevisionFetch($revisionId);

    if ($revision === null) {
        return ['ok' => false, 'error' => 'Версия не найдена — возможно, её уже удалили.'];
    }

    if ((string) $revision['content_type'] !== $type || (string) $revision['content_id'] !== $key) {
        return ['ok' => false, 'error' => 'Эта версия принадлежит другому материалу.'];
    }

    if ((string) $revision['operation'] === 'publish') {
        return ['ok' => false, 'error' => 'Опубликованная версия остаётся в истории: удалять её нельзя.'];
    }

    $latest = contentRevisionLatest($type, $key);

    if ($latest !== null && (string) $latest['id'] === $revisionId) {
        return ['ok' => false, 'error' => 'Актуальную версию удалить нельзя — сначала сохраните более новую.'];
    }

    $lastPublished = contentRevisionLastPublished($type, $key);

    if ($lastPublished !== null && (string) $lastPublished['id'] === $revisionId) {
        return ['ok' => false, 'error' => 'Последняя опубликованная версия остаётся в истории.'];
    }

    try {
        tx(static function () use ($revisionId, $revision): void {
            run(
                'UPDATE ' . t('content_revisions') . ' SET parent_id = ? WHERE parent_id = ?',
                [$revision['parent_id'] !== null ? (string) $revision['parent_id'] : null, $revisionId]
            );
            run('DELETE FROM ' . t('content_revisions') . ' WHERE id = ?', [$revisionId]);
        });
    } catch (Throwable $error) {
        logError('revisions.delete_failed', ['revision' => $revisionId, 'error' => $error->getMessage()]);

        return ['ok' => false, 'error' => 'Не удалось удалить ревизию — повторите позже.'];
    }

    auditLog(
        'editorial.revision_delete',
        $type,
        $key,
        ['revision' => $revisionId, 'version' => (int) $revision['version']],
        'Удалена ревизия ' . (int) $revision['version']
    );

    return ['ok' => true, 'error' => ''];
}

/** Человеческая подпись операции для экранов и API. */
function contentRevisionOperationLabel(string $operation): string
{
    return CONTENT_REVISION_OPERATION_LABELS[$operation] ?? $operation;
}
