<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Чистые правила переноса данных портала (дизайн 2026-11-11, раздел 4): роли, моменты, JSON,
 * имя пользователя, пути картинок и строка секции страницы. Базы нет — правила не ходят в неё.
 */
final class ImportTest extends TestCase
{
    public function testРолиПорталаПереводятсяВРолиЯдра(): void
    {
        self::assertSame('admin', importRole('ADMIN'));
        self::assertSame('editor', importRole('CONTENT_MANAGER'));
        self::assertSame('student', importRole('STUDENT'));
    }

    public function testНеизвестнаяРольОстанавливаетПеренос(): void
    {
        // Молчаливое понижение администратора до ученика страшнее остановки: отказ обязан быть громким.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Неизвестная роль портала');

        importRole('SUPERUSER');
    }

    public function testМоментПорталаЛожитсяВКолонкуUTSБезДолейСекунды(): void
    {
        self::assertSame('2026-10-01 05:35:04', importStamp('2026-10-01T05:35:04.744'));
        self::assertSame('2026-09-28 17:00:27', importStamp('2026-09-28T17:00:27.253'));
        self::assertSame('2026-10-04 05:34:30', importStamp('2026-10-04 05:34:30'));
    }

    public function testПустойИлиБитыйМоментДеплNullАНеТекущуюДату(): void
    {
        // Выдуманное «сейчас» исказило бы историю: колонки допускают NULL, и это честнее.
        self::assertNull(importStamp(null));
        self::assertNull(importStamp(''));
        self::assertNull(importStamp('не дата'));
        self::assertNull(importStamp('2026-02-30T10:00:00'));
    }

    public function testДеньДляКолонкиПоследнегоЗанятия(): void
    {
        self::assertSame('2026-10-04', importDay('2026-10-04T05:34:30.775'));
        self::assertNull(importDay(null));
    }

    public function testПустойСписокСтановитсяПустымJSONМассивом(): void
    {
        // Колонки CMS объявлены JSON NOT NULL: шаблон не должен ветвиться на NULL.
        self::assertSame('[]', importJsonList(null));
        self::assertSame('[]', importJsonList(''));
        self::assertSame('{}', importJsonObject(null));

        $list = importJsonList([['title' => 'Разбор', 'text' => 'Короткое объяснение']]);
        self::assertSame('[{"title":"Разбор","text":"Короткое объяснение"}]', $list);
    }

    public function testСтрокаJSONИзПорталаПринимаетсяКакСтрока(): void
    {
        // jsonb приходит от psql уже строкой, а не массивом: разбор обязан быть один.
        self::assertSame('[{"a":"б"}]', importJsonList('[{"a":"б"}]'));
        self::assertSame('{"count":3}', importJsonObject('{"count":3}'));
    }

    public function testИмяБерётсяИзПрофиляАПриПустомИзПочты(): void
    {
        self::assertSame('Алексей', importUserName('Алексей', 'alex@school.dev'));
        self::assertSame('alex', importUserName('', 'alex@school.dev'));
        self::assertSame('alex', importUserName(null, 'alex@school.dev'));
    }

    public function testПутьКартинкиПереезжаетВКаталогАссетов(): void
    {
        self::assertSame('/assets/longread/icon-goals.png', importAssetPath('/longread/icon-goals.png'));
        // Чужой адрес не переписывается: подставленная ссылка вела бы в пустоту.
        self::assertSame('https://cdn.example.com/a.png', importAssetPath('https://cdn.example.com/a.png'));
        self::assertSame('/uploads/images/x.png', importAssetPath('/uploads/images/x.png'));
        self::assertSame('', importAssetPath(null));
    }

    public function testСекцияГлавнойПолучаетПустойИдентификаторСтраницы(): void
    {
        // pageId NULL у портала — блок главной; в CMS это '', иначе уникальный ключ пропускал бы дубликаты.
        $row = importSectionRow([
            'key' => 'hero',
            'kind' => 'hero',
            'title' => 'Ваш путь',
            'lead' => "Первый абзац\n\nВторой абзац",
            'pageId' => null,
            'order' => 1,
            'visible' => true,
            'buttons' => [['label' => 'Начать', 'href' => '/register', 'blank' => false]],
            'items' => [],
        ], 'new1', '2026-11-11 10:00:00');

        self::assertSame('', $row['page_id']);
        self::assertSame('hero', $row['sec_key']);
        self::assertSame("Первый абзац\n\nВторой абзац", $row['lead_text']);
        self::assertSame(1, $row['sort']);
        self::assertSame(1, $row['visible']);
        self::assertSame('{}', $row['settings']);
        self::assertStringContainsString('"label":"Начать"', $row['buttons']);
    }

