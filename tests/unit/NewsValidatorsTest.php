<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NewsValidatorsTest extends TestCase
{
    public function testАбзацыРазбираютсяПоПустымСтрокам(): void
    {
        self::assertSame(
            [
                ['title' => '', 'text' => 'Первый абзац.'],
                ['title' => '', 'text' => 'Второй абзац.'],
            ],
            parseBody("Первый абзац.\n\nВторой абзац.")
        );
    }

    public function testЗаголовокАбзацаЧитаетсяИзСтрокиСРешётками(): void
    {
        self::assertSame(
            [['title' => 'Подготовка', 'text' => 'Текст абзаца.']],
            parseBody("## Подготовка\nТекст абзаца.")
        );
    }

    public function testПустыеСтрокиВНачалеБлокаНеМешаютЗаголовку(): void
    {
        self::assertSame(
            [['title' => 'Заголовок', 'text' => 'Текст']],
            parseBody("\n\n## Заголовок\nТекст")
        );
    }

    public function testПустойТекстНеДаётАбзацев(): void
    {
        self::assertSame([], parseBody(''));
        self::assertSame([], parseBody("\n\n\n"));
        self::assertSame([], parseBody("   \n  "));
    }

    public function testАбзацевНеБольшеПредела(): void
    {
        $raw = implode("\n\n", array_map(static fn (int $n): string => 'Абзац ' . $n, range(1, 60)));

        self::assertCount(fieldLimit(NEWS_FIELDS, 'paragraphs'), parseBody($raw));
    }

    public function testТекстАбзацаОбрезаетсяПоПределу(): void
    {
        $paragraphs = parseBody(str_repeat('а', 5000));

        self::assertCount(1, $paragraphs);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'paragraphText', 'max'), mb_strlen($paragraphs[0]['text']));
    }

    public function testТелоВозвращаетсяВФормуТемЖеТекстом(): void
    {
        $body = [
            ['title' => 'Подготовка', 'text' => 'Текст'],
            ['title' => '', 'text' => 'Просто абзац'],
            ['title' => '', 'text' => '   '],
        ];

        self::assertSame("## Подготовка\nТекст\n\nПросто абзац", bodyToFormText($body));
        self::assertSame('', bodyToFormText(null));
        self::assertSame('', bodyToFormText([]));
        self::assertSame('', bodyToFormText('не массив'));
    }

    public function testВыделениеЗвёздочкамиРазбираетсяНаЧасти(): void
    {
        self::assertSame(
            [
                ['text' => 'Обычный ', 'strong' => false],
                ['text' => 'важный', 'strong' => true],
                ['text' => ' текст', 'strong' => false],
            ],
            splitBold('Обычный **важный** текст')
        );
        self::assertSame([['text' => 'Без выделения', 'strong' => false]], splitBold('Без выделения'));
        self::assertSame([], splitBold(''));
    }

    public function testСписокТеговЧиститсяОтПовторовИПробелов(): void
    {
        // Регистр сохраняется как ввёл человек, а сравнивается в нижнем: «ВЫПУСКНИК» не заводит
        // второй тег рядом с «Выпускник».
        self::assertSame(
            ['Выпускник', 'олимпиада', 'Школа'],
            parseTagList('  Выпускник ,, олимпиада , Школа ,ВЫПУСКНИК')
        );
        self::assertSame([], parseTagList(''));
        self::assertSame([], parseTagList(',,, ,'));
        self::assertSame([], parseTagList('а,б'));
    }

    public function testТеговНеБольшеПределаНаМатериал(): void
    {
        $raw = implode(',', array_map(static fn (int $n): string => 'тег' . $n, range(1, 30)));

        self::assertCount(fieldLimit(TAG_FIELDS, 'perItem'), parseTagList($raw));
    }

    public function testСрокПубликацииЧитаетсяКакМосковскоеВремя(): void
    {
        $parsed = parsePublishedAt('2026-10-07T09:00');

        self::assertArrayNotHasKey('error', $parsed);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed['date']);
        self::assertSame('2026-10-07 06:00:00', utcStamp($parsed['date']));
    }

    public function testПустойСрокОзначаетПубликациюБезОтсрочки(): void
    {
        $parsed = parsePublishedAt('   ');

        self::assertArrayNotHasKey('error', $parsed);
        self::assertNull($parsed['date']);
    }

    public function testСрокНеПоФорматуПоляОтклоняется(): void
    {
        $cases = ['07.10.2026 09:00', '2026-10-07', '2026-10-07T09:00:00Z', 'завтра', '2026-10-07 09:00'];

        foreach ($cases as $raw) {
            $parsed = parsePublishedAt($raw);

            self::assertSame(
                'Срок публикации нужно выбрать в поле даты и времени — например, 04.10.2026 09:00.',
                (string) ($parsed['error'] ?? ''),
                $raw
            );
        }
    }

    public function testНебывалыйМоментОтклоняется(): void
    {
        $parsed = parsePublishedAt('2026-13-45T09:00');

        self::assertSame('Такого момента не бывает — проверьте дату и время.', (string) ($parsed['error'] ?? ''));
    }

    public function testСохранённыйСрокВозвращаетсяВПолеФормы(): void
    {
        $parsed = parsePublishedAt('2026-10-07T09:00');

        self::assertInstanceOf(DateTimeImmutable::class, $parsed['date']);
        self::assertSame('2026-10-07T09:00', formPublishedAt($parsed['date']));
        self::assertSame('', formPublishedAt(null));
    }

    public function testКартинкойБываетТолькоПутьВнутриСайта(): void
    {
        self::assertSame('/uploads/news/1.jpg', imagePath('/uploads/news/1.jpg'));
        self::assertNull(imagePath('https://chuzhoj.ru/1.jpg'));
        self::assertNull(imagePath('//chuzhoj.ru/1.jpg'));
        self::assertNull(imagePath('uploads/news/1.jpg'));
        self::assertNull(imagePath('   '));
        self::assertNull(imagePath('/uploads/../../../etc/passwd'));
        self::assertNull(imagePath('/../etc/passwd'));
        self::assertNull(imagePath('/uploads//../../x'));
        self::assertNull(imagePath('/uploads/./1.jpg'));
        self::assertNull(imagePath('/uploads/%2e%2e/etc/passwd'));
        self::assertNull(imagePath('/uploads/%2E%2E/etc/passwd'));
        self::assertNull(imagePath('/uploads/a\\b.jpg'));
        self::assertNull(imagePath('/uploads/a' . chr(0) . 'b.jpg'));
        self::assertNull(imagePath("/uploads/a\nb.jpg"));
        self::assertNull(imagePath("/uploads/a\tb.jpg"));
        self::assertNull(imagePath('/uploads/news/../secret.php'));
        self::assertNull(imagePath('/uploads/..'));
        self::assertSame('/uploads/news-2026.photo.jpg', imagePath('/uploads/news-2026.photo.jpg'));
        self::assertSame('/a.b/c.jpg', imagePath('/a.b/c.jpg'));
        self::assertSame(
            fieldLimit(NEWS_FIELDS, 'image', 'max'),
            mb_strlen((string) imagePath('/' . str_repeat('a', 400)))
        );
    }

    public function testПоискОбрезаетсяДоПредела(): void
    {
        self::assertSame('олимпиада', parseQuery('  олимпиада  '));
        self::assertSame('', parseQuery(null));
        self::assertSame(SEARCH_MAX_LENGTH, mb_strlen(parseQuery(str_repeat('ы', 300))));
    }

    public function testСтатусРешаетсяПоКнопкеИлиПоИзвестномуЗначению(): void
    {
        self::assertSame('PUBLISHED', resolveStatus('publish', 'DRAFT'));
        self::assertSame('DRAFT', resolveStatus('draft', 'PUBLISHED'));
        self::assertSame('PUBLISHED', resolveStatus('', 'PUBLISHED'));
        self::assertSame('DRAFT', resolveStatus('', 'DRAFT'));
        self::assertSame('DRAFT', resolveStatus('', 'НЕИЗВЕСТНО'));
        self::assertSame('PUBLISHED', resolveStatus('неизвестная', 'PUBLISHED'));
    }

    public function testФормаСобираетсяЦеликом(): void
    {
        $data = $this->data($this->form());

        self::assertSame('Выпускники собрались на вечер встречи', $data['title']);
        self::assertSame('vecher-vstrechi', $data['slug']);
        self::assertSame('Короткий лид.', $data['lead']);
        self::assertSame('/uploads/news/cover.jpg', $data['cover']);
        self::assertSame('kategoriya-1', $data['categoryId']);
        self::assertSame([['title' => 'Подготовка', 'text' => 'Текст абзаца.']], $data['body']);
        self::assertSame(['Выпускник', 'олимпиада'], $data['tags']);
        self::assertSame('PUBLISHED', $data['status']);
        self::assertInstanceOf(DateTimeImmutable::class, $data['publishedAt']);
        self::assertSame('2026-10-07 06:00:00', utcStamp($data['publishedAt']));
        self::assertTrue($data['pinned']);
        self::assertSame('Вечер встречи выпускников', $data['seoTitle']);
        self::assertSame('Описание для поисковика.', $data['seoDescription']);
        self::assertSame('/uploads/news/og.jpg', $data['ogImage']);
        self::assertFalse($data['noindex']);
    }

    public function testЧекбоксыЧитаютсяПравиломЯдра(): void
    {
        $form = $this->form();
        $form['pinned'] = '';
        $form['noindex'] = '1';
        $data = $this->data($form);

        self::assertFalse($data['pinned']);
        self::assertTrue($data['noindex']);
    }

    public function testОтказыФормыИдутВПорядкеПолей(): void
    {
        $form = $this->form();
        $form['title'] = 'Не';
        $form['slug'] = 'admin';
        $form['categoryId'] = '';
        $form['publishedAt'] = 'завтра';

        $errors = $this->errors($form);

        self::assertSame(['title', 'slug', 'categoryId', 'publishedAt'], array_keys($errors));
        self::assertSame(
            'Заголовок короче 3 символов — по нему новость находят в ленте и в поиске.',
            $errors['title']
        );
        self::assertSame('Адрес «admin» занят служебной страницей раздела — выберите другой.', $errors['slug']);
        self::assertSame('Выберите рубрику: без неё новость не попадёт ни в один раздел.', $errors['categoryId']);
    }

    public function testСлишкомДлинныеПоляОтклоняютсяСоСвоимПределом(): void
    {
        $form = $this->form();
        $form['title'] = str_repeat('а', 200);
        $form['lead'] = str_repeat('б', 400);
        $form['tags'] = str_repeat('в', 700);
        $form['seoTitle'] = str_repeat('г', 200);
        $form['seoDescription'] = str_repeat('д', 400);

        $errors = $this->errors($form);

        self::assertSame(['title', 'lead', 'tags', 'seoTitle', 'seoDescription'], array_keys($errors));
        self::assertSame('Заголовок длиннее 120 символов.', $errors['title']);
        self::assertSame('Лид длиннее 300 символов.', $errors['lead']);
        self::assertSame('Список тегов слишком длинный — оставьте самые важные.', $errors['tags']);
        self::assertSame('Title длиннее 120 символов.', $errors['seoTitle']);
        self::assertSame('Description длиннее 300 символов.', $errors['seoDescription']);
    }

    public function testСписокПолейФормыПолный(): void
    {
        $expected = ['title', 'slug', 'lead', 'cover', 'categoryId', 'body', 'tags', 'status',
            'publishedAt', 'pinned', 'seoTitle', 'seoDescription', 'ogImage', 'noindex'];

        self::assertSame($expected, NEWS_FORM_FIELDS);

        // Предел объявлен для всех полей, кроме двух: `body` режет parseBody() по абзацам,
        // а `slug` — slugify() по NEWS_SLUG_MAX.
        foreach (NEWS_FORM_FIELDS as $field) {
            if ($field === 'body' || $field === 'slug') {
                self::assertArrayNotHasKey($field, NEWS_FORM_MAX);

                continue;
            }

            self::assertArrayHasKey($field, NEWS_FORM_MAX, 'У поля ' . $field . ' нет предела длины');
            self::assertArrayHasKey($field, NEWS_FORM_TOO_LONG, 'У поля ' . $field . ' нет текста отказа');
        }
    }

    public function testПределыФормыНеРасходятсяСПределамиПолей(): void
    {
        self::assertSame(fieldLimit(NEWS_FIELDS, 'title', 'max'), NEWS_FORM_MAX['title']);
        // У адреса ключа в NEWS_FORM_MAX нет намеренно: предел режет slugify() по NEWS_SLUG_MAX,
        // и одна граница не должна жить в двух местах.
        self::assertArrayNotHasKey('slug', NEWS_FORM_MAX);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'lead', 'max'), NEWS_FORM_MAX['lead']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'image', 'max'), NEWS_FORM_MAX['cover']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'image', 'max'), NEWS_FORM_MAX['ogImage']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'seoTitle', 'max'), NEWS_FORM_MAX['seoTitle']);
        self::assertSame(fieldLimit(NEWS_FIELDS, 'seoDescription', 'max'), NEWS_FORM_MAX['seoDescription']);
        self::assertSame(fieldLimit(TAG_FIELDS, 'rawList'), NEWS_FORM_MAX['tags']);
    }

    public function testКнопкиФормыЗнаютОбаСтатуса(): void
    {
        self::assertSame(['draft', 'publish'], array_keys(NEWS_STATUS_BY_INTENT));
        self::assertSame(['DRAFT', 'PUBLISHED'], array_values(NEWS_STATUS_BY_INTENT));
    }

    public function testТекстОтказаСобираетсяИзПределаПоля(): void
    {
        self::assertSame('Заголовок длиннее 120 символов.', newsTooLongText('title'));
        self::assertSame('Рубрика выбрана неверно.', newsTooLongText('categoryId'));
        self::assertSame('Значение поля слишком длинное.', newsTooLongText('несуществующее'));
    }

    public function testНестроковыеПоляФормыСтановятсяПустойСтрокой(): void
    {
        $result = parseNewsForm([
            'title' => 'Заголовок',
            'slug' => ['zagolovok'],
            'categoryId' => 'abc',
            'body' => "Текст\n\nВторой абзац",
            'tags' => ['выпускник', 'выпуск'],
            'status' => ['PUBLISHED'],
            'publishedAt' => '2026-10-04T09:00',
            'pinned' => ['on'],
            'noindex' => ['on'],
        ]);

        self::assertArrayHasKey('data', $result);
        $data = $result['data'];
        self::assertSame([], $data['tags'], 'список тегов из массива пуст');
        self::assertSame('DRAFT', $data['status'], 'статус из массива не публикуется и остаётся черновиком');
        self::assertFalse($data['pinned']);
        self::assertFalse($data['noindex']);
        self::assertSame('', (string) $data['slug'], 'адрес из массива становится пустым, а не словом Array');
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'title' => '  Выпускники   собрались на вечер встречи ',
            'slug' => ' Вечер встречи ',
            'lead' => " Короткий\nлид. ",
            'cover' => '/uploads/news/cover.jpg',
            'categoryId' => 'kategoriya-1',
            'body' => "## Подготовка\nТекст абзаца.",
            'tags' => 'Выпускник, олимпиада',
            'status' => 'PUBLISHED',
            'publishedAt' => '2026-10-07T09:00',
            'pinned' => 'on',
            'seoTitle' => 'Вечер встречи выпускников',
            'seoDescription' => 'Описание для поисковика.',
            'ogImage' => '/uploads/news/og.jpg',
            'noindex' => '',
        ];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function data(array $form): array
    {
        $result = parseNewsForm($form);

        if (!isset($result['data'])) {
            self::fail('Форма не собралась: ' . json_encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE));
        }

        return (array) $result['data'];
    }

    /**
     * @param array<string, string> $form
     * @return array<string, string>
     */
    private function errors(array $form): array
    {
        $result = parseNewsForm($form);

        if (!isset($result['errors'])) {
            self::fail('Форма собралась, хотя должна была отказать.');
        }

        return (array) $result['errors'];
    }
}
