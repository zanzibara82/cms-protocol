<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testСредаОтвечаетНаВерсиюPhpНеНижеВосьмиОдин(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '8.1.0', '>='), 'Нужен PHP 8.1 или выше');
    }

    public function testОбразецНастроекСодержитОбязательныеКлючи(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.sample.php';

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'TABLE_PREFIX', 'SITE_URL', 'TIMEZONE'] as $key) {
            self::assertArrayHasKey($key, $config, "В образце нет ключа {$key}");
        }

        self::assertSame('tbl_', $config['TABLE_PREFIX']);
    }

    public function testРасширенияЯдраНаМесте(): void
    {
        // Проверка ровно того набора, без которого код ядра не работает: база, строки в UTF-8,
        // определение типа картинки по содержимому, GD для заготовки превью и TLS к почтовому серверу.
        // Список совпадает с docker/php-dev.Dockerfile: соберёшь образ без расширения — тест скажет.
        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'openssl'] as $name) {
            self::assertTrue(extension_loaded($name), $name . ' не установлен');
        }
    }
}
