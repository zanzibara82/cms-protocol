<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Разделитель параметров — единственное место, где адрес встречается с вопросом. Ядро выбирал его
// inline, и этот выбор до сих пор не проверялся: ошибись он на адресе со своими параметрами, человек
// потерял бы фильтр молча. Разделу склейка нужна в двух местах, поэтому она становится помощником.
final class QueryAppendTest extends TestCase
{
    public function testПервыйПараметрИдётПослеЗнакаВопроса(): void
    {
        self::assertSame('/admin/apps?notice=installed', queryAppend('/admin/apps', 'notice', 'installed'));
    }

    public function testСледующийПараметрИдётПослеАмперсанда(): void
    {
        self::assertSame(
            '/news/admin/items?status=DRAFT&notice=published',
            queryAppend('/news/admin/items?status=DRAFT', 'notice', 'published')
        );
    }

    public function testЗначениеИлючкаКодируются(): void
    {
        // Помощник общий: и имя параметра, и значение кодируются одинаково, какие бы ни подставили.
        self::assertSame('/x?q=a%20b%26c%3Fd', queryAppend('/x', 'q', 'a b&c?d'));
    }

    public function testПустоеЗначениеВсёРавноПишется(): void
    {
        // Плашка «ничего не изменилось» приходит ключом, а не текстом, и пустой ключ — тоже ключ:
        // без параметра страница не отличила бы «действия не было» от «действия не назвали».
        self::assertSame('/news/admin/items?notice=', queryAppend('/news/admin/items', 'notice', ''));
    }
}
