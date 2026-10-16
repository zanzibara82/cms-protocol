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
