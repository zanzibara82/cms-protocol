<?php

declare(strict_types=1);

// Манифест раздела «SEO и статистика» (NovaSEO Analytics). `id` обязан совпадать с именем
// папки: по нему ядро ищет sql/ и routes.php, а также пишет журнал обновлений. Seed создаёт
// только ключ подписи счётчика: метаданные заводят люди через админку раздела, а не скрипт
// установки.
return [
    'id' => 'seo',
    'title' => 'SEO и статистика',
    'version' => '1.1.0',
    'requiresCore' => '1.1.0',
    'privatePaths' => ['/seo/admin'],
    'sql' => __DIR__ . '/sql',
    'seed' => __DIR__ . '/seed.php',
    'adminNav' => [['href' => '/seo/admin', 'label' => 'SEO']],
];
