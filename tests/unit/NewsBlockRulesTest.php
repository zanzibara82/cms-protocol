<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила блока — про договор, а не про базу: какие зоны и виды вообще существуют, какой предел
// у числа материалов и когда цель блока признана. База здесь не нужна, и её нет: тест обязан
// падать на правке правила, а не на состоянии таблиц.
final class NewsBlockRulesTest extends TestCase
{
    public function testЗоныИВидыПеречисленыОднимСписком(): void
    {
        // Список зон — единственное место, где ядро и раздел договариваются об именах: читатель
        // зоны, подписка в маршрутах и форма экрана берут его отсюда.
        self::assertSame(['home', 'category', 'article'], NEWS_BLOCK_ZONES);
        self::assertSame(['list', 'related'], NEWS_BLOCK_KINDS);
        self::assertSame(6, NEWS_BLOCK_PER_ZONE, 'Больше шести блоков на зоне страница читается простынёй');
        self::assertSame(
            ['category' => 'Рубрики', 'tag' => 'Теги'],
            NEWS_BLOCK_TARGETS,
            'Целью блока бывают только рубрика и метка (§2 спецификации), и этот словарь читает и проверка, и выбор формы'
        );
    }

    public function testПоляБлокаОбъявленыДляФормыИСервера(): void
    {
        self::assertSame(2, fieldLimit(BLOCK_FIELDS, 'title', 'min'));
        self::assertSame(120, fieldLimit(BLOCK_FIELDS, 'title', 'max'));
        // Предел `take` обязан совпадать с пределом страницы: `takeLimit()` режет по NEWS_PER_PAGE,
        // и форма, позволяющая большее, обещала бы то, что сервер всё равно урежет.
        self::assertSame(NEWS_PER_PAGE, fieldLimit(BLOCK_FIELDS, 'take', 'max'));
        self::assertSame(1, fieldLimit(BLOCK_FIELDS, 'take', 'min'));
    }

    public function testРазборПолейБерётЗначенияИзСписков(): void
    {
        $checked = checkBlockFields('home', 'list', '  Свежие   материалы ', 3);

        self::assertSame('', $checked['error']);
        self::assertSame('home', $checked['zone']);
        self::assertSame('list', $checked['kind']);
        self::assertSame('Свежие материалы', $checked['title'], 'Лишние пробелы внутри и по краям схлопываются');
        self::assertSame(3, $checked['take']);
    }

    public function testНеизвестнаяЗонаИлиВидОтказываются(): void
    {
        self::assertNotSame('', checkBlockFields('sidebar', 'list', 'Заголовок', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'carousel', 'Заголовок', 4)['error']);
    }

    public function testНазваниеБлокаПоПределу(): void
    {
        // Минимальная длина считается по схлопнутой строке (collapseSpace в blocks.php:24),
        // поэтому значения границы собраны из букв, без пробелов.
        self::assertNotSame('', checkBlockFields('home', 'list', 'к', 4)['error'], 'Одного символа мало: минимум два');
        self::assertSame('', checkBlockFields('home', 'list', str_repeat('а', 2), 4)['error'], 'Заголовок из двух символов — нижняя граница предела — отклонён');
        self::assertSame('', checkBlockFields('home', 'list', 'Кот', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', '', 4)['error']);
        self::assertSame('', checkBlockFields('home', 'list', str_repeat('а', 120), 4)['error'], 'Заголовок из ста двадцати символов — верхняя граница — отклонён');
        self::assertNotSame('', checkBlockFields('home', 'list', str_repeat('а', 121), 4)['error']);
    }

    public function testЧислоМатериаловВнеПределаОтказывается(): void
    {
        // Ноль и 999 — отказ, а не тихое зажатие: блок с нулём на экране выглядит поломкой,
        // а обещание больше страницы редактор не выполняет (§5 спецификации).
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', 0)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', -5)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', 999)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', 'Заголовок', NEWS_PER_PAGE + 1)['error']);

        $one = checkBlockFields('home', 'list', 'Заголовок', 1);
        self::assertSame('', $one['error'], 'Один материал — нижняя граница — отклонён');
        self::assertSame(1, $one['take'], 'Один материал — нижняя граница — принят не своим числом');

