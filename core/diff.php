<?php

declare(strict_types=1);

// RELEASE 2.2: сравнение ревизий. Текстовые поля сравниваются построчно (LCS), секции — по
// стабильному id, а не по позиции: перестановка не выдаётся за удаление и создание. SEO
// сравнивается отдельным блоком, включая canonical/robots/Open Graph/Schema.org, даже если
// текущая схема хранит часть полей пустыми.

const CONTENT_DIFF_MAX_LINES = 2000;
const CONTENT_DIFF_MAX_CELLS = 1500000;

/**
 * Построчное сравнение текста. Возвращает последовательность equal/add/remove.
 *
 * @return list<array{type: string, text: string}>
 */
function contentDiffText(string $old, string $new): array
{
    $oldLines = contentDiffSplitLines($old);
    $newLines = contentDiffSplitLines($new);

    if ($old === '') {
        return array_map(static fn (string $line): array => ['type' => 'add', 'text' => $line], $newLines);
    }

    if ($new === '') {
        return array_map(static fn (string $line): array => ['type' => 'remove', 'text' => $line], $oldLines);
    }

    $oldCount = count($oldLines);
    $newCount = count($newLines);

    // Общий префикс и хвост не участвуют в матрице: так правка одной строки в большом тексте
    // не строит таблицу на весь документ.
    $prefix = 0;
    while ($prefix < $oldCount && $prefix < $newCount && $oldLines[$prefix] === $newLines[$prefix]) {
        $prefix++;
    }

    $suffix = 0;
    while (
        $suffix < $oldCount - $prefix
        && $suffix < $newCount - $prefix
        && $oldLines[$oldCount - 1 - $suffix] === $newLines[$newCount - 1 - $suffix]
    ) {
        $suffix++;
    }

    $oldMiddle = array_slice($oldLines, $prefix, $oldCount - $prefix - $suffix);
    $newMiddle = array_slice($newLines, $prefix, $newCount - $prefix - $suffix);

    $result = [];
    foreach (array_slice($oldLines, 0, $prefix) as $line) {
        $result[] = ['type' => 'equal', 'text' => $line];
    }

    if (count($oldMiddle) * count($newMiddle) > CONTENT_DIFF_MAX_CELLS) {
        // Слишком большой документ: честнее показать замену целиком, чем съесть память процесса.
        foreach ($oldMiddle as $line) {
            $result[] = ['type' => 'remove', 'text' => $line];
        }
        foreach ($newMiddle as $line) {
            $result[] = ['type' => 'add', 'text' => $line];
        }
    } else {
        foreach (contentDiffLcs($oldMiddle, $newMiddle) as $line) {
            $result[] = $line;
        }
    }

    foreach (array_slice($oldLines, $oldCount - $suffix) as $line) {
        $result[] = ['type' => 'equal', 'text' => $line];
    }

    return $result;
}

/** @return list<string> */
function contentDiffSplitLines(string $text): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);

    return explode("\n", $normalized);
}

/**
 * LCS двух списков строк. Матрица — список направлений, чтобы восстановить путь без второго
 * прохода по значениям.
 *
 * @param list<string> $old
 * @param list<string> $new
 * @return list<array{type: string, text: string}>
 */
function contentDiffLcs(array $old, array $new): array
{
    $n = count($old);
    $m = count($new);
    $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $table[$i][$j] = $old[$i] === $new[$j]
                ? $table[$i + 1][$j + 1] + 1
                : max($table[$i + 1][$j], $table[$i][$j + 1]);
        }
    }

    $out = [];
    $i = 0;
    $j = 0;

    while ($i < $n && $j < $m) {
        if ($old[$i] === $new[$j]) {
            $out[] = ['type' => 'equal', 'text' => $old[$i]];
            $i++;
            $j++;
        } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
            $out[] = ['type' => 'remove', 'text' => $old[$i]];
            $i++;
        } else {
            $out[] = ['type' => 'add', 'text' => $new[$j]];
            $j++;
        }
    }

    while ($i < $n) {
        $out[] = ['type' => 'remove', 'text' => $old[$i]];
        $i++;
    }

    while ($j < $m) {
        $out[] = ['type' => 'add', 'text' => $new[$j]];
        $j++;
    }

    return $out;
}

/**
 * Сравнение двух снимков. Возвращает поля материала, блок секций и блок SEO.
 *
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 * @return array{
 *   fields: list<array{key: string, label: string, old: string, new: string, changed: bool}>,
 *   seo: list<array{key: string, label: string, old: string, new: string, changed: bool}>,
 *   sections: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, moved: list<array<string, mixed>>, order_before: list<string>, order_after: list<string>},
 *   text: list<array{type: string, text: string}>,
 *   summary: array{added: int, removed: int, changed: int, moved: int}
 * }
 */
