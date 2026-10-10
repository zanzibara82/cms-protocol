<?php

declare(strict_types=1);

// Публичное чтение раздела: лента, карточка, страницы рубрики и тега, «похожие» материалы,
// прежние адреса и набор адресов для карты сайта. Порт english-portal/src/apps/news/lib/feed.ts
// без блочных функций (отклонение 6).

// Сколько номеров страниц печатает пагинатор: первая, последняя и соседние текущей.
// Число живёт здесь, а не в constants.php: это правило вывода ленты, а не предел поля формы.
const NEWS_PAGER_WINDOW = 7;

// Колонки карточки — явный список. SELECT * притащил бы в ленту JSON каждого материала:
// страница из 12 карточек читала бы 12 полных текстов, которые на ней не печатаются.
const NEWS_CARD_COLUMNS = 'i.id, i.slug, i.title, i.lead_text, i.cover, i.pinned, i.status, i.published_at,'
    . ' c.slug AS category_slug, c.title AS category_title';

// К колонкам карточки добавляется то, что читает страница материала: абзацы, метаданные для
// поисковика, снимок автора и момент последней правки. Теги — отдельным запросом (itemTags()).
const NEWS_FULL_COLUMNS = NEWS_CARD_COLUMNS
    . ', i.body, i.seo_title, i.seo_description, i.og_image, i.noindex,'
    . ' i.author_email, i.category_id, i.updated_at';

/**
 * Число страниц ленты. Пустая лента — одна страница, а не ноль: пагинатор обязан печатать
 * «страница 1 из 1», иначе человек увидел бы пустой список без объяснения.
 */
function pageCount(int $total, int $perPage = NEWS_PER_PAGE): int
{
    if ($perPage <= 0) {
        return 1;
    }

    return max(1, (int) ceil($total / $perPage));
}

function clampPage(int $page, int $pages): int
{
    return $page < 1 ? 1 : min($page, max(1, $pages));
}

/**
 * Номера страниц для пагинатора: не больше NEWS_PAGER_WINDOW штук, всегда с первой и последней.
 * У края ленты окно растёт в середину — иначе на первой странице человек увидел бы три номера.
 *
 * @return list<int>
 */
function pageWindow(int $current, int $pages): array
{
    $total = max(1, $pages);

    if ($total <= NEWS_PAGER_WINDOW) {
        return range(1, $total);
    }

    $radius = intdiv(NEWS_PAGER_WINDOW - 2, 2);
    $start = max(2, min($current, $total) - $radius);
    $end = min($total - 1, max(1, $current) + $radius);

    if ($start === 2) {
        $end = min($total - 1, NEWS_PAGER_WINDOW - 1);
    }

    if ($end === $total - 1) {
        $start = max(2, $total - (NEWS_PAGER_WINDOW - 2));
    }

    return array_merge([1], range($start, $end), [$total]);
}

/** Сколько материалов просить у «похожих» и блоков вывода: не меньше одного, не больше страницы. */
function takeLimit(int $take): int
{
    return max(1, min($take, NEWS_PER_PAGE));
}

/**
 * Адрес страницы раздела. Один сегмент решает, что это: материал, рубрика, тег или сама лента.
 * Значения кодируются — адрес приходит из базы, а не из кода.
 *
 * @param array{slug?: string, category?: string, tag?: string} $target
 */
function newsHref(array $target = []): string
{
    $slug = trim((string) ($target['slug'] ?? ''));

    if ($slug !== '') {
        return '/news/' . rawurlencode($slug);
    }

    $category = trim((string) ($target['category'] ?? ''));

    if ($category !== '') {
        return '/news/c/' . rawurlencode($category);
    }

    $tag = trim((string) ($target['tag'] ?? ''));

    if ($tag !== '') {
        return '/news/t/' . rawurlencode($tag);
    }

    return '/news';
}

/**
 * Адрес ленты с поиском и номером страницы. Первая страница в адрес не попадает: `?page=1`
 * дублировал бы чистую ссылку, и поисковик увидел бы два одинаковых адреса.
 *
 * @param array{q?: string, page?: int} $query
 */
function feedHref(string $path, array $query = []): string
{
    $parts = [];
    $text = parseQuery($query['q'] ?? null);

    if ($text !== '') {
        $parts[] = 'q=' . rawurlencode($text);
    }

    $page = (int) ($query['page'] ?? 1);

    if ($page > 1) {
        $parts[] = 'page=' . $page;
    }

    return $parts === [] ? $path : $path . '?' . implode('&', $parts);
}

