<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Чистые правила слоя страниц: слаг-политика (форма, зарезервированные сегменты, занятость),
 * перечень видов первого захода, пределы полей и общий транслит ядра. Базы нет: правила не ходят
 * в неё сами — занятость приносит замыкание вызывающего.
 */
final class PagesRulesTest extends TestCase
{
    public function testСлагСтраницыСобираетсяИзЗаголовкаИОтводитСлужебныеСегменты(): void
    {
        // Ручной адрес проходит нормализацию транслита ядра.
        $typed = pageSlugPlan('О Нас', 'Заголовок', static fn (string $candidate): bool => false);

        self::assertSame('o-nas', $typed['slug']);
        self::assertSame('', $typed['error']);

        // Пустое поле — адрес собирается из заголовка; занятость решает нумерация.
        $auto = pageSlugPlan('', 'Жизнь школы', static fn (string $candidate): bool => $candidate === 'zhizn-shkoly');

        self::assertSame('zhizn-shkoly-2', $auto['slug']);
        self::assertSame('', $auto['error']);

        // Зарезервированный первый сегмент раздела отводится с причиной, а не формальной длиной.
        $reserved = pageSlugPlan('news', 'Заголовок', static fn (string $candidate): bool => false);

        self::assertSame('', $reserved['slug']);
        self::assertStringContainsString('news', $reserved['error']);

        // Короткий адрес — отказ с названным пределом.
        $short = pageSlugPlan('ab', 'Заголовок', static fn (string $candidate): bool => false);

        self::assertSame('', $short['slug']);
        self::assertStringContainsString((string) PAGES_SLUG_MIN, $short['error']);

        // Занятый адрес — отказ.
        $taken = pageSlugPlan('o-nas', 'Заголовок', static fn (string $candidate): bool => true);

        self::assertSame('', $taken['slug']);
        self::assertStringContainsString('адрес', $taken['error']);
    }

    public function testЗарезервированныйСписокПокрываетСлужебныеПутиЯдра(): void
    {
        // Первый сегмент страницы не может занять вход, админку, отдаваемые файлами каталоги
        // и префиксы разделов: точный маршрут всегда выигрывает, и страница была бы недоступна.
        foreach (['admin', 'login', 'logout', 'news', 'shop', 'commerce', 'classifieds', 'seo', 'assets', 'uploads'] as $slug) {
            self::assertContains($slug, PAGES_RESERVED_SLUGS, 'Резерв без сегмента ' . $slug);
        }
    }

    public function testВидыПервогоЗаходаСперечнемИмен(): void
    {
        self::assertSame(
            ['hero', 'band', 'cards', 'steps', 'prose', 'cta', 'faq'],
            PAGES_KINDS,
            'Решение 3 штурма: каркас + семь видов, остальные добираются пачками'
        );

        foreach (PAGES_KINDS as $kind) {
            self::assertArrayHasKey($kind, PAGES_KIND_NAMES, 'У вида без имени нет подписи в конструкторе');
        }
    }

    public function testПределыПолейЧитаютсяОднимСпособом(): void
    {
        // Как у новостей: предел читает и подпись формы, и сервер, чтобы подпись не расходилась
        // с проверкой. Числовые записи — пределы списков, парные — min/max строк.
        self::assertSame(40, fieldLimit(PAGES_FIELDS, 'items'));
        self::assertSame(120, fieldLimit(PAGES_FIELDS, 'title', 'max'));
        self::assertSame(80, PAGES_SLUG_MAX, 'Размер колонки slug держит константа, а не магическое число');
        self::assertSame(40, PAGES_SECTIONS_LIMIT, 'Предел секций на страницу — константа решения §3 спецификации');
    }

    public function testТранслитЯдраСовпадаетСПеренесённымИзПортала(): void
    {
        // Список перенесён из портала без изменений (apps/news/lib/slug.php): при переносе
        // содержимого из PostgreSQL адреса страниц обязаны совпасть с адресами портала.
        self::assertSame('zhizn-shkoly', slugify('Жизнь школы', 80));
        self::assertSame('metodika', slugify('Методика', 80));
        // Предел обрезает хвост и снимает оборванный дефис.
        $long = slugify('аб' . str_repeat('-аб', 60), 80);

        self::assertLessThanOrEqual(80, mb_strlen($long));
        self::assertStringEndsNotWith('-', $long);
    }

