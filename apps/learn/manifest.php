<?php

declare(strict_types=1);

// Манифест раздела «Курсы». `id` обязан совпадать с именем папки: по нему ядро ищет
// sql/, routes.php и seed.php, а также пишет журнал обновлений. Учебный контур — раздел,
// а не способность сайта: выключенное приложение прячет ссылку и снимает свои адреса.
return [
    'id' => 'learn',
    'title' => 'Курсы',
    'version' => '1.0.0',
    'requiresCore' => '1.2.0',
    'privatePaths' => ['/learn/admin'],
    'sql' => __DIR__ . '/sql',
    'publicNav' => [['/learn', 'Курсы']],
    'adminNav' => [['href' => '/learn/admin', 'label' => 'Курсы']],
    'seed' => __DIR__ . '/seed.php',
];
