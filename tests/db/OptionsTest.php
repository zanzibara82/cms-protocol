<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('option');
        optionsReset();
    }

    public function testОтсутствующаяНастройкаОтдаётЗначениеПоУмолчанию(): void
    {
        self::assertSame('запас', getOption('нету', 'запас'));
        self::assertSame('', getOption('нету'));
    }

    public function testЗаписьИЧтение(): void
    {
        setOption('site_name', 'Школа английского');

        self::assertSame('Школа английского', getOption('site_name'));
        self::assertSame('Школа английского', getOption('site_name', 'запас'));
    }

    public function testПовторнаяЗаписьОбновляетСтроку(): void
    {
        setOption('site_name', 'Первое');
        setOption('site_name', 'Второе');

        self::assertSame('Второе', getOption('site_name'));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('option')));
    }

    public function testПустоеЗначениеОтличаетсяОтОтсутствующего(): void
    {
        setOption('phone', '');

        self::assertSame('', getOption('phone', 'запас'));
    }

    public function testУдалениеВозвращаетЗначениеПоУмолчанию(): void
    {
        setOption('site_name', 'Школа');
        deleteOption('site_name');

        self::assertSame('запас', getOption('site_name', 'запас'));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('option')));
    }

    public function testЗаписьNullУдаляетСтроку(): void
    {
        setOption('site_name', 'Школа');
        setOption('site_name', null);

        self::assertSame('запас', getOption('site_name', 'запас'));
    }

    public function testКешСбрасываетсяПослеЗаписи(): void
    {
        self::assertSame('запас', getOption('site_name', 'запас'));
        setOption('site_name', 'Школа');

        self::assertSame('Школа', getOption('site_name', 'запас'));
    }

    public function testКешЧитаетсяОднимЗапросом(): void
    {
        setOption('site_name', 'Школа');
        $first = optionsAll();
        $second = optionsAll();

        self::assertSame($first, $second);
        self::assertArrayHasKey('site_name', $first);
    }
}
