<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/template.php';

final class TemplateTest extends TestCase
{
    public function testИерархияШаблоновБерётФайлПриложенияПервым(): void
    {
        // Цепочка из спецификации §5.6: приложение → тема → ядро → 404.
        self::assertStringEndsWith('apps/news/templates/feed.php', str_replace('\\', '/', resolveTemplate('news/feed')));
    }

    public function testТемаПодхватываетсяКогдаШаблонаПриложенияНет(): void
    {
        self::assertStringEndsWith('tests/fixtures/theme/news/probe-theme.php', str_replace('\\', '/', resolveTemplate('news/probe-theme')));
    }

    public function testНесуществующийШаблонУходитВЧетырестаЧетыре(): void
    {
        self::assertStringEndsWith('theme/404.php', str_replace('\\', '/', resolveTemplate('news/несуществующий')));
    }

    public function testPartialОтдаётСтрокуАНеПечатаетЕё(): void
    {
        $html = partial('parts/probe', ['name' => '<кот>']);

        self::assertStringContainsString('Привет, &lt;кот&gt;', $html);
        self::assertStringNotContainsString('<кот>', $html);
    }

    public function testВыделениеЗвёздочкамиСтановитсяЖирным(): void
    {
        self::assertSame('обычный <strong>важный</strong> текст', richText('обычный **важный** текст'));
    }

    public function testВыделениеЭкранируетРазметкуВокруг(): void
    {
        self::assertSame('&lt;b&gt;и <strong>жирное</strong>&lt;/b&gt;', richText('<b>и **жирное**</b>'));
    }

    public function testНезакрытыеЗвёздочкиОстаютсяТекстом(): void
    {
        self::assertSame('половина ** не выделение', richText('половина ** не выделение'));
    }

    public function testПереносыСтрокСтановятсяАбзацами(): void
    {
        self::assertSame('первая<br>вторая', richText("первая\nвторая"));
    }
}
