<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// F10: ветка сбоя в applyApp() (core/migrations.php). Раньше ни один тест её не накрывал,
// хотя код при отказе не пишет сбойный файл в журнал и не обновляет версию установки.
// Частичное применение — существующее поведение применителя, а не дефект: MySQL неявно
// коммитит DDL, и транзакция не умеет откатить наполовину созданную схему.
final class MigrationFailureTest extends TestCase
{
    private const APP = 'broken';
    private const GOOD_FILE = '20261006_0001_note.sql';
    private const BROKEN_FILE = '20261006_0002_broken.sql';

    private string $brokenFilePath = '';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        $this->brokenFilePath = dirname(__DIR__, 2) . '/tests/fixtures/apps/broken/sql/' . self::BROKEN_FILE;
        self::assertFileExists($this->brokenFilePath);

        TestDb::clearTable('app_installation');
        TestDb::clearTable('app_migration');
        $this->dropBrokenTables();
    }

    protected function tearDown(): void
    {
        // Таблицы фикстуры и её строки журнала убираются целиком: последующие наборы не должны
        // наследовать следы сбоя, а сам файл фикстуры тест не переписывает — он остаётся битым.
        run('DELETE FROM ' . t('app_migration') . ' WHERE app = ?', [self::APP]);
        run('DELETE FROM ' . t('app_installation') . ' WHERE id = ?', [self::APP]);
        $this->dropBrokenTables();
    }

    private function dropBrokenTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['broken_note', 'broken_part', 'broken_ghost'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
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

    private function seedInstallation(string $version): void
    {
        insert('app_installation', [
            'id' => self::APP,
            'enabled' => 1,
            'installed_at' => utcStamp(nowUtc()),
            'version' => $version,
        ]);
    }

    public function testУстановкаСоСбойнымФайломВозвращаетОтказСИменемФайла(): void
    {
        $result = installApp(self::APP);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Не удалось применить файл ' . self::BROKEN_FILE, (string) $result['error']);

        // Сбойный файл в журнале не записан: с него применение начнётся заново.
        self::assertSame([self::GOOD_FILE], $this->journal());
        self::assertSame([self::GOOD_FILE], $result['applied']);
    }

    public function testСбойнаяУстановкаНеСчитаетсяУстановленной(): void
    {
        installApp(self::APP);

        // installApp() сам убирает строку установки при отказе: раздел без таблиц не должен
        // числиться установленным, иначе «Установить» отказывал бы навсегда.
        self::assertFalse(isAppInstalled(self::APP));
        self::assertNull(appInstallation(self::APP));
    }

    public function testЧастичноеПрименениеОставляетТаблицыБезЗаписиВЖурнале(): void
    {
        installApp(self::APP);

        // Первое предложение сбойного файла выполнено, второе — нет: обе таблицы созданы,
        // но строки сбойного файла в журнале нет.
        self::assertTrue($this->tableExists('broken_note'), 'Валидный файл создал свою таблицу');
        self::assertTrue($this->tableExists('broken_part'), 'Первое предложение сбойного файла применилось');
        self::assertNotContains(self::BROKEN_FILE, $this->journal());
    }

    public function testСбойПриПримененииНеСдвигаетВерсиюУстановки(): void
    {
        // Раздел «уже установлен» на старой версии: именно она обязана сохраниться при отказе.
        $this->seedInstallation('0.0.0');

        $result = applyApp(planApp(self::APP));

        self::assertFalse($result['ok']);
        self::assertStringContainsString(self::BROKEN_FILE, (string) $result['error']);
        self::assertSame(
            '0.0.0',
            (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', [self::APP]),
            'Версия установки при отказе не сдвинулась'
        );
        self::assertNotContains(self::BROKEN_FILE, $this->journal());
    }

    public function testПослеПочинкиФайлаОбновлениеДоводитУстановкуДоКонца(): void
    {
        $this->seedInstallation('0.0.0');
        self::assertFalse(applyApp(planApp(self::APP))['ok'], 'Битый файл обязан уронить применение');

        // Чинится окружение, а не фикстура: файл остаётся битым как есть, но созданная таблица
        // tbl_broken_ghost делает его второе предложение валидным.
        run('CREATE TABLE IF NOT EXISTS ' . t('broken_ghost') . ' (id VARCHAR(26) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $result = updateApp(self::APP);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame([self::GOOD_FILE, self::BROKEN_FILE], $this->journal(), 'Журнал содержит обе записи');
        self::assertSame(
            '1.0.0',
            (string) value('SELECT version FROM ' . t('app_installation') . ' WHERE id = ?', [self::APP]),
            'Версия доведена до версии сборки'
        );
        self::assertTrue(isAppInstalled(self::APP));
        self::assertSame('ready', appState(self::APP)['state'], 'После починки раздел установлен');
    }
}