function contentDiffPayloads(array $old, array $new): array
{
    $fields = contentDiffFields($old, $new);
    $seo = contentDiffSeoFields($old, $new);
    $sections = contentDiffSections(
        is_array($old['sections'] ?? null) ? $old['sections'] : [],
        is_array($new['sections'] ?? null) ? $new['sections'] : []
    );

    return [
        'fields' => $fields,
        'seo' => $seo,
        'sections' => $sections,
        'text' => contentDiffText(contentDiffHumanText($old), contentDiffHumanText($new)),
        'summary' => [
            'added' => count($sections['added']),
            'removed' => count($sections['removed']),
            'changed' => count($sections['changed']),
            'moved' => count($sections['moved']),
        ],
    ];
}

/**
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 * @return list<array{key: string, label: string, old: string, new: string, changed: bool}>
 */
function contentDiffFields(array $old, array $new): array
{
    $labels = [
        'title' => 'Заголовок',
        'slug' => 'Адрес',
        'description' => 'Описание',
        'lead_text' => 'Лид',
        'body' => 'Текст',
        'status' => 'Статус',
        'noindex' => 'Индексация',
        'category_id' => 'Рубрика',
        'published_at' => 'Дата публикации',
        'pinned' => 'Закрепление',
        'cover' => 'Обложка',
        'author_email' => 'Автор',
        'sku' => 'Артикул',
        'brand' => 'Бренд',
        'barcode' => 'Штрихкод',
        'price_retail' => 'Цена',
        'price_promo' => 'Акционная цена',
        'price_cost' => 'Закупочная цена',
        'currency' => 'Валюта',
        'stock' => 'Остаток',
        'seo_title' => 'SEO-заголовок',
        'seo_description' => 'SEO-описание',
        'og_image' => 'Open Graph — изображение',
        'tags' => 'Теги',
        'categories' => 'Рубрики товара',
        'attributes' => 'Характеристики',
    ];

    $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
    $out = [];

    foreach ($keys as $key) {
        if (!isset($labels[$key])) {
            continue;
        }

        $left = contentDiffValue($old[$key] ?? null);
        $right = contentDiffValue($new[$key] ?? null);

        if ($left === $right) {
            continue;
        }

        $out[] = [
            'key' => (string) $key,
            'label' => $labels[$key],
            'old' => $left,
            'new' => $right,
            'changed' => true,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 * @return list<array{key: string, label: string, old: string, new: string, changed: bool}>
 */
function contentDiffSeoFields(array $old, array $new): array
{
    $left = is_array($old['seo'] ?? null) ? $old['seo'] : [];
    $right = is_array($new['seo'] ?? null) ? $new['seo'] : [];
    $labels = [
        'title' => 'SEO title',
        'description' => 'SEO description',
        'canonical' => 'Canonical',
        'robots' => 'Robots',
        'schema' => 'Schema.org',
        'open_graph' => 'Open Graph',
        'url' => 'URL',
        'images' => 'Изображения',
    ];

    $out = [];
    foreach ($labels as $key => $label) {
        $before = contentDiffValue($left[$key] ?? null);
        $after = contentDiffValue($right[$key] ?? null);

        if ($before === $after) {
            continue;
        }

        $out[] = ['key' => $key, 'label' => $label, 'old' => $before, 'new' => $after, 'changed' => true];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $old
 * @param list<array<string, mixed>> $new
 * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, moved: list<array<string, mixed>>, order_before: list<string>, order_after: list<string>}
 */
function contentDiffSections(array $old, array $new): array
{
    $before = [];
    $after = [];

    foreach ($old as $section) {
        if (is_array($section)) {
            $before[contentDiffSectionKey($section)] = $section;
        }
    }

    foreach ($new as $section) {
        if (is_array($section)) {
            $after[contentDiffSectionKey($section)] = $section;
        }
    }

    $added = [];
    $removed = [];
    $changed = [];
    $moved = [];
    $orderBefore = [];
    $orderAfter = [];

    foreach ($before as $key => $section) {
        $orderBefore[] = $key;
        if (!isset($after[$key])) {
            $removed[] = contentDiffSectionCard($section);
        }
    }

    foreach ($after as $key => $section) {
        $orderAfter[] = $key;
        if (!isset($before[$key])) {
            $added[] = contentDiffSectionCard($section);
            continue;
        }

        $fields = contentDiffSectionFields($before[$key], $section);
        if ($fields !== []) {
            $card = contentDiffSectionCard($section);
            $card['fields'] = $fields;
            $changed[] = $card;
        }
    }

    $commonBefore = array_values(array_filter($orderBefore, static fn (string $key): bool => isset($after[$key])));
    $commonAfter = array_values(array_filter($orderAfter, static fn (string $key): bool => isset($before[$key])));

    foreach ($commonBefore as $position => $key) {
        $newPosition = array_search($key, $commonAfter, true);

        if ($newPosition !== false && $newPosition !== $position) {
            $card = contentDiffSectionCard($after[$key]);
            $card['old_position'] = $position + 1;
            $card['new_position'] = $newPosition + 1;
            $moved[] = $card;
        }
    }

    return [
        'added' => $added,
        'removed' => $removed,
        'changed' => $changed,
        'moved' => $moved,
        'order_before' => $orderBefore,
        'order_after' => $orderAfter,
    ];
}

/** @param array<string, mixed> $section */
function contentDiffSectionKey(array $section): string
{
    $id = trim((string) ($section['id'] ?? ''));

    if ($id !== '') {
        return 'id:' . $id;
    }

    return 'key:' . (string) ($section['sec_key'] ?? '');
}

/** @param array<string, mixed> $section
 *  @return array<string, mixed>
 */
function contentDiffSectionCard(array $section): array
{
    return [
        'id' => (string) ($section['id'] ?? ''),
        'sec_key' => (string) ($section['sec_key'] ?? ''),
        'kind' => (string) ($section['kind'] ?? ''),
        'title' => (string) ($section['title'] ?? ''),
        'name' => contentDiffSectionName($section),
    ];
}

/** @param array<string, mixed> $section */
function contentDiffSectionName(array $section): string
{
    $title = trim((string) ($section['title'] ?? ''));

    if ($title !== '') {
        return $title;
    }

    $key = trim((string) ($section['sec_key'] ?? ''));

    if ($key !== '') {
        return $key;
    }

    return (string) ($section['kind'] ?? 'Секция');
}

/**
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 * @return list<array{key: string, label: string, old: string, new: string}>
 */
function contentDiffSectionFields(array $old, array $new): array
{
    $labels = [
        'kind' => 'Тип',
        'layout' => 'Вёрстка',
        'anchor' => 'Якорь',
        'badge' => 'Бейдж',
        'title' => 'Заголовок',
        'lead_text' => 'Текст',
        'note' => 'Примечание',
        'image' => 'Изображение',
        'image_alt' => 'ALT изображения',
        'buttons' => 'Кнопки',
        'items' => 'Повторяющиеся блоки',
        'settings' => 'Настройки',
        'sort' => 'Порядок',
        'visible' => 'Видимость',
    ];

    $out = [];

    foreach ($labels as $key => $label) {
        $left = contentDiffValue($old[$key] ?? null);
        $right = contentDiffValue($new[$key] ?? null);

        if ($left === $right) {
            continue;
        }

        $out[] = ['key' => $key, 'label' => $label, 'old' => $left, 'new' => $right];
    }

    return $out;
}

/** @param mixed $value */
function contentDiffValue($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'да' : 'нет';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Человеческий текст материала для общего построчного сравнения.
 *  @param array<string, mixed> $payload
 */
function contentDiffHumanText(array $payload): string
{
    $parts = [];

    foreach (['title', 'description', 'lead_text'] as $key) {
        if (trim((string) ($payload[$key] ?? '')) !== '') {
            $parts[] = (string) $payload[$key];
        }
    }

    if (is_array($payload['body'] ?? null)) {
        foreach ($payload['body'] as $paragraph) {
            if (is_array($paragraph)) {
                $parts[] = trim((string) ($paragraph['title'] ?? ''));
                $parts[] = trim((string) ($paragraph['text'] ?? ''));
            }
        }
    }

    if (is_array($payload['sections'] ?? null)) {
        foreach ($payload['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach (['title', 'lead_text', 'note'] as $key) {
                if (trim((string) ($section[$key] ?? '')) !== '') {
                    $parts[] = (string) $section[$key];
                }
            }

            foreach (['buttons', 'items'] as $list) {
                foreach (is_array($section[$list] ?? null) ? $section[$list] : [] as $row) {
                    if (is_array($row)) {
                        foreach ($row as $value) {
                            if (is_scalar($value) && trim((string) $value) !== '') {
                                $parts[] = (string) $value;
                            }
                        }
                    }
                }
            }
        }
    }

    return implode("\n", array_values(array_filter($parts, static fn (string $line): bool => $line !== '')));
}

/**
 * Сколько строк каждого вида в результате contentDiffText().
 *
 * @param list<array{type: string, text: string}> $lines
 * @return array{equal: int, add: int, remove: int}
 */
function contentDiffCounts(array $lines): array
{
    $counts = ['equal' => 0, 'add' => 0, 'remove' => 0];

    foreach ($lines as $line) {
        $type = (string) ($line['type'] ?? 'equal');
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }

    return $counts;
}
