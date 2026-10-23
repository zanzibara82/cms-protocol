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
        // Время занимает первые десять символов, поэтому порядок строк проверяется окном между
        // двумя замерами, а не паузой: сон делает проверку плавающей.
        $before = (int) floor(microtime(true) * 1000);
        $first = newId();
        $second = newId();
        $after = (int) floor(microtime(true) * 1000);

        $firstPrefix = substr($first, 0, ID_TIME_LENGTH);
        $secondPrefix = substr($second, 0, ID_TIME_LENGTH);

        self::assertGreaterThanOrEqual(encodeTime($before), $firstPrefix, 'Префикс первого идентификатора раньше левого края окна');
        self::assertLessThanOrEqual(encodeTime($after), $firstPrefix, 'Префикс первого идентификатора позже правого края окна');
        self::assertGreaterThanOrEqual(encodeTime($before), $secondPrefix, 'Префикс второго идентификатора раньше левого края окна');
        self::assertLessThanOrEqual(encodeTime($after), $secondPrefix, 'Префикс второго идентификатора позже правого края окна');

        // Пять подряд идущих миллисекунд, начиная с $before, обязаны давать строго растущие
        // строки: это закрепляет совпадение порядка строк с порядком времени и ловит перестановку
        // букв в ID_ALPHABET.
        for ($ms = $before; $ms < $before + 4; $ms++) {
            self::assertLessThan(encodeTime($ms + 1), encodeTime($ms), 'Кодирование времени не растёт на миллисекунде ' . $ms);
        }

        self::assertLessThanOrEqual($secondPrefix, $firstPrefix, 'Строки должны сортироваться по времени создания');
    }
}
