<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/hooks.php';

final class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        hooksReset();
    }

    public function testДействиеВызываетВсеОбработчикиПоПорядкуПриоритета(): void
    {
        $log = [];
        addAction('probe', static function () use (&$log): void {
            $log[] = 'второй';
        }, 20);
        addAction('probe', static function () use (&$log): void {
            $log[] = 'первый';
        }, 10);
        addAction('probe', static function () use (&$log): void {
            $log[] = 'третий';
        }, 30);

        doAction('probe');

        self::assertSame(['первый', 'второй', 'третий'], $log);
    }

    public function testОдинаковыйПриоритетСохраняетПорядокРегистрации(): void
    {
        $log = [];
        addAction('probe', static function () use (&$log): void {
            $log[] = 'a';
        });
        addAction('probe', static function () use (&$log): void {
            $log[] = 'b';
        });

        doAction('probe');

        self::assertSame(['a', 'b'], $log);
    }

    public function testАргументыДоходятДоОбработчика(): void
    {
        $seen = [];
        addAction('probe', static function (string $first, int $second) use (&$seen): void {
            $seen = [$first, $second];
        });

        doAction('probe', 'значение', 7);

        self::assertSame(['значение', 7], $seen);
    }

    public function testФильтрОбязанВернутьЗначение(): void
    {
        addFilter('title', static fn (string $value): string => $value . ' — школа');
        addFilter('title', static fn (string $value): string => mb_strtoupper($value));

        self::assertSame('НОВОСТИ — ШКОЛА', applyFilters('title', 'Новости'));
    }

    public function testФильтрБезОбработчиковВозвращаетИсходное(): void
    {
        self::assertSame('как есть', applyFilters('пустой_хук', 'как есть'));
    }

    public function testОдинХукМожноЗарегистрироватьНесколькоРаз(): void
    {
        $count = 0;
        $bump = static function () use (&$count): void {
            $count++;
        };
        addAction('probe', $bump);
        addAction('probe', $bump);

        doAction('probe');

        self::assertSame(2, $count);
        self::assertSame(2, hookCount('probe'));
    }

    public function testНезарегистрированноеДействиеНеПадает(): void
    {
        doAction('такого_хука_нет');
        self::assertSame(0, hookCount('такого_хука_нет'));
    }
}
