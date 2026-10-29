<?php

declare(strict_types=1);

// Раздел глазами редактора: вкладки списка, строка формы, сохранение материала и кнопки состояния.
// Порт english-portal/src/apps/news/lib/items.ts. Публичная лента читает свои выборки в feed.php;
// выборки этого файла сознательно НЕ ограничены наступившим сроком — черновик и «ещё не время»
// обязан видеть тот, кто раздел правит (§8.2).
//
// Это единственное место раздела, которое читает tbl_news_item без visibleWhere(), и оно названо
// в страже видимости (задача 20), чтобы правило не разъехалось молча.

// Идентификатор материала и рубрики приходит из адресной строки: столько занимает строковый
// идентификатор из core/id.php, и более длинное значение не нашло бы строку.
const NEWS_ID_MAX = 26;

// Путь списка администратора — одна строка на ссылки, маршруты и форму.
const NEWS_ADMIN_ITEMS_PATH = '/news/admin/items';

// Порядок списка — по последней правке, а не по сроку: редактор возвращается к тому, что трогал
// последним, а «закреплённые вперёд» здесь только путали бы картину. Хвост из created_at и id
// обязателен: у строк одной секунды порядок иначе решала бы база, и материал повторялся бы на двух
// страницах списка или пропадал между ними.
const ADMIN_ITEMS_ORDER = 'i.updated_at DESC, i.created_at DESC, i.id DESC';

// Единственная формулировка правила «на печать выходят с текстом»: её читают и кнопка списка, и
// форма, чтобы два пути не разошлись ни словами, ни смыслом. Пустая статья на сайте — пустая
// карточка в ленте.
const NEWS_PUBLISH_NEEDS_TEXT = 'Материал выходит на печать с текстом — допишите абзацы в форме.';

// ── Вкладки и ссылки списка ──────────────────────────────

// Неизвестное значение вкладки — «все материалы»: адрес пишет человек или чужой скрипт,
// и падать из-за опечатки незачем.
function statusFilterOf(string $value): string
{
    return in_array($value, NEWS_FILTERS, true) ? $value : 'all';
}

// Номер страницы читается только целым числом: «2.7» и «abc» вернули бы отрицательное смещение,
// а MySQL ответил бы ошибкой на OFFSET -1.
function pageOf(string $value): int
{
    $raw = trim($value);

    if (preg_match('/^\d+$/', $raw) !== 1) {
        return 1;
    }

    $page = (int) $raw;

    return $page > 1 ? $page : 1;
}

/**
 * @param array{status?: string, cat?: string, q?: string, page?: string} $raw
 *
 * @return array{status: string, categoryId: string, query: string, page: int}
 */
function parseAdminFilters(array $raw): array
{
    return [
        'status' => statusFilterOf((string) ($raw['status'] ?? '')),
        'categoryId' => mb_substr(trim((string) ($raw['cat'] ?? '')), 0, NEWS_ID_MAX),
        'query' => parseQuery($raw['q'] ?? null),
        'page' => pageOf((string) ($raw['page'] ?? '')),
    ];
}

/**
 * Все ссылки списка несут текущие условия: ссылка, которая их теряет, возвращает человека в другой
 * список, чем тот, что он видел. Пустые значения в строку запроса не пишутся, а первая страница не
 * пишется вовсе — `?page=1` дублировал бы чистую ссылку в глазах поисковика.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 */
function adminItemsHref(array $filters): string
{
    $parts = [];
    $status = statusFilterOf((string) ($filters['status'] ?? ''));

    if ($status !== 'all') {
        $parts[] = 'status=' . rawurlencode($status);
    }

    $category = mb_substr(trim((string) ($filters['categoryId'] ?? '')), 0, NEWS_ID_MAX);

    if ($category !== '') {
        $parts[] = 'cat=' . rawurlencode($category);
    }

    $query = parseQuery($filters['query'] ?? null);

    if ($query !== '') {
        $parts[] = 'q=' . rawurlencode($query);
    }

    $page = (int) ($filters['page'] ?? 1);

    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $parts === [] ? NEWS_ADMIN_ITEMS_PATH : NEWS_ADMIN_ITEMS_PATH . '?' . implode('&', $parts);
}

/**
 * Условие вкладки. «Ждут срока» и «опубликованные» — один статус в базе, их различает срок,
 * поэтому момент приходит аргументом, а не подставляется в текст условия.
 *
 * Колонки условия видимости написаны без префикса — так их отдаёт visibleWhere(), и в соединении
 * с рубрикой они однозначны: в tbl_news_category нет ни `status`, ни `published_at`. Все колонки,
 * которые есть в обеих таблицах (`slug`, `title`), написаны с префиксом `i.`.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 *
 * @return array{where: string, args: list<string>}
 */