    public function testКадрыГалереиДописываютсяВItemsСКартинкойИПодписью(): void
    {
        // Отдельной колонки gallery в CMS нет: кадр — это items с image, подпись — title.
        $row = importSectionRow([
            'key' => 'demo',
            'kind' => 'demo',
            'items' => [['title' => 'Карточка слова', 'text' => 'Разбор']],
            'gallery' => [
                ['image' => '/longread/shot-word-card.png', 'text' => 'Карточка слова'],
                ['image' => '/longread/shot-quiz.png', 'text' => 'Задание'],
                ['image' => '', 'text' => ''],
            ],
            'pageId' => 'p1',
            'order' => 6,
            'visible' => true,
        ], 'new2', '2026-11-11 10:00:00');

        // Вид demo в ядре — это галерея: печатать его иначе нечем.
        self::assertSame('gallery', $row['kind']);
        self::assertSame('p1', $row['page_id']);

        /** @var list<array<string, mixed>> $items */
        $items = json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $items);
        self::assertSame('/assets/longread/shot-word-card.png', $items[1]['image']);
        self::assertSame('Карточка слова', $items[1]['title']);
        self::assertSame('Задание', $items[2]['title']);
    }

    public function testПутиКартинокВнутриItemsПереезжаютВКаталогАссетов(): void
    {
        // Слайд карусели и карточка уровня держат картинку в строке, а не в колонке. Без
        // переписывания слайд остался бы без image и вид напечатал бы пустое место.
        $row = importSectionRow([
            'key' => 'first_week',
            'kind' => 'slider',
            'items' => [
                ['image' => '/longread/shot-quiz.png', 'title' => 'Задание', 'text' => 'Проверка'],
            ],
            'pageId' => null,
            'order' => 12,
            'visible' => true,
        ], 'new4', '2026-11-11 10:00:00');

        /** @var list<array<string, mixed>> $items */
        $items = json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/assets/longread/shot-quiz.png', $items[0]['image']);
        self::assertSame('first_week', $row['sec_key']);
        self::assertSame('slider', $row['kind']);
    }

    public function testШагЦепочкиБерётПодписьИзText(): void
    {
        // У портала подпись шага лежит в text, а «Цепочка шагов» ядра читает title.
        $row = importSectionRow([
            'key' => 'repetition',
            'kind' => 'chain',
            'items' => [['text' => 'Новое слово'], ['text' => 'Первый повтор']],
            'pageId' => null,
            'order' => 10,
            'visible' => true,
        ], 'new5', '2026-11-11 10:00:00');

        /** @var list<array<string, mixed>> $items */
        $items = json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Новое слово', $items[0]['title']);
        self::assertSame('Первый повтор', $items[1]['title']);
    }

    public function testСсылкаНаВидеоБерётсяИзПодсказки(): void
    {
        // Поле video портала пустое, а путь файла упомянут в тексте подсказки.
        $row = importSectionRow([
            'key' => 'video_tour',
            'kind' => 'video',
            'items' => [[
                'title' => 'Как проходит занятие',
                'text' => 'Замените ссылку: YouTube, Vimeo или файл сайта, например /videos/promo.mp4.',
                'video' => null,
            ]],
            'pageId' => null,
            'order' => 13,
            'visible' => false,
        ], 'new6', '2026-11-11 10:00:00');

        /** @var list<array<string, mixed>> $items */
        $items = json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/videos/promo.mp4', $items[0]['video']);
        self::assertSame(0, $row['visible']);
    }

    public function testПодсказкаБезФайлаНеВыдумываетВидео(): void
    {
        $row = importSectionRow([
            'key' => 'video_tour',
            'kind' => 'video',
            'items' => [['text' => 'Настройте YouTube или Vimeo в конструкторе.']],
            'pageId' => null,
            'order' => 13,
            'visible' => true,
        ], 'new7', '2026-11-11 10:00:00');

        /** @var list<array<string, mixed>> $items */
        $items = json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $items[0]['video'] ?? '');
    }

    public function testСкрытаяСекцияОстаётсяСкрытой(): void
    {
        $row = importSectionRow(['key' => 'x', 'kind' => 'cards', 'visible' => false], 'new3', '2026-11-11 10:00:00');

        self::assertSame(0, $row['visible']);
    }

    public function testСтрокаОтчётаНазываетПричиныПропуска(): void
    {
        $line = importStatLine('User', ['read' => 9, 'written' => 8, 'skipped' => ['уже есть' => 1]]);

        self::assertSame('User: прочитано 9, перенесено 8, пропущено 1 (уже есть 1)', $line);

        $empty = importStatLine('Lead', ['read' => 0, 'written' => 0, 'skipped' => []]);
        self::assertSame('Lead: прочитано 0, перенесено 0, пропущено 0 (—)', $empty);
    }
}
