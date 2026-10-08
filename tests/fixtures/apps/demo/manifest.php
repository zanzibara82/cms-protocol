<?php

declare(strict_types=1);

// Фикстура для проверок установки и обновления: два релиза SQL и своя начальная закладка.
// id обязан совпадать с именем каталога — расхождение ловит AppsRegistryTest, а в работе оно
// дало бы молчаливый 404: resolveTemplate() ищет шаблоны по имени каталога, а не по id.
return [
    'id' => 'demo',
    'title' => 'Демонстрационный раздел',
    'version' => '1.1.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/demo/admin'],
    'sql' => __DIR__ . '/sql',
    'adminNav' => [['href' => '/demo/admin', 'label' => 'Демо']],
    'seed' => __DIR__ . '/seed.php',
];