function adminWhere(array $filters, DateTimeImmutable $now): array
{
    $parts = [];
    $args = [];
    $status = statusFilterOf((string) ($filters['status'] ?? ''));

    if ($status === 'scheduled') {
        // Строка без срока считается ждущей: isOpen() её не открывает, значит вкладка «Ждут срока»
        // обязана её показать — иначе материал потерялся бы между вкладками.
        $parts[] = sprintf(
            "status = '%s' AND (published_at IS NULL OR published_at > ?)",
            NEWS_STATUS['PUBLISHED']
        );
        $args[] = utcStamp($now);
    } elseif ($status === NEWS_STATUS['PUBLISHED']) {
        // Вкладка «Опубликованные» показывает то же, что видит читатель: одно условие на двоих.
        $parts[] = visibleWhere();
        $args = visibleArgs($now);
    } elseif ($status === NEWS_STATUS['DRAFT']) {
        $parts[] = sprintf("status = '%s'", NEWS_STATUS['DRAFT']);
    }

    $categoryId = mb_substr(trim((string) ($filters['categoryId'] ?? '')), 0, NEWS_ID_MAX);

    if ($categoryId !== '') {
        // Чужой идентификатор рубрики — не ошибка, а пустой список.
        $parts[] = 'i.category_id = ?';
        $args[] = $categoryId;
    }

    $query = parseQuery($filters['query'] ?? null);

    if ($query !== '') {
        $like = '%' . escapeLike($query) . '%';
        // Знак снятия объявляем явно и передаём его связываемым параметром: литерал обратного
        // слэша в тексте SQL живёт только в одном режиме (`ESCAPE '\\'` ломается при
        // `NO_BACKSLASH_ESCAPES`, `ESCAPE '\'` — в режиме по умолчанию), а бинд даёт ровно один
        // слэш в обоих. Без объявления поиск по «100%» при этом режиме ищет уже не то.
        $parts[] = '(i.title LIKE ? ESCAPE ? OR i.lead_text LIKE ? ESCAPE ? OR i.slug LIKE ? ESCAPE ?)';
        $args[] = $like;
        $args[] = '\\';
        $args[] = $like;
        $args[] = '\\';
        $args[] = $like;
        $args[] = '\\';
    }

    // Пустое условие — единица: `WHERE 1` читается как «все строки», а без него каждому вызову
    // пришлось бы ветвиться на «есть условие» и «условия нет».
    return ['where' => $parts === [] ? '1' : implode(' AND ', $parts), 'args' => $args];
}

// ── Список ───────────────────────────────────────────────

/**
 * Надписи тегов для строк страницы — одним запросом: по запросу на строку список из двадцати
 * материалов открывался бы сорока запросами, а виртуальный хостинг считает каждый.
 *
 * @param list<string> $itemIds
 *
 * @return array<string, list<string>>
 */
function adminTagsOf(array $itemIds): array
{
    $ids = array_values(array_filter($itemIds, static fn (string $id): bool => $id !== ''));

    if ($ids === []) {
        return [];
    }

    $sql = sprintf(
        'SELECT link.item_id, tg.label FROM %s link JOIN %s tg ON tg.id = link.tag_id'
            . ' WHERE link.item_id IN (%s) ORDER BY tg.label',
        t('news_item_tag'),
        t('news_tag'),
        inPlaceholders(count($ids))
    );

    $out = [];

    foreach (all($sql, $ids) as $row) {
        $out[(string) $row['item_id']][] = (string) $row['label'];
    }

    return $out;
}

/**
 * Строка списка из строки базы. Моменты приводятся к московскому времени здесь: шаблон не имеет
 * права сам решать, в каком времени печатать (страж DateUsageTest не пускает туда date() и format()).
 *
 * @param array<string, mixed> $row
 * @param list<string> $tags
 *
 * @return array{
 *     id: string, slug: string, title: string, status: string, statusLabel: string,
 *     open: bool, scheduled: bool, pinned: bool, href: string, editHref: string,
 *     publishedAt: DateTimeImmutable|null, published: string,
 *     updatedAt: DateTimeImmutable|null, updated: string,
 *     categoryTitle: string, categoryVisible: bool, tags: list<string>, authorEmail: string
 * }
 */
