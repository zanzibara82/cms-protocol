<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

securityHeaders();

// Маршрутизатор подключит задача 5; до неё точка входа показывает заглушку темы.
echo 'Ядро загружено. Маршрутизатор появится в задаче 5.';
