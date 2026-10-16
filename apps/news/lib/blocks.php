<?php

declare(strict_types=1);

// Блоки вывода раздела: какие зоны бывают, сколько материалов просить и как задаётся цель.
// Файл подключается в lib/boot.php после feed.php: схлопывание пробелов берётся из
// `collapseSpace()` (lib/catalog.php), а выборка карточек — из `feedScope()` и `toCard()`
// (lib/feed.php), поэтому правило видимости ленты у блока остаётся одним и тем же.

/**
 * Разбор и проверка полей блока. Возвращает очищенные значения и текст отказа: пустая строка в
 * `error` означает «принято».
 *
 * Число материалов сверяется с тем же пределом, что держит лента (`NEWS_PER_PAGE`), но не
 * зажимается молча: просьба больше страницы или ноль — это отказ, а не тихо исправленное
 * намерение человека (§5 спецификации).
 *
 * @return array{zone: string, kind: string, title: string, take: int, error: string}
 */
function checkBlockFields(string $rawZone, string $rawKind, string $rawTitle, int $rawTake): array
{
    $zone = trim($rawZone);
    $kind = trim($rawKind);
    $title = collapseSpace($rawTitle);
    $take = $rawTake;

    if (!in_array($zone, NEWS_BLOCK_ZONES, true)) {
        return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => 'Зоны с таким именем нет.'];
    }

    if (!in_array($kind, NEWS_BLOCK_KINDS, true)) {
        return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => 'Такого вида блока нет.'];
    }

    if (mb_strlen($title) < fieldLimit(BLOCK_FIELDS, 'title', 'min') || mb_strlen($title) > fieldLimit(BLOCK_FIELDS, 'title', 'max')) {
        return [
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'take' => $take,
            'error' => 'Название блока должно быть от ' . fieldLimit(BLOCK_FIELDS, 'title', 'min')
                . ' до ' . fieldLimit(BLOCK_FIELDS, 'title', 'max') . ' символов.',
        ];
    }

    if ($take < fieldLimit(BLOCK_FIELDS, 'take', 'min') || $take > fieldLimit(BLOCK_FIELDS, 'take', 'max')) {
        return [
            'zone' => $zone,
            'kind' => $kind,
            'title' => $title,
            'take' => $take,
            'error' => 'Число материалов должно быть от ' . fieldLimit(BLOCK_FIELDS, 'take', 'min')
                . ' до ' . fieldLimit(BLOCK_FIELDS, 'take', 'max') . '.',
        ];
    }

    return ['zone' => $zone, 'kind' => $kind, 'title' => $title, 'take' => $take, 'error' => ''];
}

/**
 * Проверка цели блока. Возвращает текст отказа; пустая строка — цель принята (или её нет).
 *
 * Три правила: цель бывает только у `list` (§5 спецификации), она ограничена рубрикой и меткой (§2:
 * `target_kind` = `category` / `tag` / `NULL`) и выбранная цель обязана существовать. `targetId` без
 * `targetKind` — обрывок формы (человек снял выбор, а идентификатор в POST остался), и принимать
 * его нельзя: блок получил бы висячую ссылку, которую читатель молча проглатывает.
 */
function blockTargetProblem(string $kind, string $targetKind, string $targetId, bool $targetExists): string
{
    $targetKind = trim($targetKind);
    $targetId = trim($targetId);

    if ($targetKind === '' && $targetId === '') {
        return '';
    }

    if ($kind === 'related') {
        return 'У блока «похожие материалы» цели нет: он берёт рубрику того материала, на котором лежит.';
    }

    // Легальный вид цели берётся из словаря, а не из двух сравнений: тот же словарь читает
    // `blockTargetOptions()`, и добавленная цель не обязана помнить, где её проверяли (вердикт Р13).
    if (!isset(NEWS_BLOCK_TARGETS[$targetKind]) || $targetId === '') {
        return 'Целью блока может быть только рубрика или метка.';
    }

    if (!$targetExists) {
        return 'Выбранной цели уже нет — обновите страницу.';
    }

    return '';
}

/**
 * Адрес цели блока — рубрики или метки — по её идентификатору. Пустая строка означает «цели нет»
 * или «цели уже нет»: читателю довольно и того, и другого, оба случая печатают пустую зону.
 *
 * Имя поля `target_id` держит идентификатор, а все фильтры ленты ждут адрес (§4 спецификации),
 * поэтому разрешение идёт прямым запросом к таблице цели: `findCategory()` и `findTag()` принимают
 * адрес, а не идентификатор (вердикт Р4).
 */