function adminItemRow(array $row, array $tags, DateTimeImmutable $now): array
{
    $id = (string) $row['id'];
    $slug = (string) $row['slug'];
    $status = (string) $row['status'];
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

    return [
        'id' => $id,
        'slug' => $slug,
        'title' => (string) $row['title'],
        'status' => $status,
        'statusLabel' => NEWS_STATUS_LABEL[$status] ?? $status,
        'open' => isOpen($row, $now),
        'scheduled' => isScheduled($row, $now),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'href' => newsHref(['slug' => $slug]),
        'editHref' => NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id),
        'publishedAt' => $published,
        'published' => formatRuDateTime($published),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        // Рубрика присоединяется LEFT JOIN: строка с удалённой рубрикой обязана открыться в списке
        // и сказать об этом, а не уронить страницу.
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryVisible' => (int) ($row['category_visible'] ?? 0) === 1,
        'tags' => $tags,
        'authorEmail' => (string) ($row['author_email'] ?? ''),
    ];
}

/**
 * Страница списка администратора.
 *
 * @param array{status?: string, categoryId?: string, query?: string, page?: int} $filters
 *
 * @return array{
 *     items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>,
 *     perPage: int, href: string
 * }
 */
function adminItemPage(array $filters, DateTimeImmutable $now): array
{
    $scope = adminWhere($filters, $now);
    $from = sprintf('%s i LEFT JOIN %s c ON c.id = i.category_id', t('news_item'), t('news_category'));

    $total = (int) value(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $from, $scope['where']), $scope['args']);
    $pages = pageCount($total, ADMIN_NEWS_PER_PAGE);
    $current = clampPage(pageOf((string) ($filters['page'] ?? 1)), $pages);

    $sql = sprintf(
        'SELECT i.id, i.slug, i.title, i.status, i.published_at, i.pinned, i.updated_at, i.author_email,'
            . ' c.title AS category_title, c.visible AS category_visible'
            . ' FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
        $from,
        $scope['where'],
        ADMIN_ITEMS_ORDER,
        ADMIN_NEWS_PER_PAGE,
        ($current - 1) * ADMIN_NEWS_PER_PAGE
    );

    $rows = all($sql, $scope['args']);
    $tags = adminTagsOf(array_map(static fn (array $row): string => (string) $row['id'], $rows));
    $items = [];

    foreach ($rows as $row) {
        $items[] = adminItemRow($row, $tags[(string) $row['id']] ?? [], $now);
    }

    return [
        'items' => $items,
        'total' => $total,
        'pages' => $pages,
        'current' => $current,
        'window' => pageWindow($current, $pages),
        'perPage' => ADMIN_NEWS_PER_PAGE,
        'href' => adminItemsHref($filters),
    ];
}

/**
 * Счётчики вкладок. Число берётся четырьмя запросами, а не группировкой по статусу: срок публикации —
 * часть условия вкладки, и группировка по одной колонке его не учитывает.
 *
 * @return array<string, int>
 */
function adminItemCounts(DateTimeImmutable $now): array
{
    $counts = [];

    foreach (NEWS_FILTERS as $filter) {
        $scope = adminWhere(parseAdminFilters(['status' => $filter]), $now);
        $counts[$filter] = (int) value(
            sprintf('SELECT COUNT(*) FROM %s i WHERE %s', t('news_item'), $scope['where']),
            $scope['args']
        );
    }

    return $counts;
}

/** Закреплённых и открытых материалов — столько же, сколько их видит читатель на первой странице. */
function pinnedVisibleCount(DateTimeImmutable $now): int
{
    return (int) value(
        sprintf('SELECT COUNT(*) FROM %s i WHERE %s AND i.pinned = 1', t('news_item'), visibleWhere()),
        visibleArgs($now)
    );
}

/**
 * Материалы, которые читатель видит сейчас. Правило берётся у самой ленты (`feedScope()`):
 * счётчик «на сайте» обязан сходиться с первой страницей публичного раздела, а не со статусом
 * строки в базе. Вкладка «Опубликованные» считает иначе — материал снятой с показа рубрики
 * остаётся в списке администратора помеченным, и на сайте его нет.
 */
function onSiteItemCount(DateTimeImmutable $now): int
{
    $scope = feedScope(['now' => $now]);

    return (int) value(
        sprintf('SELECT COUNT(*) FROM %s WHERE %s', $scope['from'], $scope['where']),
        $scope['args']
    );
}

// ── Форма ────────────────────────────────────────────────

