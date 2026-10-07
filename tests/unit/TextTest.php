<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/text.php';
require_once dirname(__DIR__, 2) . '/core/escape.php';

final class TextTest extends TestCase
{
    public function testСклонениеЧислительного(): void
    {
        self::assertSame('материал', plural(1, ['материал', 'материала', 'материалов']));
        self::assertSame('материала', plural(3, ['материал', 'материала', 'материалов']));
        self::assertSame('материалов', plural(5, ['материал', 'материала', 'материалов']));
        self::assertSame('материалов', plural(11, ['материал', 'материала', 'материалов']));
        self::assertSame('материал', plural(21, ['материал', 'материала', 'материалов']));
        self::assertSame('материале', plural(1, ['материале', 'материалах', 'материалах']));
    }

    public function testВыдержкаОбрезаетсяПоСловамСМноготочием(): void
    {
        $text = str_repeat('слово ', 60);

        self::assertSame(161, mb_strlen(excerpt($text, 160)));
        self::assertStringEndsWith('…', excerpt($text, 160));
        self::assertSame('короткий текст', excerpt('  короткий   текст ', 160));
    }

    public function testВыводЭкранируетКавычкиИУгловыеСкобки(): void
    {
        self::assertSame('&lt;b&gt;&quot;кот&quot;&lt;/b&gt;', e('<b>"кот"</b>'));
        self::assertSame('', e(null));
    }
}
