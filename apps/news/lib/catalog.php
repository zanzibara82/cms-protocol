<?php

declare(strict_types=1);

// Справочники раздела «Новости». Рубрики правит контент-менеджер, теги рождаются из формы
// материала. Скрытая рубрика не открывается вовсе, а не отвечает пустым списком: пустая
// страница выглядела бы как рубрика, которую стоит искать.
//
// Совпадения строк ищутся без приведения регистра: схема раздела собрана на utf8mb4_unicode_ci,
// где «Методика» и «МЕТОДИКА» — одно значение.

// ── Рубрики ──────────────────────────────────────────────

/** @return array{id: string, slug: string, title: string, description: ?string}|null */
function findCategory(string $slug): ?array
{
    $row = one(
        'SELECT id, slug, title, description FROM ' . t('news_category') . ' WHERE slug = ? AND visible = 1',
        [$slug]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'description' => $row['description'] === null ? null : (string) $row['description'],
    ];
}

/** Идентификатор рубрики по адресу; пустая строка — рубрики нет. */
function categoryIdOf(string $slug): string
{
    $id = value('SELECT id FROM ' . t('news_category') . ' WHERE slug = ?', [$slug]);

    return $id === null ? '' : (string) $id;
}

/** @return list<array{slug: string, title: string}> */
function visibleCategories(): array
{
    $rows = all('SELECT slug, title FROM ' . t('news_category') . ' WHERE visible = 1 ORDER BY order_index, title');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['slug' => (string) $row['slug'], 'title' => (string) $row['title']];
    }

    return $out;
}

// Форма материала предлагает и скрытые рубрики: материал в них лежит, и редактор обязан видеть,
// куда он положен, иначе правка молча переложила бы материал в первую видимую.
/** @return list<array{id: string, title: string, visible: bool}> */
function categoryOptions(): array
{
    $rows = all('SELECT id, title, visible FROM ' . t('news_category') . ' ORDER BY order_index, title');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'visible' => (bool) $row['visible']];
    }

    return $out;
}

// Название — единственное, из чего складывается адрес рубрики, поэтому проверяем и его, и сам
// адрес: «!!!» превратилось бы в пустой slug, а пустое описание не должно ложиться в базу.
/** @return array{title: string, slugBase: string, description: ?string, error: string} */
function checkCategoryTitle(string $rawTitle, string $rawDescription = ''): array
{
    $min = fieldLimit(CATEGORY_FIELDS, 'title', 'min');
    $title = mb_substr(collapseSpace($rawTitle), 0, fieldLimit(CATEGORY_FIELDS, 'title', 'max'));

    if (mb_strlen($title) < $min) {
        return ['title' => '', 'slugBase' => '', 'description' => null, 'error' => 'Название короче ' . $min . ' символов — напишите его целиком.'];
    }

    $slugBase = slugify($title);
    if ($slugBase === '') {
        return [
            'title' => $title,
            'slugBase' => '',
            'description' => null,
            'error' => 'Название должно содержать буквы или цифры: из него строится адрес рубрики.',
        ];
    }

    $limit = fieldLimit(CATEGORY_FIELDS, 'description', 'max');
    $text = mb_substr(collapseSpace($rawDescription), 0, $limit);

    return ['title' => $title, 'slugBase' => $slugBase, 'description' => $text === '' ? null : $text, 'error' => ''];
}

// Пробелы и переносы в названии и описании схлопываются: человек вставляет текст из письма,
// а рубрика в списке и на странице читается одной строкой.
function collapseSpace(string $raw): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $raw));
}

function categoryExists(string $id): bool
{
    if ($id === '') {
        return false;
    }

    return (int) value('SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE id = ?', [$id]) > 0;
}

// Название рубрики занято, если такая строка уже есть и это не та же самая рубрика: сравнение ведёт
// коллация, поэтому «Методика» и «МЕТОДИКА» — одно название.
function categoryTitleTaken(string $title, string $exceptId = ''): bool
{
    $row = one('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', [$title]);

    return $row !== null && (string) $row['id'] !== $exceptId;
}

