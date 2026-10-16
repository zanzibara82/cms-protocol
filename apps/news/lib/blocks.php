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