/** @return list<string> */
function oldSlugsOf(string $itemId): array
{
    if ($itemId === '') {
        return [];
    }

    $sql = 'SELECT slug FROM ' . t('news_old_slug') . ' WHERE item_id = ? ORDER BY created_at DESC, slug DESC';
    $out = [];

    foreach (all($sql, [$itemId]) as $row) {
        $out[] = (string) $row['slug'];
    }

    return $out;
}

/**
 * Строка формы. Правит тот материал, что лежит в базе, а не только тот, что видно на сайте:
 * в форму обязаны вернуться и черновик, и материал со сроком в будущем.
 *
 * Поля формы — строки: textarea читает текст абзацев, а не JSON, а поле тегов — одна строка через
 * запятую, а не список. Возврат введённого после отказа идёт в те же имена.
 *
 * @return array{
 *     id: string, slug: string, title: string, lead: string, cover: string, categoryId: string,
 *     categoryTitle: string, categoryVisible: bool, bodyText: string, tags: string, status: string,
 *     statusLabel: string, publishedAt: string, pinned: bool, seoTitle: string, seoDescription: string,
 *     ogImage: string, noindex: bool, oldSlugs: list<string>, authorEmail: string,
 *     updatedAt: DateTimeImmutable|null, updated: string, href: string, editHref: string
 * }|null
 */
function itemForForm(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    $sql = sprintf(
        'SELECT i.id, i.slug, i.title, i.lead_text, i.cover, i.category_id, i.body, i.status, i.published_at,'
            . ' i.pinned, i.seo_title, i.seo_description, i.og_image, i.noindex, i.author_email, i.updated_at,'
            . ' c.title AS category_title, c.visible AS category_visible'
            . ' FROM %s i LEFT JOIN %s c ON c.id = i.category_id WHERE i.id = ?',
        t('news_item'),
        t('news_category')
    );

    $row = one($sql, [$id]);

    if ($row === null) {
        return null;
    }

    $itemId = (string) $row['id'];
    $slug = (string) $row['slug'];
    $status = (string) $row['status'];
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);
    $labels = [];

    foreach (itemTags($itemId) as $tag) {
        $labels[] = (string) $tag['label'];
    }

    return [
        'id' => $itemId,
        'slug' => $slug,
        'title' => (string) $row['title'],
        'lead' => (string) ($row['lead_text'] ?? ''),
        'cover' => (string) ($row['cover'] ?? ''),
        'categoryId' => (string) $row['category_id'],
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryVisible' => (int) ($row['category_visible'] ?? 0) === 1,
        'bodyText' => bodyToFormText(paragraphsOf($row['body'] ?? null)),
        'tags' => implode(', ', $labels),
        'status' => $status,
        'statusLabel' => NEWS_STATUS_LABEL[$status] ?? $status,
        'publishedAt' => formPublishedAt($published),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'seoTitle' => (string) ($row['seo_title'] ?? ''),
        'seoDescription' => (string) ($row['seo_description'] ?? ''),
        'ogImage' => (string) ($row['og_image'] ?? ''),
        'noindex' => (int) ($row['noindex'] ?? 0) === 1,
        'oldSlugs' => oldSlugsOf($itemId),
        'authorEmail' => (string) ($row['author_email'] ?? ''),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        'href' => newsHref(['slug' => $slug]),
        'editHref' => NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($itemId),
    ];
}

// ── Срок, текст и сообщения ──────────────────────────────

// Опубликованная строка обязана иметь срок: без него её не показывает ни лента, ни карта сайта,
// а вкладка «Опубликованные» строится на наступившем сроке. Пустое поле при выпуске — «сейчас».
function publishDateOf(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now): ?DateTimeImmutable
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return $publishedAt;
    }

    return $publishedAt ?? $now;
}

/**
 * Что сказать человеку после сохранения: выпуск по сроку — «ждёт срока», выпуск без срока — «на сайте»,
 * черновик при создании — «сохранён», при правке — «снят с сайта». Ключ читает NEWS_NOTICE_TEXT,
 * а страница — noticeOfRequest() из core/notice.php.
 */
function publishNotice(string $status, ?DateTimeImmutable $publishedAt, DateTimeImmutable $now, bool $created): string
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return $created ? 'created' : 'unpublished';
    }

    return $publishedAt !== null && $publishedAt > $now ? 'scheduled' : 'published';
}

// Пустое значение поля — NULL в базе, а не пустая строка: карточка и метаданные различают «не задано»
// и «задано пусто», иначе seoOf() взял бы пустой Title вместо заголовка.
function orNull(string $value): ?string
{
    return $value === '' ? null : $value;
}

