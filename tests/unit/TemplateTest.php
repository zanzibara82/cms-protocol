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

    public function testНастоящиеРазмерыКартинкиЧитаютсяИзПубличногоКаталога(): void
    {
        // Шаблоны считают от этих цифр height: грустный кот не квадратный, и height === width
        // сплющивает его по вертикали. Числа обязана давать сама картинка, а не предположение кода.
        self::assertSame([64, 64], imageNaturalSize('assets/img/cats/cat-happy.png'));
        self::assertSame([192, 170], imageNaturalSize('assets/img/cats/cat-sad.png'));
    }

    public function testВнеПубличногоКаталогаРазмерыНеЧитаются(): void
    {
        // Путь собирается внутри public: `..` наружу не выводит, а несуществующий файл означает
        // «не читается» — шаблоны тогда остаются на квадрате, а не берут размеры чужого файла.
        self::assertSame([0, 0], imageNaturalSize('../core/template.php'));
        self::assertSame([0, 0], imageNaturalSize('assets/img/cats/такой-картинки-нет.png'));
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

    public function testПустаяЗонаНеПечатаетНиБайта(): void
    {
        // Подписчика нет в сборке без раздела: страница обязана остаться ровно такой, какой была
        // до вызова. Обёртка «для пустоты» нарисовала бы посередине полосы пустую рамку.
        ob_start();
        region('home');

        self::assertSame('', (string) ob_get_clean());
    }

    public function testЗонаСПробеламиВместоСодержимогоМолчит(): void
    {
        // «Ничего не нашлось» подписчик возвращает переводом строк, и это тоже пустота:
        // решение 5 спецификации проверяется именно на границе trim(), а не на пустой строке.
        addFilter('region_home', static fn (string $html): string => "\n  \t ");

        ob_start();
        region('home');

        self::assertSame('', (string) ob_get_clean(), 'Обёртка печатается только вокруг содержимого');
    }

    public function testЗонаПечатаетОбёрткуИхСодержимоеСырьём(): void
    {
        addFilter('region_home', static fn (string $html): string => $html . '<p>один</p>');

        ob_start();
        region('home');
        $out = (string) ob_get_clean();

        self::assertStringContainsString('<section class="region region-home">', $out);
        self::assertStringContainsString('<p>один</p>', $out, 'Разметка подписчика не экранируется');
        self::assertSame(1, substr_count($out, '<section class="region region-'), 'Одна зона — одна обёртка');
    }

    public function testПодписчикПолучаетИмяЗоныИКонтекстСтраницы(): void
    {
        // Ядро не знает, зачем подписчику имя зоны и адрес материала, — но без этих двух
        // аргументов раздел не различил бы три свои точки и не собрал бы «похожие» в нужной
        // рубрике (решение 7 спецификации: третий аргумент нужен именно переезду виджета).
        addFilter('region_article', static fn (string $html, string $zone, array $data): string => $html
            . '<i>' . e($zone) . '</i><b>' . e((string) $data['slug']) . '</b>');

        ob_start();
        region('article', ['slug' => 'itog-goda']);
        $out = (string) ob_get_clean();

        self::assertStringContainsString('<i>article</i>', $out);
        self::assertStringContainsString('<b>itog-goda</b>', $out);
    }

    public function testНесколькоПодписчиковСходятсяВОднуОбёртку(): void
    {
        // Подписок у зоны может быть больше одного, и порядок обязан читаться по приоритету:
        // иначе раздел не сможет встать раньше темы, а обёрток станет две на один вызов.
        addFilter('region_home', static fn (string $html): string => $html . '<p>ранний</p>', 5);
        addFilter('region_home', static fn (string $html): string => $html . '<p>поздний</p>', 20);

        ob_start();
        region('home');
        $out = (string) ob_get_clean();

        self::assertSame(1, substr_count($out, '<section class="region region-'));
        self::assertLessThan(
            (int) strpos($out, '<p>поздний</p>'),
            (int) strpos($out, '<p>ранний</p>'),
            'Более ранний приоритет обязан читаться первым'
        );
    }

    public function testВыводЗоныПопадаетВСтрокуКоторуюВозвращаетRenderToString(): void
    {
        // Единственный канал ответа ядра — строка, собранная renderToString() (core/template.php:103),
        // и экраны отказа уходят в respond() именно ею. Печатающая точка вывода обязана донести зону
        // и туда: иначе отказный экран и любой ответ-строка вышли бы без блоков (§6 спецификации).
        addFilter('region_home', static fn (string $html): string => $html . '<p>в строке</p>');

        $html = renderToString('probe-region');

        self::assertStringContainsString('<p>тело страницы</p>', $html, 'Страница-фикстура не собрана');
        self::assertStringContainsString('<main id="main"', $html, 'Каркас темы обязан остаться целым');
        self::assertStringContainsString('<section class="region region-home">', $html);
        self::assertStringContainsString('<p>в строке</p>', $html, 'Содержимое подписчика потерялось');
    }
}