/** Страница, на которой живёт лента: рубрика, тег или общая лента. */
function feedBase(string $category, string $tag): string
{
    if ($tag !== '') {
        return newsHref(['tag' => $tag]);
    }

    if ($category !== '') {
        return newsHref(['category' => $category]);
    }

    return newsHref();
}

/** FROM для одного материала: рубрика нужна и в карточке, и в «похожих». */
function newsItemFrom(): string
{
    return sprintf('%s i JOIN %s c ON c.id = i.category_id', t('news_item'), t('news_category'));
}

/**
 * FROM, WHERE и аргументы публичной выборки.
 *
 * Соединение с тегами добавляется только под фильтр по тегу: постоянное соединение повторило бы
 * материал по числу его тегов в общей ленте.
 *
 * @param array{q?: string, category?: string, tag?: string, now?: DateTimeImmutable} $filter
 * @return array{
 *     from: string, where: string, args: list<string>, now: DateTimeImmutable,
 *     q: string, category: string, tag: string
 * }
 */
function feedScope(array $filter = []): array
{
    $now = $filter['now'] ?? nowUtc();
    $category = trim((string) ($filter['category'] ?? ''));
    $tag = trim((string) ($filter['tag'] ?? ''));
    $query = parseQuery($filter['q'] ?? null);

    $from = $tag === ''
        ? newsItemFrom()
        : sprintf(
            '%s JOIN %s link ON link.item_id = i.id JOIN %s tg ON tg.id = link.tag_id',
            newsItemFrom(),
            t('news_item_tag'),
            t('news_tag')
        );

    // Рубрика скрыта — её нет в ленте целиком, поэтому условие стоит вне ветки фильтра:
    // иначе общая лента («все рубрики») показывала бы материалы скрытой рубрики.
    $parts = [visibleWhere(), 'c.visible = 1'];
    $args = visibleArgs($now);

    if ($category !== '') {
        $parts[] = 'c.slug = ?';
        $args[] = $category;
    }

    if ($tag !== '') {
        $parts[] = 'tg.slug = ?';
        $args[] = $tag;
    }

    if ($query !== '') {
        $like = '%' . escapeLike($query) . '%';
        $parts[] = '(i.title LIKE ? OR i.lead_text LIKE ?)';
        $args[] = $like;
        $args[] = $like;
    }

    return [
        'from' => $from,
        'where' => implode(' AND ', $parts),
        'args' => $args,
        'now' => $now,
        'q' => $query,
        'category' => $category,
        'tag' => $tag,
    ];
}

/**
 * Лента раздела: карточки страницы, общее число, страницы и окно номеров.
 *
 * @param array{q?: string, category?: string, tag?: string, now?: DateTimeImmutable} $filter
 * @return array{
 *     items: list<array<string, mixed>>, total: int, pages: int, current: int, window: list<int>,
 *     q: string, category: string, tag: string, base: string, perPage: int
 * }
 */
function findFeed(array $filter = [], int $page = 1): array
{
    $scope = feedScope($filter);

    $total = (int) value(
        sprintf('SELECT COUNT(*) FROM %s WHERE %s', $scope['from'], $scope['where']),
        $scope['args']
    );

    $pages = pageCount($total);
    $current = clampPage($page, $pages);

    // LIMIT и OFFSET подставляются числами через %d: оба пришли из кода, а не из запроса.
    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
        NEWS_CARD_COLUMNS,
        $scope['from'],
        $scope['where'],
        orderNews(),
        NEWS_PER_PAGE,
        ($current - 1) * NEWS_PER_PAGE
    );

    $items = [];

    foreach (all($sql, $scope['args']) as $row) {
        $items[] = toCard($row, $scope['now']);
    }

    return [
        'items' => $items,
        'total' => $total,
        'pages' => $pages,
        'current' => $current,
        'window' => pageWindow($current, $pages),
        'q' => $scope['q'],
        'category' => $scope['category'],
        'tag' => $scope['tag'],
        'base' => feedBase($scope['category'], $scope['tag']),
        'perPage' => NEWS_PER_PAGE,
    ];
}