// Рубрику могли удалить из справочника, пока форма висела открытой: ответ обязан называть причину,
// а не падать на внешнем ключе. Публикация требует видимой рубрики: у скрытой карточке негде
// показаться, и кнопка списка такое правило уже держит (setItemStatus) — форма обязана сверить
// то же правило, иначе «На печать» облистывало б размещение рубрики.
function checkCategory(string $categoryId, bool $requireVisible = false): string
{
    if ($categoryId === '') {
        return 'Выберите рубрику: без неё материал не попадёт ни в один раздел.';
    }

    $category = one('SELECT title, visible FROM ' . t('news_category') . ' WHERE id = ?', [$categoryId]);

    if ($category === null) {
        return 'Рубрики, в которую вы кладёте материал, уже нет — выберите другую.';
    }

    if ($requireVisible && (int) $category['visible'] !== 1) {
        return sprintf('Рубрика «%s» скрыта: сначала покажите её в справочнике.', (string) $category['title']);
    }

    return '';
}

// Абзацы бывают и списком (форма), и строкой JSON (колонка базы): проверка одна для обоих видов,
// потому что оба читает paragraphsOf() из feed.php.
function hasParagraphs(mixed $body): bool
{
    return paragraphsOf($body) !== [];
}

/** То же правило для обоих путей: '' — на печать можно, иначе текст отказа. */
function publishTextError(string $status, mixed $body): string
{
    if ($status !== NEWS_STATUS['PUBLISHED']) {
        return '';
    }

    return hasParagraphs($body) ? '' : NEWS_PUBLISH_NEEDS_TEXT;
}

// Абзацы пишутся строкой JSON: колонка body — JSON, а не текст. Недопустимый UTF-8 до сюда не
// доходит (preg_replace с флагом /u возвращает null раньше, и поле становится пустым), поэтому
// отказ здесь — признак порчи данных: пишем пустой список и событие в журнал, а не роняем сохранение.
function bodyJson(mixed $paragraphs): string
{
    try {
        return json_encode(paragraphsOf($paragraphs), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        logWarn('news.body_encode_failed', ['message' => $error->getMessage()]);

        return '[]';
    }
}

/**
 * @param mixed $raw
 *
 * @return list<string>
 */
function tagLabelsOf(mixed $raw): array
{
    $out = [];

    foreach (is_array($raw) ? $raw : [] as $label) {
        if (is_string($label) || is_int($label)) {
            $out[] = (string) $label;
        }
    }

    return $out;
}

/**
 * Колонки материала из разобранных полей формы. Снимок автора обновляет только выпуск на печать:
 * создателя черновика правка менять не должна, а карточка остаётся читаемой и после удаления аккаунта.
 *
 * @param array<string, mixed> $input
 * @param array{id: string, email: string} $editor
 *
 * @return array<string, mixed>
 */
function dataOf(array $input, ?DateTimeImmutable $publishedAt, array $editor, bool $publishedNow, DateTimeImmutable $now): array
{
    $row = [
        'title' => (string) ($input['title'] ?? ''),
        'lead_text' => orNull((string) ($input['lead'] ?? '')),
        'cover' => orNull((string) ($input['cover'] ?? '')),
        'category_id' => (string) ($input['categoryId'] ?? ''),
        'body' => bodyJson($input['body'] ?? []),
        'status' => (string) ($input['status'] ?? NEWS_STATUS['DRAFT']),
        'published_at' => $publishedAt === null ? null : utcStamp($publishedAt),
        'pinned' => ($input['pinned'] ?? false) === true ? 1 : 0,
        'seo_title' => orNull((string) ($input['seoTitle'] ?? '')),
        'seo_description' => orNull((string) ($input['seoDescription'] ?? '')),
        'og_image' => orNull((string) ($input['ogImage'] ?? '')),
        'noindex' => ($input['noindex'] ?? false) === true ? 1 : 0,
        'updated_at' => utcStamp($now),
    ];

    if ($publishedNow) {
        $row['author_id'] = (string) $editor['id'];
        $row['author_email'] = (string) $editor['email'];
    }

    return $row;
}

/**
 * SET для правки материала: имена колонок приходят из dataOf(), а не из запроса, поэтому подстановка
 * имён безопасна — значения всегда уходят плейсхолдерами. Проверка имени та же, что в insert().
 *
 * @param array<string, mixed> $row
 *
 * @return list<string>
 */
function updateSet(array $row): array
{
    $parts = [];

    foreach (array_keys($row) as $column) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $column) !== 1) {
            throw new InvalidArgumentException('Недопустимое имя колонки: ' . (string) $column);
        }

        $parts[] = $column . ' = ?';
    }

    return $parts;
}

