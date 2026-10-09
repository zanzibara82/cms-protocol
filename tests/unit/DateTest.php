<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/date.php';

final class DateTest extends TestCase
{
    public function testВБазуПишемUtc(): void
    {
        $moscow = new DateTimeImmutable('2026-10-06 09:00:00', new DateTimeZone('Europe/Moscow'));

        self::assertSame('2026-10-06 06:00:00', utcStamp($moscow));
    }

    public function testИзБазыЧитаемUtc(): void
    {
        $date = fromDb('2026-10-06 06:00:00');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('2026-10-06T09:00:00+03:00', moscowIso($date));
    }

    public function testПустоеЗначениеИзБазыОстаётсяПустым(): void
    {
        self::assertNull(fromDb(null));
        self::assertNull(fromDb(''));
    }

    public function testПолеФормыРазбираетсяВМосковскихЧасах(): void
    {
        $date = parseFormDateTime('2026-10-06T09:00');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-10-06 06:00:00', utcStamp($date));
    }

    public function testНеверныйФорматПоляФормыДаётПустоАНеИсключение(): void
    {
        self::assertNull(parseFormDateTime('вчера в девять'));
        self::assertNull(parseFormDateTime(''));
        self::assertNull(parseFormDateTime('2026-13-45T99:99'));
    }

    public function testРусскаяДатаЧитаетсяВРодительномПадежеМесяца(): void
    {
        self::assertSame('6 октября 2026', formatRuDate(fromDb('2026-10-06 06:00:00')));
        self::assertSame('1 января 2026', formatRuDate(fromDb('2026-01-01 00:00:00')));
        self::assertSame('—', formatRuDate(null));
    }

    public function testРусскаяДатаСоВременем(): void
    {
        self::assertSame('6 октября 2026, 09:00', formatRuDateTime(fromDb('2026-10-06 06:00:00')));
        self::assertSame('—', formatRuDateTime(null));
    }

    public function testМосковскийПереносНеУезжаетНаПредыдущийДень(): void
    {
        // 23:40 по Москве — 20:40 UTC того же дня: формат обязан показать 23:40, а не вчерашнее число.
        self::assertSame('2026-10-06T23:40:00+03:00', moscowIso(fromDb('2026-10-06 20:40:00')));
    }

    public function testШтампПисьмаВФорматеRfc2822ВсегдаВUtc(): void
    {
        // Заголовок Date письма: день недели по-английски и пояс «+0000» — момент переводится в UTC
        // до форматирования, и почтовый сервер сверяет это время со своим.
        $utc = fromDb('2026-10-06 06:00:00');
        self::assertInstanceOf(DateTimeImmutable::class, $utc);
        self::assertSame('Tue, 06 Oct 2026 06:00:00 +0000', rfc2822Stamp($utc));

        // Тот же момент, набранный редактором по Москве (09:00 = 06:00 UTC), даёт идентичный штамп:
        // иначе письмо утверждало бы, что отправлено на три часа раньше.
        $fromForm = parseFormDateTime('2026-10-06T09:00');
        self::assertInstanceOf(DateTimeImmutable::class, $fromForm);
        self::assertSame('Tue, 06 Oct 2026 06:00:00 +0000', rfc2822Stamp($fromForm));
    }
}
