<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/boot.php';

// Закладка идемпотентна: ядро запускает seed.php через `require` при каждой установке,
// поэтому файл не объявляет ни констант, ни функций — только повторяет запись по slug.
// Рубрики, добавленные контент-менеджером вручную, закладка не трогает и не переименовывает.
foreach (NEWS_SEED_CATEGORIES as $index => $category) {
    $exists = (int) value(
        'SELECT COUNT(*) FROM ' . t('news_category') . ' WHERE slug = ?',
        [$category['slug']]
    );

    if ($exists > 0) {
        continue;
    }

    run(
        'INSERT INTO ' . t('news_category')
            . ' (id, slug, title, order_index, visible, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, 1, ?, ?)',
        [newId(), $category['slug'], $category['title'], $index, utcStamp(nowUtc()), utcStamp(nowUtc())]
    );
}
