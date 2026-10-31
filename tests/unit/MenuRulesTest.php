<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Чистые правила слоя меню: валидатор адреса пункта (формы — как у портала), нормализация строки
 * формы, группировка колонок подвала, сбор ссылок разделов из манифестов. Базы нет: правила
 * не ходят в неё сами — включённость приложения сюда приносит вызывающий.
 */
final class MenuRulesTest extends TestCase
{
    public function testСлотыИПределыМенюЗафиксированы(): void
    {
        // Два слота, как у портала: подпись и порядок живут внутри слота, иерархии нет.
        self::assertSame(['HEADER', 'FOOTER'], MENU_SLOTS);
        self::assertSame('Ссылки', MENU_SECTION_FALLBACK);

        // Пределы полей читаются справочником через fieldLimit(): label/section/href короче
        // и длиннее, sort — порядок без нумерации свыше.
        self::assertSame(60, fieldLimit(MENU_FIELDS, 'label', 'max'));
        self::assertSame(60, fieldLimit(MENU_FIELDS, 'section', 'max'));
        self::assertSame(300, fieldLimit(MENU_FIELDS, 'href', 'max'));
        self::assertSame(999, fieldLimit(MENU_FIELDS, 'sort'));
    }

    public function testАдресПунктаПроходитТолькоВРазборныхФормах(): void
    {
        // Шесть разобранных форм: якорь секции той же страницы, раздел сайта, внешняя ссылка,
        // почта, телефон и файл в папке сайта (у пути есть точка — разделы сайта точек не прощают).
        $valid = [
            '#levels',
            '#faq-2',
            '/news',
            '/',
            '/news/2026/item',
            'https://school.ru/page?course=en&level=1',
            'http://school.example/ru',
            'mailto:team@school.ru',
            'tel:+7 (495) 123-45-67',
            '/files/lesson1.pdf',
            '/uploads/images/full-photo.png',
        ];
        foreach ($valid as $href) {
            self::assertTrue(menuHrefValid($href), 'Валидный адрес отвергнут: ' . $href);
        }

        // Кривые адреса — всё, что вывело бы посетителя на чужую механику или просто никуда:
        // схема не из перечня, пустой якорь, короткий телефон, адрес без доменной части,
        // пробел внутри пути файла и протокол-относительный //host (уходит на чужой домен).
        $broken = [
            '',
            'javascript:alert(1)',
            'data:text/html;base64,AAAA',
            'ftp://files.school.ru/list.pdf',
            'SMTP:team@school.ru',
            '#',
            'tel:9',
            'mailto:only-name',
            '/files/a b.pdf',
            '//host/page',
        ];
        foreach ($broken as $href) {
            self::assertFalse(menuHrefValid($href), 'Кривой адрес пропущен: ' . $href);
        }
    }

