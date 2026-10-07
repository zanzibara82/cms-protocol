<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class DbLayerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function tearDown(): void
    {
        TestDb::clearTable('option');
    }

    public function testВставкаИЧтениеСтроки(): void
    {
        insert('option', ['option_name' => 'site_name', 'option_value' => 'Школа', 'autoload' => 1]);

        self::assertSame('Школа', value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['site_name']));
    }

    public function testOneВозвращаетСтрокуИлиПусто(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);

        self::assertSame(['option_name' => 'a', 'option_value' => '1', 'autoload' => 1], one('SELECT * FROM ' . t('option') . ' WHERE option_name = ?', ['a']));
        self::assertNull(one('SELECT * FROM ' . t('option') . ' WHERE option_name = ?', ['нет']));
    }

    public function testAllВозвращаетСписок(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);
        insert('option', ['option_name' => 'b', 'option_value' => '2', 'autoload' => 1]);

        self::assertCount(2, all('SELECT option_name FROM ' . t('option') . ' ORDER BY option_name'));
    }

    public function testRunВозвращаетЧислоЗатронутыхСтрок(): void
    {
        insert('option', ['option_name' => 'a', 'option_value' => '1', 'autoload' => 1]);

        self::assertSame(1, run('UPDATE ' . t('option') . ' SET option_value = ? WHERE option_name = ?', ['2', 'a']));
        self::assertSame(0, run('UPDATE ' . t('option') . ' SET option_value = ? WHERE option_name = ?', ['3', 'нет']));
    }

    public function testТранзакцияОткатываетсяПриОшибке(): void
    {
        try {
            tx(static function (): void {
                insert('option', ['option_name' => 'rollback', 'option_value' => '1', 'autoload' => 1]);
                throw new \RuntimeException('проверяем откат');
            });
        } catch (\RuntimeException) {
            // ожидаемо
        }

        self::assertNull(value('SELECT option_value FROM ' . t('option') . ' WHERE option_name = ?', ['rollback']));
    }

    public function testПоискСоСпецсимволамиНеПревращаетсяВШаблон(): void
    {
        insert('option', ['option_name' => 'news_2026', 'option_value' => '1', 'autoload' => 1]);
        insert('option', ['option_name' => 'news 2026', 'option_value' => '2', 'autoload' => 1]);

        $rows = all(
            'SELECT option_name FROM ' . t('option') . ' WHERE option_name LIKE ? ESCAPE \'\\\\\'',
            ['%' . escapeLike('news_2026') . '%']
        );

        self::assertCount(1, $rows);
        self::assertSame('news_2026', $rows[0]['option_name']);
    }

    public function testИмяТаблицыСПостороннимСимволомОтвергается(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        all('SELECT 1 FROM ' . t('user WHERE 1=1 --'));
    }
}
