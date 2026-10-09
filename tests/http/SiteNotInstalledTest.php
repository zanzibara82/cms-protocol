<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// F3: на неустановленной схеме человек открывает первую попавшуюся страницу и обязан получить
// понятный русский экран со ссылкой на установщик, а не пустой ответ из-за падения на отсутствующей таблице.
final class SiteNotInstalledTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropCoreTables();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        // Класс уронил таблицы ядра в setUpBeforeClass; без возврата схемы следующий набор падал
        // не на своём дефекте, а на отсутствии таблиц. Порядок важен: сервер обязан умереть, пока
        // таблицы ещё на месте, иначе php -S успел бы их тронуть после сброса.
        TestDb::reset();
    }

    // Сбрасываем схему до состояния «установщик ещё не запускался»: таблиц ядра нет вовсе.
    private static function dropCoreTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (CORE_BASELINE_TABLES as $name) {
            run('DROP TABLE IF EXISTS ' . t($name));
        }
        run('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testГлавнаяНаНеустановленнойСхемеДаётПодсказку(): void
    {
        $page = Kernel::get('/');

        self::assertSame(503, $page['status']);
        self::assertStringContainsString('Сайт не установлен', $page['body']);
        self::assertStringContainsString('/setup.php', $page['body']);
        // Пакет V, находка 3 аудита просмотра: этот экран идёт до `startSession()`, поэтому заголовков
        // лимитера на нём нет вовсе — запрет кэширования обязан исходить из самого вызова `respond()`.
        // Экран описывает состояние установки: после её запуска он перестаёт быть правдой.
        self::assertSame('no-store', $page['headers']['cache-control'], 'Экран «Сайт не установлен» не отдал собственный запрет кэширования');
    }

    public function testВходНаНеустановленнойСхемеДаётПодсказку(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(503, $page['status']);
        self::assertStringContainsString('Сайт не установлен', $page['body']);
        self::assertSame('no-store', $page['headers']['cache-control'], 'Тот же экран на пути входа не отдал собственный запрет кэширования');
    }

    public function testУстановщикОтдаётФормуПокаСхемаНеУстановлена(): void
    {
        $page = Kernel::get('/setup.php');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Установка сайта', $page['body']);
    }
}
