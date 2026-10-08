<?php

declare(strict_types=1);

// Фикстура отказа: требует ядро, которого не существует. На ней проверяется критерий §12.3 —
// человеческий текст и ни одной созданной таблицы.
return [
    'id' => 'future',
    'title' => 'Раздел из будущего',
    'version' => '1.0.0',
    'requiresCore' => '99.0.0',
    'privatePaths' => ['/future/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [],
    'seed' => null,
];
