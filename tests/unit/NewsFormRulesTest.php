<?php

declare(strict_types=1);

// Подпространство набора — со строчной буквы: PSR-4 (Cms\Tests\ → tests/) ищет файл tests/unit/,
// а на Linux регистр в пути значим, поэтому «Unit» здесь означало бы «класс не найден».

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила экрана формы проверяются без базы и без HTTP: подписи кнопок, порядок и форма значений,
// разбор присланного файла. Перенос файла на диск проверяется набором `http` — только там
// файл приходит настоящим multipart-запросом и `is_uploaded_file()` отвечает правду.
final class NewsFormRulesTest extends TestCase
{
    // Корень проекта берётся вызовом `dirname()` на месте, а не константой класса: PHP не вычисляет
    // функцию в выражении константы (`Fatal error: Constant expression contains invalid operations`),
    // а остальные наборы этого проекта пишут `dirname(__DIR__, 2)` прямо в коде проверки.

    // ── Кнопки ────────────────────────────────────────────

    public function testКнопкиНовогоМатериала(): void
    {
        $buttons = newsFormButtons('');

        self::assertSame(['draft', 'publish'], array_column($buttons, 'intent'), 'Черновик обязан стоять первым в разметке');
        self::assertSame('Сохранить черновик', $buttons[0]['label']);
        self::assertSame('На печать', $buttons[1]['label']);
        self::assertSame('primary', $buttons[1]['tone']);
        self::assertSame('plain', $buttons[0]['tone']);
    }

    public function testКнопкиЧерновикаСовпадаютСНовымМатериалом(): void
    {
        self::assertSame(newsFormButtons(''), newsFormButtons(NEWS_STATUS['DRAFT']));
    }

    public function testКнопкиОпубликованногоМатериалаМеняютСмысл(): void
    {
        $buttons = newsFormButtons(NEWS_STATUS['PUBLISHED']);

        // На сайте: «черновая» кнопка снимает материал, а печатающая сохраняет как есть.
        self::assertSame('Вернуть в черновики', $buttons[0]['label']);
        self::assertSame('danger', $buttons[0]['tone']);
        self::assertSame('Сохранить', $buttons[1]['label']);
        self::assertSame('plain', $buttons[1]['tone']);
    }

    // ── Значения полей ────────────────────────────────────

    public function testПустыеЗначенияНовогоМатериалаИдутВПорядкеПолейФормы(): void
    {
        $values = newsFormValues([], []);

        self::assertSame(NEWS_FORM_FIELDS, array_keys($values), 'Шаблон печатает поля по этому же списку');
        self::assertSame(array_fill_keys(NEWS_FORM_FIELDS, ''), $values);
    }

    public function testСохранённаяСтрокаВозвращаетсяВФормуКакТекст(): void
    {
        $values = newsFormValues([
            'title' => 'Осенний бал',
            'bodyText' => "## Кому интересно\nПриходите в пятницу.",
            'publishedAt' => '2026-10-04T09:00',
            'pinned' => true,
            'noindex' => false,
            'status' => NEWS_STATUS['PUBLISHED'],
        ], []);

        self::assertSame('Осенний бал', $values['title']);
        self::assertSame("## Кому интересно\nПриходите в пятницу.", $values['body'], 'textarea читает текст абзацев, а не JSON');
        self::assertSame('2026-10-04T09:00', $values['publishedAt']);
        self::assertSame('on', $values['pinned'], 'Флаг возвращается в тот же вид, что отдаёт браузер');
        self::assertSame('', $values['noindex']);
        self::assertSame(NEWS_STATUS['PUBLISHED'], $values['status']);
    }

    public function testНабранныйТекстВажнееСохранённого(): void
    {
        // После отказа человек обязан увидеть то, что писал, а не то, что лежит в базе.
        $values = newsFormValues(
            ['title' => 'Старый заголовок'],
            ['title' => 'Новый заголовок', 'lead' => '', 'slug' => '']
        );

        self::assertSame('Новый заголовок', $values['title']);
        self::assertSame('', $values['seoTitle'], 'Не пришло — пусто, а не «undefined»');
    }

    // ── Приём картинки ────────────────────────────────────

    public function testИмяФайлаГенерируетСервер(): void
    {
        $href = newsImageHrefOf('png');

        self::assertMatchesRegularExpression('#^/uploads/images/[0-9a-z]{26}\.png$#', $href);
        self::assertNotSame($href, newsImageHrefOf('png'), 'Два вызова дают два разных имени');
    }

    public function testКаталогЗагрузокЛежитВПубличномКаталоге(): void
    {
        $dir = str_replace('\\', '/', newsUploadDir());

        self::assertStringEndsWith('/public/uploads/images', $dir);
        self::assertStringStartsWith(str_replace('\\', '/', dirname(__DIR__, 2)), $dir, 'За пределами проекта писать некуда');
    }

    public function testОтказПоКодуЗагрузкиЧеловеческий(): void
    {
        $iniSize = newsImageRefusalOf(UPLOAD_ERR_INI_SIZE);
        $partial = newsImageRefusalOf(UPLOAD_ERR_PARTIAL);

        self::assertNotSame('', $iniSize);
        self::assertNotSame($iniSize, $partial, 'Разные причины не должны звучать одинаково');
        self::assertStringNotContainsString('UPLOAD_', $iniSize, 'Константу PHP человеку показывать нельзя');
    }

    public function testФайлБольшеПятиМегабайтОтклоняется(): void
    {
        $check = newsImageInspect([
            'name' => 'bal.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/og-default.png',
            'error' => UPLOAD_ERR_OK,
            'size' => NEWS_IMAGE_MAX_BYTES + 1,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('5 МБ', $check['error']);
    }

    public function testРасширениеБерётсяИзСодержимогоАНеИзИмени(): void
    {
        // Настоящий PNG, названный `.jpg`: сохранится как `.png`, потому что расширение берётся из finfo.
        $check = newsImageInspect([
            'name' => 'bal.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => dirname(__DIR__, 2) . '/public/assets/og-default.png',
            'error' => UPLOAD_ERR_OK,
            'size' => 2048,
        ]);

        self::assertSame('png', $check['ext']);
        self::assertSame('', $check['error']);
    }

    public function testНеКартинкаОтклоняется(): void
    {
        $check = newsImageInspect([
            'name' => 'podrobnyj.php.png',
            'type' => 'image/png',
            'tmp_name' => dirname(__DIR__, 2) . '/config.sample.php',
            'error' => UPLOAD_ERR_OK,
            'size' => 512,
        ]);

        self::assertSame('', $check['ext']);
        self::assertStringContainsString('jpg, png или webp', $check['error']);
    }

    public function testУдалениеТрогаетТолькоКаталогЗагрузок(): void
    {
        $foreign = dirname(__DIR__, 2) . '/config.sample.php';
        newsImageDelete('/uploads/../config.sample.php');
        newsImageDelete('');
        newsImageDelete('/uploads/images/takogo-fajla-net.png');

        self::assertFileExists($foreign, 'Адрес вне каталога загрузок не удаляется');
    }

    public function testОдинКлючУведомленияНаКаждыйИсходФормы(): void
    {
        // Форма отвечает шестью ключами; каждый обязан быть в словаре §8.3.
        foreach (['created', 'published', 'scheduled', 'updated', 'unpublished', 'slugChanged'] as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT);
        }
    }
}
