<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class CoreSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    public function testВсеТаблицыЯдраНаМесте(): void
    {
        $expected = ['user', 'login_attempt', 'option', 'app_installation', 'app_migration', 'page', 'page_section', 'page_old_slug'];

        foreach ($expected as $name) {
            self::assertSame(1, (int) value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [t($name)]), 'Нет таблицы ' . t($name));
        }
    }

    public function testПочтаПользователяУникальна(): void
    {
        TestDb::clearTable('user');
        $first = ['id' => newId(), 'email' => 'redaktor@school.dev', 'password_hash' => 'x', 'name' => 'Первый', 'role' => 'editor', 'session_version' => 0, 'created_at' => utcStamp(nowUtc())];
        insert('user', $first);

        $this->expectException(\PDOException::class);
        insert('user', [...$first, 'id' => newId(), 'name' => 'Второй']);
    }

    public function testЖурналМиграцийНеПринимаетДваОдинаковыхФайла(): void
    {
        TestDb::clearTable('app_migration');
        $row = ['id' => newId(), 'app' => 'core', 'name' => '0001_core_baseline.sql', 'checksum' => str_repeat('a', 64), 'applied_at' => utcStamp(nowUtc())];
        insert('app_migration', $row);

        $this->expectException(\PDOException::class);
        insert('app_migration', [...$row, 'id' => newId()]);
    }

    public function testДвижокИКодировкаТаблиц(): void
    {
        $row = one('SELECT ENGINE, TABLE_COLLATION FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [t('user')]);

        self::assertSame('InnoDB', $row['ENGINE']);
        self::assertSame('utf8mb4_unicode_ci', $row['TABLE_COLLATION']);
    }

    public function testРелизСтраницИдемпотентен(): void
    {
        // MySQL фиксирует DDL сразу, поэтому кнопка «Обновить» может доехать до уже созданных
        // таблиц недочитанным файлом: второй прогон по живым таблицам обязан пройти молча.
        $statements = splitSql((string) file_get_contents(dirname(__DIR__, 2) . '/sql/0002_pages.sql'));

        self::assertGreaterThan(0, count($statements));

        foreach ($statements as $statement) {
            run($statement);
        }

        // Повторный прогон без сброса: IF NOT EXISTS пропускает живые таблицы.
        foreach ($statements as $statement) {
            run($statement);
        }
    }

    public function testДатаСозданияХранитсяВUtc(): void
    {
        TestDb::clearTable('option');
        $moment = utcStamp(nowUtc());
        insert('option', ['option_name' => 'moment', 'option_value' => $moment, 'autoload' => 1]);

        $stored = (string) value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['moment']);
        self::assertSame($moment, $stored);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored);
    }
}