    public function testСтрокаФормыНормализуетсяИОтказываетПоЧеловечески(): void
    {
        // Полная строка подвала: края обрезаются, заготовленный раздел и видимость остаются.
        $good = menuItemValuesOf([
            'slot' => 'FOOTER',
            'section' => ' О школе ',
            'label' => ' Контакты ',
            'href' => ' /contacts ',
            'blank' => '1',
        ]);

        self::assertSame('', $good['error']);
        self::assertSame('FOOTER', $good['slot']);
        self::assertSame('О школе', $good['section']);
        self::assertSame('Контакты', $good['label']);
        self::assertSame('/contacts', $good['href']);
        self::assertSame(1, $good['blank']);

        // Чекбокс unchecked ('0') ведёт к 0; отсутствие флага — тоже 0 (новый пункт из формы).
        self::assertSame(0, menuItemValuesOf(['slot' => 'FOOTER', 'label' => 'А', 'href' => '/a', 'blank' => '0'])['blank']);
        self::assertSame(0, menuItemValuesOf(['slot' => 'HEADER', 'label' => 'А', 'href' => '/a'])['blank']);
        self::assertSame(1, menuItemValuesOf(['slot' => 'FOOTER', 'label' => 'А', 'href' => '/a', 'blank' => true])['blank']);

        // У шапки раздела нет: подпись колонки пришла из кривой формы — она уходит молча.
        self::assertSame('', menuItemValuesOf(['slot' => 'HEADER', 'section' => 'О школе', 'label' => 'А', 'href' => '/a'])['section']);

        // Длинные поля обрезаются до пределов справочника, не ломая адрес.
        $longLabel = mb_substr(str_repeat('н', 90), 0, 90);
        $longName = menuItemValuesOf(['slot' => 'HEADER', 'label' => $longLabel, 'href' => '/a']);
        self::assertSame(60, mb_strlen($longName['label']));
        self::assertSame('', $longName['error']);

        $longHref = '/' . str_repeat('p', 320);
        $cut = menuItemValuesOf(['slot' => 'HEADER', 'label' => 'А', 'href' => $longHref]);
        self::assertSame(300, mb_strlen($cut['href']));
        self::assertSame('', $cut['error']);

        // Пустая подпись и пустой адрес — человеческий отказ, без записи в базу.
        $noLabel = menuItemValuesOf(['slot' => 'HEADER', 'label' => '   ', 'href' => '/a']);
        self::assertSame('', $noLabel['label']);
        self::assertNotSame('', $noLabel['error']);

        $noHref = menuItemValuesOf(['slot' => 'HEADER', 'label' => 'А', 'href' => ' ']);
        self::assertSame('', $noHref['href']);
        self::assertNotSame('', $noHref['error']);

        // Кривой адрес — отказ с перечислением разобранных форм, пункт не попадёт на сайт.
        $bad = menuItemValuesOf(['slot' => 'HEADER', 'label' => 'Платно', 'href' => 'javascript:alert(1)']);
        self::assertStringContainsString('Адрес', $bad['error']);

        // Слот не из перечня — тоже отказ: пункт записать некуда.
        $noSlot = menuItemValuesOf(['slot' => 'SIDE', 'label' => 'А', 'href' => '/a']);
        self::assertNotSame('', $noSlot['error']);
    }

    public function testКолонкиПодвалаСобираютсяПоПорядкуСтрок(): void
    {
        // Строки приходят уже отсортированными: колонка занимает место своего первого пункта,
        // одноимённые подписи складываются в одну колонку, пустая — в запасную «Ссылки».
        $groups = menuFooterGroups([
            ['section' => 'Курсы', 'label' => 'Английский', 'href' => '/en', 'blank' => false],
            ['section' => 'Курсы', 'label' => 'Немецкий', 'href' => '/de', 'blank' => true],
            ['section' => '', 'label' => 'Карта сайта', 'href' => '/sitemap', 'blank' => false],
            ['section' => 'Контакты', 'label' => 'Телефоны', 'href' => 'tel:+7 (495) 000-00-00', 'blank' => false],
        ]);

        self::assertSame(
            ['Курсы', MENU_SECTION_FALLBACK, 'Контакты'],
            array_column($groups, 'title')
        );
        self::assertCount(2, $groups[0]['links']);
        self::assertSame(['Английский', 'Немецкий'], array_column($groups[0]['links'], 'label'));
        self::assertTrue($groups[0]['links'][1]['blank'], 'Флаг «в новом окне» терять нельзя');
        self::assertSame('Карта сайта', $groups[1]['links'][0]['label']);
    }

    public function testСсылкиРазделовБерутсяИзВключённыхМанифестов(): void
    {
        // Включённое приложение отдаёт навигацию манифеста: пары «адрес — подпись»,
        // «в новом окне» разделам сайта не нужен.
        $news = ['id' => 'news', 'publicNav' => [['/news', ' Новости ']]];
        self::assertSame(
            [['href' => '/news', 'label' => 'Новости', 'blank' => false]],
            menuPublicLinksOf($news, true)
        );

        // Выключенное приложение ссылки не отдаёт: пункт вёл бы на 404.
        self::assertSame([], menuPublicLinksOf($news, false));

        // Битые записи прочь: не пара, не строки, пустая подпись, пустой и кривой адрес.
        $mangled = [
            ['/news'],
            ['/news', 'Новости', 'лишнее'],
            ['/news', ''],
            ['', 'Новости'],
            ['javascript:alert(1)', 'Новости'],
            'не массив',
        ];
        self::assertSame([], menuPublicLinksOf(['id' => 'news', 'publicNav' => $mangled], true));

        // Навигации нет — ссылок нет.
        self::assertSame([], menuPublicLinksOf(['id' => 'seo'], true));
    }
}
