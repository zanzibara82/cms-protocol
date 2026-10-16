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
        self::assertNotSame('', checkBlockFields('home', 'list', 'к', 4)['error'], 'Одного символа мало: минимум два');
        self::assertSame('', checkBlockFields('home', 'list', 'Кот', 4)['error']);
        self::assertNotSame('', checkBlockFields('home', 'list', '', 4)['error']);
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
}
