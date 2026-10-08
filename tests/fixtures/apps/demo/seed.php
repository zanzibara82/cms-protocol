<?php

declare(strict_types=1);

// Начальная закладка обязана быть идемпотентной: seed.php запускается при первой установке,
// а проверка «повторная установка не дублирует строки» (критерий §12.4) считает строки после него.
$exists = value('SELECT COUNT(*) FROM ' . t('demo_note') . ' WHERE title = ?', ['Первая заметка']);

if ((int) $exists === 0) {
    insert('demo_note', [
        'id' => newId(),
        'title' => 'Первая заметка',
        'created_at' => utcStamp(nowUtc()),
    ]);
}
