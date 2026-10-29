<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Ветка сбоя закладки в installApp() (core/migrations.php). До этой задачи seedApp() шёл вне
// try/catch: отказ сида уводил исполнителя в непойманное исключение (→ HTTP 500 на «Установить»),
// а строка app_installation оставалась — раздел числился установленным, «Обновить» ничего не
// планировало, и закладка не выпускалась никогда.
final class SeedFailureTest extends TestCase
{
    private const APP = 'brokenseed';
    private const SQL_FILE = '20261006_0001_seed_note.sql';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/tests/fixtures/apps/brokenseed/seed.php');

        TestDb::clearTable('app_installation');
        TestDb::clearTable('app_migration');
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        // Следы отказа убираются целиком: наборы, идущие дальше, не должны видеть ни установку,
        // ни журнальные файлы фикстуры.
        run('DELETE FROM ' . t('app_migration') . ' WHERE app = ?', [self::APP]);
        run('DELETE FROM ' . t('app_installation') . ' WHERE id = ?', [self::APP]);
        $this->dropTables();
    }

    private function dropTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');
        run('DROP TABLE IF EXISTS ' . t('brokenseed_note'));
        run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    private function journal(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY name', [self::APP])
        );
    }

    private function tableExists(string $name): bool
    {
        return (int) value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [t($name)]
        ) === 1;
    }

    public function testСрывЗакладкиОканчиваетсяОтказомСПричиной(): void
    {
        $result = installApp(self::APP);

        self::assertFalse($result['ok']);
        // Причина живёт в тексте отказа: экран «Приложения» печатает её человеку.
        self::assertStringContainsString('Не удалось заложить начальные данные раздела', (string) $result['error']);
        self::assertStringContainsString('Закладка фикстуры brokenseed сломана', (string) $result['error']);

        // Файл миграции при этом применён и записан в журнал: срыв случился после него.
        self::assertSame([self::SQL_FILE], $this->journal());
        self::assertSame([self::SQL_FILE], $result['applied']);
    }

    public function testСорваннаяЗакладкаНеЧислитсяУстановленной(): void
    {
        installApp(self::APP);

        // Симметрия отказу applyApp(): без снятия строки «Установить» отказывал бы навсегда,
        // а «Обновить» не планировал ничего — закладка не выходила бы никогда.
        self::assertFalse(isAppInstalled(self::APP));
        self::assertNull(appInstallation(self::APP));
    }

    public function testТаблицыОстаютсяПослеСрыва(): void
    {
        installApp(self::APP);

        // Таблица создана и остаётся: повторная установка не строит её заново, а seed.php
        // запускается уже поверх готовой схемы.
        self::assertTrue($this->tableExists('brokenseed_note'), 'Таблица миграции остаётся после срыва сида');
    }
}