function blockTargetSlug(string $targetKind, string $targetId): string
{
    if ($targetId === '') {
        return '';
    }

    if ($targetKind === 'category') {
        $slug = value('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [$targetId]);
    } elseif ($targetKind === 'tag') {
        $slug = value('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$targetId]);
    } else {
        return '';
    }

    return $slug === null ? '' : (string) $slug;
}

/**
 * Видимые блоки зоны в порядке вывода. Предел стоит и здесь, и в редакторе (задача 6):
 * строка, попавшая в базу мимо редактора, страницу не раздувает.
 *
 * @return list<array<string, mixed>>
 */
function newsBlockRows(string $zone): array
{
    return all(
        sprintf(
            'SELECT id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index'
                . ' FROM %s WHERE zone = ? AND visible = 1 ORDER BY order_index, id LIMIT %d',
            t('news_block'),
            NEWS_BLOCK_PER_ZONE
        ),
        [$zone]
    );
}

/**
 * Карточки блока — поверх уже существующих имён читателя ленты, а не вторым экземпляром правил
 * (вердикт Р8). Рубрика отдаётся `inCategory()` / `relatedLinks()`: они собирают ровно тот же
 * запрос через `categoryFeedRows()`, и исключение материала — их собственный аргумент. Общая лента
 * и подборка по метке строятся поверх `feedScope()`, где живут условия видимости материала,
 * видимости рубрики и соединение с тегами (§8.2).
 *
 * @param array{category?: string, tag?: string} $filter
 * @return list<array<string, mixed>>
 */
function blockFeedRows(array $filter, string $excludeSlug, int $take): array
{
    $category = trim((string) ($filter['category'] ?? ''));
    $tag = trim((string) ($filter['tag'] ?? ''));

    if ($category !== '' && $tag === '') {
        return $excludeSlug === ''
            ? inCategory($category, nowUtc(), $take)
            : relatedLinks($category, $excludeSlug, nowUtc(), $take);
    }

    $scope = feedScope($filter);

    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d',
        NEWS_CARD_COLUMNS,
        $scope['from'],
        $scope['where'],
        orderNews(),
        takeLimit($take)
    );

    $items = [];

    foreach (all($sql, $scope['args']) as $row) {
        $items[] = toCard($row, $scope['now']);
    }

    return $items;
}

/**
 * Подписка региона: добавляет блоки своей зоны к выводу, собранному до неё. Пустой блок не
 * печатается вовсе — заголовок «Похожие материалы» над пустым списком читатель принял бы за
 * сломанную страницу.
 *
 * Состояние зоны читается из базы, поэтому функция нечистая: между двумя вызовами строки
 * блоков реально могут измениться — тест в одной переменной дважды зовёт её на одной зоне,
 * подкладывая перед вторым вызовом ещё один блок. Память о первом ответе здесь запрещена —
 * чтение из базы обязано быть честным.
 *
 * @phpstan-impure
 *
 * @param array<string, mixed> $data
 */
function newsRegionHtml(string $html, string $zone, array $data = []): string
{
    if (!isAppActive('news')) {
        return $html;
    }

    $added = '';

    foreach (newsBlockRows($zone) as $block) {
        $kind = (string) $block['kind'];
        $take = (int) $block['take_limit'];

        if ($kind === 'related') {
            // У «похожих» цели нет (§5 спецификации): рубрика и исключение берутся со страницы,
            // и настройка блока к ним не примешивается — иначе один вид значил бы два разных.
            $category = trim((string) ($data['category'] ?? ''));
            $cards = $category === ''
                ? []
                : blockFeedRows(['category' => $category], trim((string) ($data['slug'] ?? '')), $take);
        } else {
            $targetKind = trim((string) ($block['target_kind'] ?? ''));
            $cards = [];

            if ($targetKind === '') {
                $cards = blockFeedRows([], '', $take);
            } else {
                $targetSlug = blockTargetSlug($targetKind, trim((string) ($block['target_id'] ?? '')));

                // Цель выбрана, но её больше нет (или её имя не из перечня): блок молчит, а не
                // печатает общую ленту под заголовком про несуществующую рубрику — решение 5.
                if ($targetSlug !== '') {
                    $cards = blockFeedRows([$targetKind => $targetSlug], '', $take);
                }
            }
        }

        if ($cards === []) {
            continue;
        }

        $added .= partial('news/partials/block', ['title' => (string) $block['title'], 'cards' => $cards]);
    }

    return $html . $added;
}

// ── Операции записи ──────────────────────────────────────

// Счётчик зоны считает и скрытые строки: предел — это место в настройке, а не число блоков на
// экране (§5 спецификации). Иначе человек накопил бы десять скрытых строк, а читатель задачи 4
// молча отрезал бы седьмую и восьмую на своём LIMIT.
function blockCountInZone(string $zone): int
{
    return (int) value('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', [$zone]);
}

// Жива ли цель, решает тот же резолвер, что зовёт страницу (вердикт Р10): пустой адрес означает
// «рубрики или метки уже нет», и у писателя с читателем остаётся одно определение цели.
function blockTargetExists(string $targetKind, string $targetId): bool
{
    return blockTargetSlug($targetKind, $targetId) !== '';
}

/** @return array{ok: bool, notice: string, error: string} */
function createBlock(string $rawZone, string $rawKind, string $rawTitle, int $rawTake, string $targetKind = '', string $targetId = ''): array
{
    $checked = checkBlockFields($rawZone, $rawKind, $rawTitle, $rawTake);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    $cleanKind = trim($targetKind);
    $cleanId = trim($targetId);

    $problem = blockTargetProblem($checked['kind'], $cleanKind, $cleanId, blockTargetExists($cleanKind, $cleanId));
    if ($problem !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $problem];
    }

    if (blockCountInZone($checked['zone']) >= NEWS_BLOCK_PER_ZONE) {
        return [
            'ok' => false,
            'notice' => '',
            'error' => 'В зоне уже ' . NEWS_BLOCK_PER_ZONE . ' '
                . plural(NEWS_BLOCK_PER_ZONE, ['блок', 'блока', 'блоков'])
                . ' — уберите лишний, чтобы добавить новый.',
        ];
    }

    // Новый блок становится в конец своей зоны: плотность 1..N держится внутри зоны, а не по
    // всей таблице (задача 7 пересобирает её стрелками тоже только в зоне).
    $order = (int) value('SELECT COALESCE(MAX(order_index), 0) FROM ' . t('news_block') . ' WHERE zone = ?', [$checked['zone']]) + 1;
    $id = newId();
    $stamp = utcStamp(nowUtc());

    run(
        'INSERT INTO ' . t('news_block')
            . ' (id, zone, kind, title, target_kind, target_id, take_limit, visible, order_index, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
        [
            $id,
            $checked['zone'],
            $checked['kind'],
            $checked['title'],
            $cleanKind === '' ? null : $cleanKind,
            $cleanId === '' ? null : $cleanId,
            $checked['take'],
            $order,
            $stamp,
            $stamp,
        ]
    );

    logInfo('news.block_saved', ['blockId' => $id, 'zone' => $checked['zone'], 'change' => 'created']);

    return ['ok' => true, 'notice' => 'created', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function renameBlock(string $id, string $rawTitle): array
{
    $row = one('SELECT id, zone, kind, take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    // Зона, вид и предел берутся из строки, а проверка та же, что при создании: у правки заголовка
    // нет своего предела длины (§5 спецификации).
    $checked = checkBlockFields((string) $row['zone'], (string) $row['kind'], $rawTitle, (int) $row['take_limit']);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET title = ?, updated_at = ? WHERE id = ?',
        [$checked['title'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'renamed']);

    return ['ok' => true, 'notice' => 'renamed', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function retargetBlock(string $id, string $targetKind, string $targetId): array
{
    $row = one('SELECT id, zone, kind FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $cleanKind = trim($targetKind);
    $cleanId = trim($targetId);

    // Вид читается из строки: форма правки цели его не отправляет, а взятый из POST позволил бы
    // записать «похожие + рубрика» — противоречие, которое читатель толкует по-своему (вердикт Р9).
    $problem = blockTargetProblem((string) $row['kind'], $cleanKind, $cleanId, blockTargetExists($cleanKind, $cleanId));
    if ($problem !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $problem];
    }

    // Обе колонки пишутся одним выражением: наполовину снятая цель — состояние, которого читатель
    // задачи 4 не различает (пустой `target_kind` значит «вся лента», а живой `target_id` при этом
    // просто теряется).
    run(
        'UPDATE ' . t('news_block') . ' SET target_kind = ?, target_id = ?, updated_at = ? WHERE id = ?',
        [$cleanKind === '' ? null : $cleanKind, $cleanId === '' ? null : $cleanId, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'retargeted']);

    return ['ok' => true, 'notice' => 'retargeted', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function limitBlock(string $id, int $rawTake): array
{
    $row = one('SELECT id, zone, kind, title FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $checked = checkBlockFields((string) $row['zone'], (string) $row['kind'], (string) $row['title'], $rawTake);
    if ($checked['error'] !== '') {
        return ['ok' => false, 'notice' => '', 'error' => $checked['error']];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET take_limit = ?, updated_at = ? WHERE id = ?',
        [$checked['take'], utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone'], 'change' => 'limited']);

    return ['ok' => true, 'notice' => 'limited', 'error' => ''];
}

/**
 * Показ и скрытие — одна функция с направлением, а не две и не переключатель. Направление приходит
 * из намерения человека (`op` = show или hide): двойное «скрыть» обязано оставить блок скрытым,
 * тогда как переключатель показал бы его против воли.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function setBlockVisibility(string $id, bool $visible): array
{
    $row = one('SELECT id, zone FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    run(
        'UPDATE ' . t('news_block') . ' SET visible = ?, updated_at = ? WHERE id = ?',
        [$visible ? 1 : 0, utcStamp(nowUtc()), (string) $row['id']]
    );

    logInfo('news.block_saved', [
        'blockId' => (string) $row['id'],
        'zone' => (string) $row['zone'],
        'change' => $visible ? 'shown' : 'hidden',
    ]);

    return ['ok' => true, 'notice' => $visible ? 'shown' : 'hidden', 'error' => ''];
}

/** @return array{ok: bool, notice: string, error: string} */
function deleteBlock(string $id): array
{
    $row = one('SELECT id, zone FROM ' . t('news_block') . ' WHERE id = ?', [$id]);
    if ($row === null) {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    run('DELETE FROM ' . t('news_block') . ' WHERE id = ?', [(string) $row['id']]);

    logInfo('news.block_deleted', ['blockId' => (string) $row['id'], 'zone' => (string) $row['zone']]);

    return ['ok' => true, 'notice' => 'deleted', 'error' => ''];
}

/**
 * Стрелки порядка. Механика `moveCategory()` (`apps/news/lib/catalog.php:299-331`) с одной
 * поправкой §5 спецификации: обход идёт внутри зоны, поэтому движение строки `home` не пересобирает
 * порядки зоны `article`.
 *
 * Направление приходит из намерения экрана, а не из состояния строки: `op` у ветки `match` задачи 8
 * бывает только `up` или `down`, и третья ветка здесь была бы мёртвым кодом.
 *
 * @return array{ok: bool, notice: string, error: string}
 */
function moveBlock(string $id, string $direction): array
{
    // Одна выборка отвечает и за «блок есть», и за его соседей: отдельный `one()` за идентификатором
    // добавил бы второй вопрос базе о том же состоянии.
    $rows = all('SELECT id, zone, visible FROM ' . t('news_block') . ' ORDER BY order_index, title');

    $zone = '';

    foreach ($rows as $row) {
        if ((string) $row['id'] === $id) {
            $zone = (string) $row['zone'];
            break;
        }
    }

    if ($zone === '') {
        return ['ok' => false, 'notice' => '', 'error' => 'Блока уже нет — обновите страницу.'];
    }

    $ids = [];
    $index = -1;

    foreach ($rows as $row) {
        if ((string) $row['zone'] !== $zone) {
            continue;
        }

        // Позиция считается до добавления строки: счётчик уже добавленных и есть её индекс.
        if ((string) $row['id'] === $id) {
            $index = count($ids);
        }

        $ids[] = (string) $row['id'];
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
        foreach ($next as $position => $blockId) {
            run(
                'UPDATE ' . t('news_block') . ' SET order_index = ?, updated_at = ? WHERE id = ?',
                [$position + 1, $stamp, $blockId]
            );
        }
    });

    logInfo('news.block_moved', ['blockId' => $id, 'zone' => $zone, 'direction' => $direction]);

    return ['ok' => true, 'notice' => 'moved', 'error' => ''];
}
