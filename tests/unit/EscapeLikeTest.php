<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/db.php';

final class EscapeLikeTest extends TestCase
{
    public function testСпецсимволыПоискаСтановятсяТекстом(): void
    {
        self::assertSame('100\\%', escapeLike('100%'));
        self::assertSame('новости\\_2024', escapeLike('новости_2024'));
        self::assertSame('a\\\\b', escapeLike('a\\b'));
        self::assertSame('обычный запрос', escapeLike('обычный запрос'));
    }

    public function testПлейсхолдерыДляСписка(): void
    {
        self::assertSame('?, ?, ?', inPlaceholders(3));
        self::assertSame('?', inPlaceholders(1));
        self::assertSame('', inPlaceholders(0));
    }

    public function testПрефиксТаблицыПодставляетсяИзНастроек(): void
    {
        self::assertSame('tbl_news_item', t('news_item'));
    }

    public function testИмяТаблицыНеМожетПринестиПостороннее(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        t('news_item; DROP TABLE tbl_user');
    }
}