// Список показывает и невидимые материалы: рубрика с двадцатью черновиками выглядит живой
// в админке и пустой на сайте, и расхождение между счётчиками человек читал бы как поломку.
/**
 * @return list<array{
 *     id: string, slug: string, title: string, description: ?string, order: int, visible: bool,
 *     items: array{total: int, visible: int}
 * }>
 */
function adminCategoryRows(): array
{
    $sql = sprintf(
        'SELECT c.id, c.slug, c.title, c.description, c.order_index, c.visible,'
            . ' COUNT(i.id) AS total,'
            . " SUM(CASE WHEN i.status = '%s' AND i.published_at IS NOT NULL AND i.published_at <= ? THEN 1 ELSE 0 END) AS opened"
            . ' FROM %s c LEFT JOIN %s i ON i.category_id = c.id'
            . ' GROUP BY c.id, c.slug, c.title, c.description, c.order_index, c.visible'
            . ' ORDER BY c.order_index, c.title',
        NEWS_STATUS['PUBLISHED'],
        t('news_category'),
        t('news_item')
    );

    $rows = all($sql, [utcStamp(nowUtc())]);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'order' => (int) $row['order_index'],
            'visible' => (bool) $row['visible'],
            'items' => ['total' => (int) $row['total'], 'visible' => (int) $row['opened']],
        ];
    }

    return $out;
}

