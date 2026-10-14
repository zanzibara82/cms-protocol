<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/setup-config.php';
require_once dirname(__DIR__, 2) . '/core/setup-probe.php';

// Оба файла механики первого шага подключаются по `require_once`: весь набор идёт в одном
// процессе, а `tests/unit/SetupConfigTest.php` уже загрузил `core/setup-config.php` этим же
// способом, поэтому второй `require` того же файла дал бы `Cannot redeclare`. Помощник `excerpt()`
// из `core/text.php` тестовому процессу доступен через `core/bootstrap.php`, который загружен в
// `tests/bootstrap.php`, и отдельно его требовать не нужно.
//
// Здесь проверяется граница текста отказа — то, что через реальный тестовый сервер не проверяется
// вовсе: сообщение `1045` на этом стенде однострочное и короче порога, поэтому в http-наборе
// утверждений про вырезку нет (и появиться не должно).
final class SetupProbeTest extends TestCase
{
    /** Порог среза первой строки: тот же, что стоит в `setupFailureHint()`. */
    private const HINT_LIMIT = 160;

    /**
     * Первая строка сообщения драйвера — единственное, что интересно оператору: в ней имя
     * пользователя и способ аутентификации. Всё ниже (`host`, `version`, стек) уходит из текста.
     */
    public function testПервойСтрокиДостаточно(): void
    {
        // Суммарная длина фикстуры обязана оставаться короче порога: иначе `excerpt()` обрезал бы
        // вторую строку раньше, чем до неё доберётся обратный замер П-1, и «секрет-второй-строки»
        // исчез бы из возврата при любом варианте кода — замер стал бы ложнозелёным.
        $message = 'Access denied for user \'root\'@\'localhost\' (using password: YES)' . "\n" . 'секрет-второй-строки во второй строке';
        self::assertSame(101, mb_strlen($message), 'Фикстура длиннее порога: замер П-1 на ней не различим');

        $hint = setupFailureHint($message);

        self::assertStringStartsWith('Access denied for user ', $hint, 'Первая строка сообщения драйвера не взята целиком');
        self::assertStringContainsString('using password: YES', $hint, 'Первая строка срезана раньше конца');
        self::assertStringNotContainsString('секрет-второй-строки', $hint, 'Вторая строка сообщения ушла в текст отказа');
    }

    /**
     * Длинная первая строка срезается по порогу, а не показывается целиком: текст отказа живёт в
     * плашке на экране, а не в журнале.
     */
    public function testДлиннаяПерваяСтрокаСрезана(): void
    {
        $longLine = str_repeat('x', 300);
        $message = $longLine . "\n" . 'вторая строка' . "\n" . 'секрет-третьей-строки';

        $hint = setupFailureHint($message);

        // Порог плюс один символ границы: `excerpt()` при переполнении добавляет многоточие.
        self::assertSame(self::HINT_LIMIT + 1, mb_strlen($hint), 'Длинная первая строка не срезана по порогу');
        self::assertSame(mb_substr($longLine, 0, 1), mb_substr($hint, 0, 1), 'Срез начался не с первого символа сообщения');
        self::assertStringNotContainsString('секрет-третьей-строки', $hint, 'Третья строка сообщения ушла в текст отказа');
    }

    /**
     * Ровно тот текст, который драйвер отдаёт на этом стенде: однострочный и короче порога, он
     * возвращается без изменений — видимая часть отказа при выносе функции не поехала.
     */
    public function testОднострочноеКороткоеСообщениеНеМеняется(): void
    {
        $message = 'Access denied for user \'root\'@\'localhost\'';

        self::assertSame($message, setupFailureHint($message), 'Однострочное короткое сообщение переписано');
    }

    /** Пустое сообщение — пустая граница: функция обязана отдавать край, а не фантом. */
    public function testПустоеСообщениеДаётПустуюГраницу(): void
    {
        self::assertSame('', setupFailureHint(''), 'Пустое сообщение дало непустой текст отказа');
    }
}
