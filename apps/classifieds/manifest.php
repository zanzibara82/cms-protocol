<?php

declare(strict_types=1);

// Манифест раздела «Доска объявлений» (ТЗ владельца зовёт его NovaClassifieds). `id` обязан
// совпадать с именем папки: по нему ядро ищет sql/, routes.php и seed.php, а также пишет
// журнал обновлений. Своего seed здесь нет: дерево категорий заводит контент-менеджер (решение
// по C1), поэтому раздел начинается с пустого справочника.
return [
    'id' => 'classifieds',
    'title' => 'Доска объявлений',
    'version' => '1.5.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/classifieds/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/classifieds/admin', 'label' => 'Доска']],
];
