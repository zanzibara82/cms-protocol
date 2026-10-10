<?php

declare(strict_types=1);

// Манифест раздела «Новости». `id` обязан совпадать с именем папки: по нему ядро ищет
// sql/, routes.php и seed.php, а также пишет журнал обновлений.
return [
    'id' => 'news',
    'title' => 'Новости',
    'version' => '1.0.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/news/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/news/admin', 'label' => 'Новости']],
    'seed' => __DIR__ . '/seed.php',
];
