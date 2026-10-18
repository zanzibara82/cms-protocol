<?php

declare(strict_types=1);

// Манифест раздела «Интернет-магазин» (ТЗ владельца зовёт его NovaCommerce). `id` обязан
// совпадать с именем папки: по нему ядро ищет sql/, routes.php и seed.php, а также пишет
// журнал обновлений. Своего seed здесь нет: магазин начинает с пустого каталога.
return [
    'id' => 'commerce',
    'title' => 'Интернет-магазин',
    'version' => '1.0.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/commerce/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/commerce/admin', 'label' => 'Магазин']],
];
