<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Миграция ядра обязана переживать второй прогон — через журнал, а не через сам файл.
 *
 * Причина проверки настоящая и уже сработавшая: в ядре появился первый `ALTER TABLE … ADD COLUMN`
 * (миграция 0006), и после этого установщик начал отвечать 500 «Duplicate column name». Ломался
 * не SQL-файл: `applyApp()` пишет строку `tbl_app_migration` **после** применения, и повторно файл
 * не берёт. Расхождение дала тестовая база — она накатывала файлы схемы напрямую, а журнал не
 * вела, поэтому `setupApplyBaseline()` видел «неприменённые» файлы и прогонял их второй раз.
 *
 * Отсюда правило, которое держит этот страж: после `TestDb::reset()` журнал ядра описывает ровно
 * те файлы, что лежат в `sql/`, и `planApp('core')` пуст. Пустой план означает, что установщик в
 * прогоне не станет применять уже созданное — и 1060 не повторится.
 *
 * Число файлов не закрепляется: его прибавляет каждый релиз схемы. Проверяется совпадение
 * журнала с каталогом, а не конкретный список.
 */
final class CoreMigrationIdempotencyTest extends TestCase
{
    public function testТестоваяБазаВедётЖурналМиграцийЯдра(): void
    {
        TestDb::reset();

        $onDisk = array_map(
            static fn (string $path): string => basename($path),
            glob(dirname(__DIR__, 2) . '/sql/*.sql') ?: []
        );
        sort($onDisk);
        self::assertNotEmpty($onDisk, 'Файлы миграций ядра не найдены — проверять нечего');

        $inJournal = array_map(
            static fn (array $row): string => (string) $row['name'],
            all('SELECT name FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY name', ['core'])
        );

        self::assertSame(
            $onDisk,
            $inJournal,
            'Журнал ядра разошёлся с каталогом sql/: установщик в прогоне применит файлы повторно'
        );

        // Пустой план — то, ради чего журнал и нужен: setupApplyBaseline() не станет повторять
        // ALTER, а значит второй прогон не упадёт.
        self::assertSame([], planApp('core')->pendingNames(), 'План ядра не пуст после сборки тестовой базы');
    }

    /**
     * Самопроверка: план обязан не пустеть от одного удаления строки журнала. Без неё страж мог бы
     * зеленеть на пустом плане, который пуст по другой причине (например, каталог `sql/` исчез).
     */
    public function testПланСтановитсяНепустымБезСтрокиЖурнала(): void
    {
        TestDb::reset();

        $name = basename((string) (glob(dirname(__DIR__, 2) . '/sql/0006*.sql')[0] ?? ''));
        self::assertNotSame('', $name, 'Файл 0006 не найден — самопроверка не имеет предмета');

        run('DELETE FROM ' . t('app_migration') . ' WHERE app = ? AND name = ?', ['core', $name]);

        self::assertContains($name, planApp('core')->pendingNames(), 'План не увидел удалённую строку журнала');

        TestDb::reset();
    }
}