        $page = checkBlockFields('home', 'list', 'Заголовок', NEWS_PER_PAGE);
        self::assertSame('', $page['error'], 'Ровно предел страницы — верхняя граница — отклонён');
        self::assertSame(NEWS_PER_PAGE, $page['take'], 'Ровно предел страницы — верхняя граница — принята не своим числом');

        $ok = checkBlockFields('home', 'list', 'Заголовок', 5);
        self::assertSame('', $ok['error']);
        self::assertSame(5, $ok['take'], 'Внутри предела значение сохраняется как принято');
    }

    public function testЦельРазрешенаУпискаИЗапрещенаУПохожих(): void
    {
        self::assertSame('', blockTargetProblem('list', '', '', false), 'Блок без цели — общая лента');
        self::assertSame('', blockTargetProblem('related', '', '', false), '«Похожие» берут рубрику материала');
        self::assertSame('', blockTargetProblem('list', 'category', 'id-рубрики', true));
        self::assertSame('', blockTargetProblem('list', 'tag', 'id-метки', true), 'Метка — тоже легальная цель');

        self::assertNotSame('', blockTargetProblem('list', 'category', 'нет-такой', false), 'Цель обязана существовать');
        self::assertNotSame('', blockTargetProblem('list', 'tag', 'нет-такой', false), 'Метка тоже обязана существовать');
        self::assertNotSame('', blockTargetProblem('related', 'category', 'id', true), 'У «похожих» цели нет');
        self::assertNotSame('', blockTargetProblem('related', 'tag', 'id', true), 'И меткой «похожие» не становятся');
        self::assertNotSame('', blockTargetProblem('list', 'user', 'id', true), 'Целью может быть только рубрика или метка');
        self::assertNotSame('', blockTargetProblem('list', '', 'id', false), 'Идентификатор без вида цели — обрывок формы');
    }

    // ── Цель из формы ────────────────────────────────────

    public function testПустаяСтрокаНеДаётНиВидаНиИдентификатора(): void
    {
        self::assertSame(['kind' => '', 'id' => ''], parseBlockTarget(''));
        self::assertSame(['kind' => '', 'id' => ''], parseBlockTarget('   '), 'Пробелы срезаются до проверки');
    }

    public function testРубрикаИМеткаРасщепляютсяПоПервомуДвоеточию(): void
    {
        self::assertSame(['kind' => 'category', 'id' => 'zhizn-shkoly'], parseBlockTarget('category:zhizn-shkoly'));
        self::assertSame(['kind' => 'tag', 'id' => 'vypusknik'], parseBlockTarget('tag:vypusknik'));
    }

    public function testЗначениеБезРазделителяНеПридумываетВид(): void
    {
        // «prosto-id» не превращается в рубрику с идентификатором «prosto-id»: вид неизвестен, и
        // проверка обязана его отвергнуть, а не выбрать первый словарь.
        self::assertSame(['kind' => '', 'id' => 'prosto-id'], parseBlockTarget('prosto-id'));
    }

    public function testДвоеточиеВИдентификатореОстаётсяВЦели(): void
    {
        // Идентификатор строит `newId()`, и двоеточия в нём нет. Разбор остаётся предсказуемым:
        // всё после первого разделителя — идентификатор, а не ещё один вид цели.
        self::assertSame(['kind' => 'tag', 'id' => 'a:b'], parseBlockTarget('tag:a:b'));
    }

    public function testЧужойВидЦелиДоходитДоПроверкиИОтказывается(): void
    {
        $parsed = parseBlockTarget('shop:9');

        self::assertSame(['kind' => 'shop', 'id' => '9'], $parsed, 'Парсер не решает, жива ли цель');
        self::assertNotSame('', blockTargetProblem('list', $parsed['kind'], $parsed['id'], true));
        self::assertNotSame('', blockTargetProblem('list', $parsed['kind'], $parsed['id'], false));
    }

    public function testНеполнаяЦельОтказывается(): void
    {
        self::assertNotSame('', blockTargetProblem('list', 'category', '', true), 'Рубрика без идентификатора — обрывок');
    }
}
