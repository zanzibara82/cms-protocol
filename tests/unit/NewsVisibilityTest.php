<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class NewsVisibilityTest extends TestCase
{
    private function moment(string $stamp): DateTimeImmutable
    {
        return new DateTimeImmutable($stamp, new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private function published(string $stamp): array
    {
        return ['status' => 'PUBLISHED', 'published_at' => $stamp];
    }

    public function testОпубликованныйМатериалНаступившегоСрокаОткрыт(): void
    {
        $now = $this->moment('2026-10-06 12:00:00');

        self::assertTrue(isOpen($this->published('2026-10-06 09:00:00'), $now));
        self::assertTrue(isOpen($this->published('2026-10-06 12:00:00'), $now), 'Граница включительно');
    }

    public function testМатериалБудущегоСрокаЗакрыт(): void
    {
        self::assertFalse(isOpen($this->published('2026-10-06 15:00:00'), $this->moment('2026-10-06 12:00:00')));
        self::assertTrue(isScheduled($this->published('2026-10-06 15:00:00'), $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($this->published('2026-10-06 09:00:00'), $this->moment('2026-10-06 12:00:00')));
    }

    public function testЧерновикЗакрытДажеСоСрокомВПрошлом(): void
    {
        $row = ['status' => 'DRAFT', 'published_at' => '2026-10-01 09:00:00'];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($row, $this->moment('2026-10-06 12:00:00')));
    }

    public function testНеизвестныйСтатусЗакрыт(): void
    {
        $row = ['status' => 'ARCHIVED', 'published_at' => '2026-10-01 09:00:00'];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertFalse(isScheduled($row, $this->moment('2026-10-06 12:00:00')));
    }

    public function testОпубликованныйБезСрокаЗакрыт(): void
    {
        $row = ['status' => 'PUBLISHED', 'published_at' => null];

        self::assertFalse(isOpen($row, $this->moment('2026-10-06 12:00:00')));
        self::assertTrue(isScheduled($row, $this->moment('2026-10-06 12:00:00')), 'Строка без срока ждёт его назначения');
    }

    public function testУсловиеВидимостиЭтоОдинПлейсхолдерБезЛитералаДаты(): void
    {
        $where = visibleWhere();

        self::assertSame(1, substr_count($where, '?'));
        self::assertStringNotContainsString('2026', $where);
        self::assertStringContainsString("status = 'PUBLISHED'", $where);
        self::assertStringContainsString('published_at IS NOT NULL', $where);
        self::assertSame(['2026-10-06 12:00:00'], visibleArgs($this->moment('2026-10-06 12:00:00')));
        self::assertSame(
            substr_count($where, '?'),
            count(visibleArgs($this->moment('2026-10-06 12:00:00'))),
            'плейсхолдеров в условии ровно столько, сколько значений передаётся в запрос'
        );
    }

    public function testПорядокЛентыОдинНаВсехСтраницах(): void
    {
        // Порядок не зависит от страницы: OFFSET считается по той же последовательности, по какой
        // читаются строки. Как только страница меняет порядок, на стыке страниц материал
        // теряется, а закреплённый печатается дважды.
        self::assertSame('pinned DESC, published_at DESC', orderNews());
    }

    public function testФрагментыПорядкаСостоятИзИменКолонок(): void
    {
        self::assertMatchesRegularExpression('/^[a-z_]+ DESC(, [a-z_]+ DESC)?$/', orderNews());
    }

    public function testПредупреждениеОЗакреплённыхСрабатываетПослеПредела(): void
    {
        self::assertFalse(tooManyPinned(0));
        self::assertFalse(tooManyPinned(PINNED_WARN_LIMIT));
        self::assertTrue(tooManyPinned(PINNED_WARN_LIMIT + 1));
    }
}
