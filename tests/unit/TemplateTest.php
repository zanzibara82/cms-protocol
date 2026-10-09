<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/template.php';

final class TemplateTest extends TestCase
{
    protected function setUp(): void
    {
        // Хук page_head общий для всего прогона: без сброса подписка этого теста утекла бы в другие.
        hooksReset();
    }

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

    public function testЧетырестаЧетыреПечатаютПричинуЯдраЭкранированной(): void
    {
        // notFound() принимает причину из кода («Сайт уже установлен.») и обязана донести её до
        // человека, а не проглотить: шаблон печатает строку через e() — размёткой она не становится.
        $html = partial('404', ['message' => '<b>причина</b>']);

        self::assertStringContainsString('&lt;b&gt;причина&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>причина</b>', $html);
    }

    public function testЧетырестаЧетыреБезПричиныОтдаютОбщуюПодсказку(): void
    {
        // Пустая причина — обычный несуществующий адрес: человек обязан увидеть подсказку,
        // где искать материал, а не пустое место под заголовком.
        $html = partial('404', ['message' => '']);

        self::assertStringContainsString('Адрес мог измениться', $html);
        self::assertStringContainsString('Страницы нет', $html);
    }

    public function testHeadСтраницыДополняетВыводХукаВРазметкеШапки(): void
    {
        // Разметка из хука page_head и значение $page['head'] обязаны попасть в <head> сырьём;
        // порядок фиксирован: сначала хук, затем значение страницы (каскад читает их в этом порядке).
        addAction('page_head', static function (): void {
            echo '<meta name="generator" content="cms">';
        });

        ob_start();
        render('home', [], [
            'title' => 'Проверка',
            'head' => '<meta property="og:type" content="website">',
        ]);
        $html = (string) ob_get_clean();

        $hook = strpos($html, '<meta name="generator" content="cms">');
        $page = strpos($html, '<meta property="og:type" content="website">');

        self::assertNotFalse($page, 'Значение $page[head] обязано появиться в разметке');
        self::assertNotFalse($hook, 'Вывод хука page_head обязан появиться в разметке');
        self::assertLessThan((int) $page, (int) $hook, 'Хук обязан идти раньше значения $page[head]');
    }
}