/** @return array{ok: bool, notice: string, error: string} */
function createCategory(string $rawTitle, string $rawDescription = ''): array
{
    $checked = checkCategoryTitle($rawTitle, $rawDescription);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    $slug = ensureUniqueSlug(
        $checked['slugBase'],
        static fn (string $candidate): bool => (int) value('SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE slug = ?', [$candidate]) > 0,
        'rubrika'
    );

    $last = (int) value('SELECT COALESCE(MAX(order_index), 0) FROM ' . t('news_category'));
    $id = newId();
    $stamp = utcStamp(nowUtc());

    run(
        'INSERT INTO ' . t('news_category')
            . ' (id, slug, title, description, order_index, visible, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
        [$id, $slug, $checked['title'], $checked['description'], $last + 1, $stamp, $stamp]
    );

    logInfo('news.category_created', ['categoryId' => $id, 'slug' => $slug]);

    return ['ok' => true, 'notice' => 'created', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function renameCategory(string $id, string $rawTitle): array
{
    $row = one('SELECT id, title FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $checked = checkCategoryTitle($rawTitle);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'], (string) $row['id'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    run(
        'UPDATE ' . t('news_category') . ' SET title = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_renamed', [
        'categoryId' => (string) $row['id'],
        'from' => (string) $row['title'],
        'to' => $checked['title'],
    ]);

    // Адрес остаётся прежним: он один раз попал в ссылки, карточки и карту сайтов.
    return ['ok' => true, 'notice' => 'renamed', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function updateCategoryDescription(string $id, string $rawTitle, string $rawDescription): array
{
    $row = one('SELECT id FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $checked = checkCategoryTitle($rawTitle, $rawDescription);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }
    if (categoryTitleTaken($checked['title'], (string) $row['id'])) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрика с таким названием уже есть.'];
    }

    run(
        'UPDATE ' . t('news_category') . ' SET title = ?, description = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], $checked['description'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_described', ['categoryId' => (string) $row['id']]);

    return ['ok' => true, 'notice' => 'described', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function toggleCategoryVisibility(string $id): array
{
    $row = one('SELECT id, visible FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $visible = !((bool) $row['visible']);
    run(
        'UPDATE ' . t('news_category') . ' SET visible = ?, updated_at = ? WHERE id = ?',
        [$visible ? 1 : 0, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.category_visibility', ['categoryId' => (string) $row['id'], 'visible' => $visible]);

    return ['ok' => true, 'notice' => $visible ? 'shown' : 'hidden', 'error' => ''];
}

// Рубрика с материалами не удаляется, а отказ называет причину: внешний ключ RESTRICT всё равно
// не дал бы удалить строку, но человек получил бы вместо текста ошибку базы.
/** @return array{ok: bool, notice: string, error: string} */
function deleteCategory(string $id): array
{
    $row = one('SELECT id, title FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $count = (int) value('SELECT COUNT(*) FROM ' . t('news_item') . ' WHERE category_id = ?', [$id]);
    if ($count > 0) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'В рубрике ' . $count . ' ' . plural($count, ['материал', 'материала', 'материалов'])
                . '. Удалить её нельзя — переведите новости в другую рубрику или скройте: материалы останутся у сайта,'
                . ' а с ленты уйдут.',
        ];
    }

    run('DELETE FROM ' . t('news_category') . ' WHERE id = ?', [$id]);
    logInfo('news.category_deleted', ['categoryId' => $id, 'title' => (string) $row['title']]);

    return ['ok' => true, 'notice' => 'deleted', 'error' => ''];
}

// Порядок пересобирается целиком: так исправляются и старые дубликаты order_index, которые
// могли остаться от ручной правки базы.
/** @return array{ok: bool, notice: string, error: string} */
function moveCategory(string $id, string $direction): array
{
    $rows = all('SELECT id FROM ' . t('news_category') . ' ORDER BY order_index, title');
    $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
    $index = array_search($id, $ids, true);

    if ($index === false) {
        return ['ok' => false, 'notice' => '', 'error' => 'Рубрики уже нет — обновите страницу.'];
    }

    $target = $direction === 'up' ? $index - 1 : $index + 1;
    if ($target < 0 || $target >= count($ids)) {
        return ['ok' => true, 'notice' => $direction === 'up' ? 'topped' : 'bottomed', 'error' => ''];
    }

    $next = $ids;
    $next[$index] = $ids[$target];
    $next[$target] = $ids[$index];

    $stamp = utcStamp(nowUtc());
    tx(static function () use ($next, $stamp): void {
        foreach ($next as $position => $categoryId) {
            run(
                'UPDATE ' . t('news_category') . ' SET order_index = ?, updated_at = ? WHERE id = ?',
                [$position + 1, $stamp, $categoryId]
            );
        }
    });

    logInfo('news.category_moved', ['categoryId' => $id, 'direction' => $direction]);

    return ['ok' => true, 'notice' => 'moved', 'error' => ''];
}

// ── Теги ─────────────────────────────────────────────────

// Тегу адрес транслитерируется, русская надпись остаётся в `label`.
function tagSlugBase(string $label): string
{
    return slugify(mb_substr(collapseSpace($label), 0, fieldLimit(TAG_FIELDS, 'label', 'max')));
}

/** @return array{id: string, slug: string, label: string}|null */
function findTag(string $slug): ?array
{
    $row = one('SELECT id, slug, label FROM ' . t('news_tag') . ' WHERE slug = ?', [$slug]);

    if ($row === null) {
        return null;
    }

    return ['id' => (string) $row['id'], 'slug' => (string) $row['slug'], 'label' => (string) $row['label']];
}

/** @return list<array{id: string, slug: string, label: string}> */
function visibleTags(): array
{
    $rows = all('SELECT id, slug, label FROM ' . t('news_tag') . ' ORDER BY label');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (string) $row['id'], 'slug' => (string) $row['slug'], 'label' => (string) $row['label']];
    }

    return $out;
}

/** @return list<array{slug: string, label: string}> */
function tagOptions(): array
{
    $rows = all('SELECT slug, label FROM ' . t('news_tag') . ' ORDER BY label');
    $out = [];
    foreach ($rows as $row) {
        $out[] = ['slug' => (string) $row['slug'], 'label' => (string) $row['label']];
    }

    return $out;
}

// Счётчик материалов тега читает все строки, а не только видимые: снятый с публикации материал
// всё ещё держит тег, и удалить его справочник не даст — иначе удаление упало бы на внешнем ключе.
function tagCountOf(string $id): int
{
    if ($id === '') {
        return 0;
    }

    return (int) value('SELECT COUNT(*) FROM ' . t('news_item_tag') . ' WHERE tag_id = ?', [$id]);
}

/** @return list<array{id: string, slug: string, label: string, items: int}> */
function adminTagRows(): array
{
    $sql = sprintf(
        'SELECT tg.id, tg.slug, tg.label, COUNT(link.tag_id) AS items'
            . ' FROM %s tg LEFT JOIN %s link ON link.tag_id = tg.id'
            . ' GROUP BY tg.id, tg.slug, tg.label'
            . ' ORDER BY tg.label',
        t('news_tag'),
        t('news_item_tag')
    );

    $rows = all($sql);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'label' => (string) $row['label'],
            'items' => (int) $row['items'],
        ];
    }

    return $out;
}

// Теги заводит форма материала: новый тег не появляется, если надпись пустая или её уже выбрали
// в этом же списке. Возвращаются идентификаторы — их пишет связь news_item_tag.
/**
 * @param list<string> $labels
 *
 * @return list<string>
 */
function resolveTagIds(array $labels): array
{
    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $max = fieldLimit(TAG_FIELDS, 'label', 'max');
    $ids = [];

    foreach ($labels as $label) {
        // Надпись короче предела не становится тегом: «а» собрало бы вокруг себя все опечатки.
        $clean = mb_substr(collapseSpace((string) $label), 0, $max);
        if (mb_strlen($clean) < $min) {
            continue;
        }

        $existing = one('SELECT id FROM ' . t('news_tag') . ' WHERE label = ?', [$clean]);
        if ($existing !== null) {
            $ids[] = (string) $existing['id'];

            continue;
        }

        $slug = ensureUniqueSlug(
            tagSlugBase($clean),
            static fn (string $candidate): bool => (int) value('SELECT COUNT(*) FROM ' . t('news_tag') . ' WHERE slug = ?', [$candidate]) > 0,
            'tag'
        );

        $id = newId();
        run('INSERT INTO ' . t('news_tag') . ' (id, slug, label) VALUES (?, ?, ?)', [$id, $slug, $clean]);
        logInfo('news.tag_created', ['tagId' => $id, 'slug' => $slug, 'label' => $clean]);
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/** @return array{ok: bool, notice: string, error: string} */
function renameTag(string $id, string $rawLabel): array
{
    $row = one('SELECT id, label FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тега уже нет — обновите страницу.'];
    }

    $min = fieldLimit(TAG_FIELDS, 'label', 'min');
    $label = mb_substr(collapseSpace($rawLabel), 0, fieldLimit(TAG_FIELDS, 'label', 'max'));
    if (mb_strlen($label) < $min) {
        return ['ok' => false, 'notice' => '', 'error' => 'Надпись короче ' . $min . ' символов.'];
    }

    $taken = one('SELECT id FROM ' . t('news_tag') . ' WHERE label = ?', [$label]);
    if ($taken !== null && (string) $taken['id'] !== (string) $row['id']) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тег с такой надписью уже есть.'];
    }

    run('UPDATE ' . t('news_tag') . ' SET label = ? WHERE id = ?', [$label, (string) $row['id']]);
    logInfo('news.tag_renamed', ['tagId' => (string) $row['id'], 'from' => (string) $row['label'], 'to' => $label]);

    // Адрес тега остаётся прежним: подборка /news/t/… уже попала в ссылки карточек.
    return ['ok' => true, 'notice' => 'tagRenamed', 'error' => ''];
}

// Тег без материалов удаляется: висящая ссылка /news/t/… на пустую подборку бесполезна.
/** @return array{ok: bool, notice: string, error: string} */
function deleteTag(string $id): array
{
    $row = one('SELECT id, label FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Тега уже нет — обновите страницу.'];
    }

    $count = tagCountOf($id);
    if ($count > 0) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'Тег стоит на ' . $count . ' ' . plural($count, ['материале', 'материалах', 'материалах'])
                . ' — сначала снимите его в форме новости.',
        ];
    }

    run('DELETE FROM ' . t('news_tag') . ' WHERE id = ?', [$id]);
    logInfo('news.tag_deleted', ['tagId' => $id, 'label' => (string) $row['label']]);

    return ['ok' => true, 'notice' => 'tagDeleted', 'error' => ''];
}