// ── Запись ───────────────────────────────────────────────

/**
 * Занят ли адрес. При правке собственная строка адрес не занимает, иначе сохранение без смены адреса
 * упало бы на коллизии с самим собой.
 */
function slugTaken(string $slug, string $exceptId = ''): bool
{
    if ($slug === '') {
        return false;
    }

    if ($exceptId === '') {
        return (int) value('SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE slug = ?', [$slug]) > 0;
    }

    return (int) value(
        'SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE slug = ? AND id <> ?',
        [$slug, $exceptId]
    ) > 0;
}

/**
 * Прежние адреса пишутся строками: tbl_news_old_slug заменяет JSON-массив из PostgreSQL, потому что
 * поиск по JSON без индекса сканировал бы таблицу на каждом открытии старого адреса. Список
 * перезаписывается целиком — он короткий (OLD_SLUGS_MAX), а предел срежется в двух местах:
 * rememberOldSlug() режет хвост при сборке списка, и запись в базу режет входящий массив, поэтому
 * за предел не уходит ни одна из двух веток.
 *
 * @param list<string> $slugs
 */
function saveOldSlugs(string $itemId, array $slugs): void
{
    run('DELETE FROM ' . t('news_old_slug') . ' WHERE item_id = ?', [$itemId]);

    foreach (array_slice($slugs, 0, OLD_SLUGS_MAX) as $slug) {
        if ($slug === '') {
            continue;
        }

        // Адрес мог раньше принадлежать другому материалу: PRIMARY KEY (slug) не даёт положить его
        // дважды, поэтому чужая строка убирается — перенаправление ведёт на свежий материал.
        run('DELETE FROM ' . t('news_old_slug') . ' WHERE slug = ?', [$slug]);
        insert('news_old_slug', ['slug' => $slug, 'item_id' => $itemId, 'created_at' => utcStamp(nowUtc())]);
    }
}

/**
 * Связи тегов перезаписываются целиком: список короткий (TAG_FIELDS['perItem']), а сравнение старого
 * и нового наборов стоило бы двух запросов вместо одного удаления.
 *
 * @param list<string> $tagIds
 */
function linkItemTags(string $itemId, array $tagIds): void
{
    run('DELETE FROM ' . t('news_item_tag') . ' WHERE item_id = ?', [$itemId]);

    foreach ($tagIds as $tagId) {
        insert('news_item_tag', ['item_id' => $itemId, 'tag_id' => $tagId]);
    }
}

/** @return array{ok: bool, notice: string, error: string, id: string, slug: string} */
function itemFailure(string $error): array
{
    return ['ok' => false, 'notice' => '', 'error' => $error, 'id' => '', 'slug' => ''];
}

/** @return array{ok: bool, notice: string, error: string, id: string, slug: string} */
function itemSuccess(string $notice, string $id, string $slug): array
{
    return ['ok' => true, 'notice' => $notice, 'error' => '', 'id' => $id, 'slug' => $slug];
}

/**
 * Строка материала для кнопок состояния: статус, срок, закрепление, рубрика и абзацы.
 *
 * @return array<string, mixed>|null
 */
function itemRow(string $id): ?array
{
    if ($id === '') {
        return null;
    }

    return one(
        sprintf(
            'SELECT id, slug, status, published_at, pinned, category_id, body FROM %s WHERE id = ?',
            t('news_item')
        ),
        [$id]
    );
}

/** Момент публикации из разобранных полей формы: значение может прийти и не объектом. */
function publishedAtOf(mixed $raw): ?DateTimeImmutable
{
    return $raw instanceof DateTimeImmutable ? $raw : null;
}