/**
 * Карточка ленты. Момент публикации приводится к московскому времени сразу: шаблон не имеет
 * права сам решать, в каком времени печатать (страж DateUsageTest не пускает туда date() и format()).
 *
 * @param array<string, mixed> $row
 * @return array{
 *     id: string, slug: string, title: string, lead: string, cover: string, pinned: bool,
 *     open: bool, status: string, href: string, publishedAt: DateTimeImmutable|null, date: string,
 *     categorySlug: string, categoryTitle: string, categoryHref: string
 * }
 */
function toCard(array $row, DateTimeImmutable $now): array
{
    $published = fromDb(is_string($row['published_at'] ?? null) ? (string) $row['published_at'] : null);
    $slug = (string) $row['slug'];
    $categorySlug = (string) ($row['category_slug'] ?? '');

    return [
        'id' => (string) $row['id'],
        'slug' => $slug,
        'title' => (string) $row['title'],
        'lead' => (string) ($row['lead_text'] ?? ''),
        'cover' => (string) ($row['cover'] ?? ''),
        'pinned' => (int) ($row['pinned'] ?? 0) === 1,
        'open' => isOpen($row, $now),
        'status' => (string) ($row['status'] ?? NEWS_STATUS['DRAFT']),
        'href' => newsHref(['slug' => $slug]),
        'publishedAt' => $published,
        'date' => formatRuDate($published),
        'categorySlug' => $categorySlug,
        'categoryTitle' => (string) ($row['category_title'] ?? ''),
        'categoryHref' => newsHref(['category' => $categorySlug]),
    ];
}

/**
 * Материал целиком — для страницы материала и её метаданных.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function toFull(array $row, DateTimeImmutable $now): array
{
    $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

    return array_merge(toCard($row, $now), [
        'paragraphs' => paragraphsOf($row['body'] ?? null),
        'seoTitle' => (string) ($row['seo_title'] ?? ''),
        'seoDescription' => (string) ($row['seo_description'] ?? ''),
        'ogImage' => (string) ($row['og_image'] ?? ''),
        'noindex' => (int) ($row['noindex'] ?? 0) === 1,
        'authorEmail' => (string) ($row['author_email'] ?? ''),
        'categoryId' => (string) ($row['category_id'] ?? ''),
        'updatedAt' => $updated,
        'updated' => formatRuDateTime($updated),
        'tags' => itemTags((string) $row['id']),
    ]);
}

/**
 * Абзацы материала. Колонка JSON приходит строкой — MySQL отдаёт JSON текстом, а не массивом.
 *
 * Нечитаемое тело возвращается пустым списком: страница материала обязана напечататься без
 * абзацев, а не ответить ошибкой 500 из-за одной испорченной строки.
 *
 * @return list<array{title: string, text: string}>
 */
function paragraphsOf(mixed $body): array
{
    if (!is_array($body)) {
        $raw = is_string($body) ? trim($body) : '';

        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
    } else {
        $decoded = $body;
    }

    $out = [];

    foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [] as $paragraph) {
        if (!is_array($paragraph)) {
            continue;
        }

        $title = trim((string) ($paragraph['title'] ?? ''));
        $text = trim((string) ($paragraph['text'] ?? ''));

        if ($title === '' && $text === '') {
            continue;
        }

        $out[] = ['title' => $title, 'text' => $text];
    }

    return $out;
}

/**
 * Теги материала — отдельным запросом: в ленте они не нужны, а на странице материала читаются.
 *
 * @return list<array{slug: string, label: string, href: string}>
 */
function itemTags(string $itemId): array
{
    $sql = sprintf(
        'SELECT tg.slug, tg.label FROM %s link JOIN %s tg ON tg.id = link.tag_id'
            . ' WHERE link.item_id = ? ORDER BY tg.label',
        t('news_item_tag'),
        t('news_tag')
    );

    $out = [];

    foreach (all($sql, [$itemId]) as $row) {
        $slug = (string) $row['slug'];
        $out[] = ['slug' => $slug, 'label' => (string) $row['label'], 'href' => newsHref(['tag' => $slug])];
    }

    return $out;
}

/**
 * Открытый материал по адресу — для страницы сайта.
 *
 * @return array<string, mixed>|null
 */
function findPublished(string $slug, DateTimeImmutable $now): ?array
{
    $sql = sprintf(
        'SELECT %s FROM %s WHERE i.slug = ? AND %s',
        NEWS_FULL_COLUMNS,
        newsItemFrom(),
        visibleWhere()
    );

    $row = one($sql, array_merge([$slug], visibleArgs($now)));

    return $row === null ? null : toFull($row, $now);
}