    public function testПомощникиСекцийНормализуютСпискиИОтбрасываютПустое(): void
    {
        // Лид секции — абзацы через двойной перенос; пустые отрезки и одиночные переносы
        // не создают пустых <p>.
        self::assertSame(
            ['Первый абзац', 'Второй'],
            pagesParagraphs("  Первый абзац \n\n Второй\n\n\n\n"),
            'пустые отрезки отфильтрованы'
        );
        self::assertSame([], pagesParagraphs('   '), 'пустой лид — пустой список');

        // Кнопки без подписи или адреса не печатаются вовсе; blank приводится к булеву.
        $buttons = pagesButtonsOf([
            ['label' => 'Записаться', 'href' => '/o-nas'],
            ['label' => 'Чужой сайт', 'href' => 'https://example.com', 'blank' => '1'],
            ['label' => 'Без адреса', 'href' => ''],
            ['label' => '', 'href' => '/news'],
            'не массив',
        ]);

        self::assertSame(
            [
                ['label' => 'Записаться', 'href' => '/o-nas', 'blank' => false],
                ['label' => 'Чужой сайт', 'href' => 'https://example.com', 'blank' => true],
            ],
            $buttons
        );

        // JSON-LD FAQPage собирается из полных пар; вопрос без ответа в разметку не идёт.
        $ld = pagesFaqLd([
            ['title' => 'Сколько длится урок?', 'text' => '60 минут.'],
            ['title' => 'Есть ли скидки?', 'text' => ''],
        ]);

        self::assertStringContainsString('FAQPage', $ld);
        self::assertStringContainsString('Сколько длится урок?', $ld);
        self::assertStringContainsString('60 минут.', $ld);
        self::assertStringNotContainsString('Есть ли скидки?', $ld, 'пустая пара не попадает в JSON-LD');
        self::assertSame('', pagesFaqLd([]), 'без пар фрагмент пуст — разметки ld+json не будет');

        // Повторяющиеся items: битые строки отбрасываются, шаблон получает список массивов
        // и не ветвится на «не массив» посреди цикла.
        self::assertSame(
            [['title' => 'Раз', 'text' => 'Один']],
            pagesItemsOf([['title' => 'Раз', 'text' => 'Один'], 'не массив', 5]),
            'битые строки items отбрасываются'
        );
        self::assertSame([], pagesItemsOf('строка'));

        // Строки повторяющихся полей формы: поля подрезаются, пустые строки отбрасываются целиком
        // («стереть кнопку» значит «очистить её поля»).
        self::assertSame(
            [
                ['label' => 'Записаться', 'href' => '/o-nas', 'blank' => ''],
                ['label' => 'Внешняя', 'href' => 'https://example.com', 'blank' => '1'],
            ],
            pagesFormRows([
                ['label' => ' Записаться ', 'href' => '/o-nas', 'blank' => ''],
                ['label' => '', 'href' => ''],
                'не массив',
                ['label' => 'Внешняя', 'href' => 'https://example.com', 'blank' => '1'],
            ], ['label', 'href', 'blank'])
        );
        self::assertSame([], pagesFormRows('строка', ['label']));

        // Поля настроек вклада: ключ обязателен, битые записи описания пропускаются.
        self::assertSame(
            [['key' => 'count', 'label' => 'Сколько материалов', 'hint' => 'От 1 до 12.']],
            pagesFieldsOf([
                ['key' => 'count', 'label' => 'Сколько материалов', 'hint' => 'От 1 до 12.'],
                'не массив',
                ['label' => 'Без ключа'],
            ]),
            'описания без ключа не попадают в форму конструктора'
        );
        self::assertSame([], pagesFieldsOf(null), 'вклад без описания полей не рисует настроек');

        // Настройки вклада: скаляры едут строками как есть, вложенные массивы подделанного
        // POST отбрасываются — вклад получает то, что рисует форма.
        self::assertSame(
            ['count' => '2', 'category' => 'postuplenie'],
            pagesSettingsOf(['count' => ' 2 ', 'category' => 'postuplenie', 'layout' => ['x']]),
            'вложенные значения настроек отбрасываются'
        );
        self::assertSame([], pagesSettingsOf('строка'));
    }
}