/**
 * Создание материала.
 *
 * @param array<string, mixed> $input значение ключа `data` из parseNewsForm()
 * @param array{id: string, email: string} $editor
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function createItem(array $input, array $editor, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $status = (string) ($input['status'] ?? NEWS_STATUS['DRAFT']);

    // На печать публикация идёт кнопкой формы и кнопкой списка — правило рубрики одно:
    // печатается материал только в видимой рубрике.
    $categoryError = checkCategory(
        (string) ($input['categoryId'] ?? ''),
        $status === NEWS_STATUS['PUBLISHED']
    );

    if ($categoryError !== '') {
        return itemFailure($categoryError);
    }

    // Кнопка «На печать» в форме идёт этим путём, а не кнопкой списка: без отказа здесь форма
    // выкладывала бы пустую карточку, хотя список такой материал не печатает.
    $textError = publishTextError($status, $input['body'] ?? []);

    if ($textError !== '') {
        return itemFailure($textError);
    }

    $plan = newsSlugPlan(
        (string) ($input['slug'] ?? ''),
        (string) ($input['title'] ?? ''),
        static fn (string $candidate): bool => slugTaken($candidate)
    );

    if ($plan['error'] !== '') {
        return itemFailure($plan['error']);
    }

    $id = newId();
    $slug = (string) $plan['slug'];
    $publishedAt = publishDateOf($status, publishedAtOf($input['publishedAt'] ?? null), $moment);
    $tagIds = resolveTagIds(tagLabelsOf($input['tags'] ?? []));
    $publishedNow = $status === NEWS_STATUS['PUBLISHED'];

    // Slug свободен в момент плана, но два одновременных сохранения могут претендовать на
    // один и тот же адрес: проигравшая вставка получает отказ уникального ключа. Человеку
    // возвращается причина с предложением, а не HTTP 500 сырого исключения.
    try {
        tx(static function () use ($id, $slug, $input, $publishedAt, $editor, $publishedNow, $moment, $tagIds): void {
            insert('news_item', array_merge(
                dataOf($input, $publishedAt, $editor, $publishedNow, $moment),
                ['id' => $id, 'slug' => $slug, 'created_at' => utcStamp($moment)]
            ));
            linkItemTags($id, $tagIds);
        });
    } catch (Throwable $error) {
        if (!duplicateKeyException($error)) {
            throw $error;
        }

        return itemFailure('Такой адрес уже занят другим материалом — предложите свой.');
    }

    logInfo('news.item_created', ['itemId' => $id, 'slug' => $slug, 'status' => $status]);

    return itemSuccess(publishNotice($status, $publishedAt, $moment, true), $id, $slug);
}

/**
 * Правка материала.
 *
 * @param array<string, mixed> $input значение ключа `data` из parseNewsForm()
 * @param array{id: string, email: string} $editor
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function updateItem(string $id, array $input, array $editor, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    $status = (string) ($input['status'] ?? NEWS_STATUS['DRAFT']);
    // Правило то же, что у создания и у кнопки списка: публикация только в видимой рубрике.
    $categoryError = checkCategory(
        (string) ($input['categoryId'] ?? ''),
        $status === NEWS_STATUS['PUBLISHED']
    );

    if ($categoryError !== '') {
        return itemFailure($categoryError);
    }

    // Правило то же, что у создания и у кнопки списка: выпуск пустого текста не выходит на сайт —
    // здесь он возможен, когда черновик дописали не до конца и нажали «На печать».
    $textError = publishTextError($status, $input['body'] ?? []);

    if ($textError !== '') {
        return itemFailure($textError);
    }

    $plan = newsSlugPlan(
        (string) ($input['slug'] ?? ''),
        (string) ($input['title'] ?? ''),
        static fn (string $candidate): bool => slugTaken($candidate, $id)
    );

    if ($plan['error'] !== '') {
        return itemFailure($plan['error']);
    }

    $slug = (string) $plan['slug'];
    $previous = (string) $row['slug'];
    $slugChanged = $slug !== $previous;
    $publishedAt = publishDateOf($status, publishedAtOf($input['publishedAt'] ?? null), $moment);
    $becamePublished = $status === NEWS_STATUS['PUBLISHED'] && (string) $row['status'] !== NEWS_STATUS['PUBLISHED'];
    $tagIds = resolveTagIds(tagLabelsOf($input['tags'] ?? []));

    // Прежний адрес уходит в список перенаправлений: ссылки из закладок и чужих статей обязаны
    // доходить до карточки, а не в 404 (§8.3).
    $oldSlugs = rememberOldSlug(oldSlugsOf($id), $previous, $slug);

    // Адрес свободен в момент плана, но правка и чужая публикация могут претендовать на
    // один и тот же slug — проигравший UPDATE получает отказ уникального ключа.
    try {
        tx(static function () use ($id, $slug, $input, $publishedAt, $editor, $becamePublished, $moment, $tagIds, $oldSlugs, $slugChanged): void {
            $data = array_merge(dataOf($input, $publishedAt, $editor, $becamePublished, $moment), ['slug' => $slug]);

            run(
                'UPDATE ' . t('news_item') . ' SET ' . implode(', ', updateSet($data)) . ' WHERE id = ?',
                array_merge(array_values($data), [$id])
            );
            linkItemTags($id, $tagIds);

            if ($slugChanged) {
                saveOldSlugs($id, $oldSlugs);
            }
        });
    } catch (Throwable $error) {
        if (!duplicateKeyException($error)) {
            throw $error;
        }

        return itemFailure('Такой адрес уже занят другим материалом — предложите свой.');
    }

    logInfo('news.item_updated', [
        'itemId' => $id,
        'from' => (string) $row['status'],
        'to' => $status,
        'slugChanged' => $slugChanged,
    ]);

    // Переход состояния важнее смены адреса: человек нажал «На печать» и ждёт подтверждения
    // выпуска, а не сообщения про адрес. Если же статус не менялся, смена адреса — главное
    // событие правки: редактор обязан узнать, что прежняя ссылка ушла в перенаправления.
    $notice = $status === (string) $row['status']
        ? ($slugChanged ? 'slugChanged' : 'updated')
        : publishNotice($status, $publishedAt, $moment, false);

    return itemSuccess($notice, $id, $slug);
}

/**
 * Кнопки состояния в списке: «На печать» и «Снять с сайта». Право на переход проверяет сервер,
 * а не наличие кнопки на экране.
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function setItemStatus(string $id, string $to, ?DateTimeImmutable $now = null): array
{
    if (!in_array($to, NEWS_STATUS, true)) {
        return itemFailure('Незнакомое состояние материала.');
    }

    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    if ((string) $row['status'] === $to) {
        return itemSuccess('unchanged', (string) $row['id'], (string) $row['slug']);
    }

    if ($to === NEWS_STATUS['PUBLISHED']) {
        // На печать материал выходит текстом и в видимой рубрике: без абзацев карточка пустая,
        // а в скрытой рубрике её негде показать. Тот же отказ отдаёт и форма — publishTextError().
        $textError = publishTextError($to, $row['body'] ?? null);

        if ($textError !== '') {
            return itemFailure($textError);
        }

        $category = one('SELECT title, visible FROM ' . t('news_category') . ' WHERE id = ?', [(string) $row['category_id']]);

        if ($category === null) {
            return itemFailure('Рубрики материала больше нет — выберите другую в форме.');
        }

        if ((int) $category['visible'] !== 1) {
            return itemFailure(sprintf(
                'Рубрика «%s» скрыта: сначала покажите её в справочнике.',
                (string) $category['title']
            ));
        }
    }

    // Снятый с сайта материал срок не теряет: статус возвращается в черновик, а дата остаётся —
    // редактор выпустит его той же кнопкой, не выбирая момент заново.
    $publishedAt = publishDateOf($to, fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null), $moment);

    run(
        sprintf('UPDATE %s SET status = ?, published_at = ?, updated_at = ? WHERE id = ?', t('news_item')),
        [$to, $publishedAt === null ? null : utcStamp($publishedAt), utcStamp($moment), (string) $row['id']]
    );

    logInfo('news.item_status', ['itemId' => (string) $row['id'], 'from' => (string) $row['status'], 'to' => $to]);

    return itemSuccess(publishNotice($to, $publishedAt, $moment, false), (string) $row['id'], (string) $row['slug']);
}

/**
 * Закрепление: первая страница ленты, страницы рубрики и «похожие» идут по orderNews(), где
 * закреплённые впереди. Черновику закрепление бессмысленно — его в ленте нет.
 *
 * @return array{ok: bool, notice: string, error: string, id: string, slug: string}
 */
function setItemPinned(string $id, bool $pinned, ?DateTimeImmutable $now = null): array
{
    $moment = $now ?? nowUtc();
    $row = itemRow($id);

    if ($row === null) {
        return itemFailure('Материала уже нет — обновите страницу.');
    }

    if (((int) ($row['pinned'] ?? 0) === 1) === $pinned) {
        return itemSuccess('unchanged', (string) $row['id'], (string) $row['slug']);
    }

    if ($pinned && !isOpen($row, $moment)) {
        return itemFailure('Закреплять можно материал, который уже на сайте: в ленте он идёт первым, а черновика там нет.');
    }

    run(
        sprintf('UPDATE %s SET pinned = ?, updated_at = ? WHERE id = ?', t('news_item')),
        [$pinned ? 1 : 0, utcStamp($moment), (string) $row['id']]
    );

    logInfo('news.item_pinned', ['itemId' => (string) $row['id'], 'pinned' => $pinned]);

    return itemSuccess($pinned ? 'pinned' : 'unpinned', (string) $row['id'], (string) $row['slug']);
}