/**
 * Материал по адресу без проверки видимости — для формы редактора и предпросмотра `?draft=1`.
 * Публичная страница его не вызывает: там работает findPublished().
 *
 * @return array<string, mixed>|null
 */
function findAny(string $slug): ?array
{
    $sql = sprintf('SELECT %s FROM %s WHERE i.slug = ?', NEWS_FULL_COLUMNS, newsItemFrom());
    $row = one($sql, [$slug]);

    return $row === null ? null : toFull($row, nowUtc());
}

/**
 * Материал по прежнему адресу — для постоянного перенаправления (§8.3). Возвращает материал
 * целиком: маршрут сам решает, открыт ли он сейчас, и добавляет `?draft=1`, если нет.
 *
 * @return array<string, mixed>|null
 */
function findByOldSlug(string $slug): ?array
{
    $sql = sprintf(
        'SELECT %s FROM %s JOIN %s prev ON prev.item_id = i.id WHERE prev.slug = ?',
        NEWS_FULL_COLUMNS,
        newsItemFrom(),
        t('news_old_slug')
    );

    $row = one($sql, [$slug]);

    return $row === null ? null : toFull($row, nowUtc());
}

/**
 * Карточки последней выборки рубрики — общее тело `inCategory()` и `relatedLinks()`.
 *
 * Скелет запроса печатается один раз: `visibleWhere()` и `orderNews()` попадают в обе публичные
 * выборки из одного места, поэтому правка условия видимости не может оставить одну выборку
 * обновлённой, а вторую — нет (§8.2). Исключение материала — отдельный кусок `WHERE`, который
 * собирается из литерала и добавляется плейсхолдером, а не второй копией запроса.
 *
 * @return list<array<string, mixed>>
 */
function categoryFeedRows(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take): array
{
    $exclude = $excludeSlug === '' ? '' : ' AND i.slug <> ?';

    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s AND c.visible = 1 AND c.slug = ?%s ORDER BY %s LIMIT %d',
        NEWS_CARD_COLUMNS,
        newsItemFrom(),
        visibleWhere(),
        $exclude,
        orderNews(),
        takeLimit($take)
    );

    $args = array_merge(visibleArgs($now), [$categorySlug]);

    if ($excludeSlug !== '') {
        $args[] = $excludeSlug;
    }

    $items = [];

    foreach (all($sql, $args) as $row) {
        $items[] = toCard($row, $now);
    }

    return $items;
}

/**
 * Последние материалы рубрики — блоки вывода и «похожие» под карточкой.
 *
 * @return list<array<string, mixed>>
 */
function inCategory(string $categorySlug, DateTimeImmutable $now, int $take = 5): array
{
    return categoryFeedRows($categorySlug, '', $now, $take);
}

/**
 * То же, что inCategory(), но без самого материала: под карточкой «Итоги года» ссылка на себя
 * не нужна.
 *
 * @return list<array<string, mixed>>
 */
function relatedLinks(string $categorySlug, string $excludeSlug, DateTimeImmutable $now, int $take = 5): array
{
    return categoryFeedRows($categorySlug, $excludeSlug, $now, $take);
}

/**
 * Адреса материалов для карты сайта (§8.6): открытые, без запрета индексации, свежие первыми.
 * `lastmod` — московская дата последней правки, как её видит читатель.
 *
 * @return list<array{loc: string, lastmod?: string}>
 */
function publishedForSitemap(int $limit = 2000): array
{
    $sql = sprintf(
        'SELECT i.slug, i.updated_at FROM %s i WHERE %s AND i.noindex = 0'
            . ' ORDER BY i.updated_at DESC LIMIT %d',
        t('news_item'),
        visibleWhere(),
        max(1, min($limit, 5000))
    );

    $out = [];

    foreach (all($sql, visibleArgs(nowUtc())) as $row) {
        /** @var array{loc: string, lastmod?: string} $entry */
        $entry = ['loc' => newsHref(['slug' => (string) $row['slug']])];
        $updated = fromDb(is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null);

        if ($updated !== null) {
            $entry['lastmod'] = substr(moscowIso($updated), 0, 10);
        }

        $out[] = $entry;
    }

    return $out;
}
