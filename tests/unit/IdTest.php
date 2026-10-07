<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/id.php';

final class IdTest extends TestCase
{
    public function testДлинаРовноДвадцатьШестьСимволов(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertSame(26, strlen(newId()), 'Идентификатор обязан быть 26 символов');
        }
    }

    public function testСостоитИзСимволовBase32БезОпасныхЗнаков(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', newId());
    }

    public function testИдентификаторыНеПовторяются(): void
    {
        $seen = [];
        for ($i = 0; $i < 5000; $i++) {
            $seen[newId()] = true;
        }

        self::assertCount(5000, $seen);
    }

    public function testПорядокСтрокСовпадаетПорядкомСоздания(): void
    {
        $first = newId();
        // Время в base32 занимает первые 10 символов: через 2 миллисекунды префикс обязан вырасти.
        usleep(2000);
        $second = newId();

        self::assertLessThan($second, $first, 'Строки должны сортироваться по времени создания');
    }
}
