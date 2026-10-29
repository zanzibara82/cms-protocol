<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Разметка Schema.org раздела «SEO и статистика»: проверка формы, сохранение и сбор объекта.
 *
 * Набор проверяет обещания раздела: печатается ровно то, что заведено, — без догадок о
 * содержимом страницы, один адрес — одна разметка, а картинка и каноник ведут только на наш
 * сайт.
 */
final class SeoSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    public static function tearDownAfterClass(): void
    {
        run('DROP TABLE IF EXISTS ' . t('seo_schema'));
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('seo_schema'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function form(array $overrides = []): array
    {
        return array_merge([
            'path' => '/obhod',
            'schema_type' => 'WebPage',
            'name' => 'Страница обхода',
            'description' => 'Описание страницы обхода.',
            'image' => '',
            'author' => '',
            'date_published' => '',
            'date_modified' => '',
        ], $overrides);
    }

    // ── Проверка формы ─────────────────────────────────────

    public function testПустойАдресОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['path' => '']));

        self::assertNotSame([], $check['errors']);
    }

    public function testАдресБезВедущегоСлэшаОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['path' => 'obhod']));

        self::assertArrayHasKey('path', $check['errors']);
    }

    public function testНеизвестныйТипОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['schema_type' => 'Product']));

        self::assertArrayHasKey('schema_type', $check['errors'], 'Набор типов ограничен теми, чьи поля мы правда умеем заполнить');
    }

    public function testБезНазванияОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['name' => '']));

        self::assertArrayHasKey('name', $check['errors']);
    }

    public function testКартинкаСЧужогоСайтаОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['image' => 'https://drugoy-site.dev/kartinka.png']));

        self::assertArrayHasKey('image', $check['errors']);
    }

    public function testКартинкаНашегоСайтаПроходит(): void
    {
        $check = seoSchemaValidate(self::form(['image' => '/assets/img/brand/og-default.png']));

        self::assertSame([], $check['errors']);
    }

    public function testПолныйАдресКартинкиПриводитсяКПути(): void
    {
        $check = seoSchemaValidate(self::form(['image' => absoluteUrl('/assets/img/brand/og-default.png')]));

        self::assertSame([], $check['errors'], 'Полный адрес нашего домена — та же картинка, а не чужой сайт');
        self::assertSame('/assets/img/brand/og-default.png', (string) $check['values']['image']);
    }

    public function testНевозможнаяДатаОтвергается(): void
    {
        $check = seoSchemaValidate(self::form(['date_published' => '2026-02-31']));

        self::assertArrayHasKey('date_published', $check['errors'], 'Такой даты в календаре нет');
    }

    public function testДатаВНашемФорматеПроходит(): void
    {
        $check = seoSchemaValidate(self::form(['date_published' => '2026-02-28']));

        self::assertSame([], $check['errors']);
    }

    public function testСлужебныйАдресВРазметкуНеПопадает(): void
    {
        $check = seoSchemaValidate(self::form(['path' => '/admin']));

        self::assertArrayHasKey('path', $check['errors']);
    }

    // ── Сохранение ─────────────────────────────────────────

    public function testРазметкаСохраняетсяИЧитается(): void
    {
        $check = seoSchemaValidate(self::form());
        $result = seoSchemaSave($check['values']);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame('schema_saved', (string) $result['notice']);

        $row = seoSchemaByPath('/obhod');
        self::assertIsArray($row);
        self::assertSame('Страница обхода', (string) $row['name']);
    }

    public function testОдинАдресОднаРазметка(): void
    {
        seoSchemaSave(seoSchemaValidate(self::form())['values']);
        seoSchemaSave(seoSchemaValidate(self::form(['name' => 'Другое название']))['values']);

        self::assertCount(1, seoSchemaList(), 'Вторая разметка того же адреса заменила бы первую двумя сущностями на одной странице');
        self::assertSame('Другое название', (string) (seoSchemaByPath('/obhod')['name'] ?? ''));
    }

    public function testРазметкаУдаляется(): void
    {
        seoSchemaSave(seoSchemaValidate(self::form())['values']);
        $row = seoSchemaByPath('/obhod');
        self::assertIsArray($row);

        $result = seoSchemaDelete((string) $row['id']);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertNull(seoSchemaByPath('/obhod'));
    }

    public function testДообновлениеПослеПроиграннойГонки(): void
    {
        // Гонку вставки в тесте не поднять (победитель обязан закоммитить между SELECT и INSERT
        // того же процесса), поэтому код гонки-проигравшего проверяется своей рукой: дообновление
        // должно перезаписать строку победителя и сдвинуть updated_at, не заводя вторую строку.
        seoSchemaSave(seoSchemaValidate(self::form())['values']);
        $winner = seoSchemaByPath('/obhod');
        self::assertIsArray($winner);

        seoSchemaRowUpdate($winner, [
            'schema_type' => 'Article',
            'name' => 'Дообновлённая разметка',
            'description' => null,
            'image' => null,
            'author' => null,
            'date_published' => null,
            'date_modified' => null,
            'updated_at' => utcStamp(nowUtc()),
        ]);

        // Строка в справочнике одна, значения формы переписали строку победителя: тем самым
        // рука гонки-проигравшего доезжает до базы (updated_at держит секундная точность,
        // сравнение дат внутри одной секунды ничего не доказывает).
        self::assertCount(1, seoSchemaList(), 'Дообновление не заводит вторую разметку');
        self::assertSame('Дообновлённая разметка', (string) (seoSchemaByPath('/obhod')['name'] ?? ''));
        self::assertSame('Article', (string) (seoSchemaByPath('/obhod')['schema_type'] ?? ''));
    }

    public function testУдалениеНесуществующейРазметкиДаётОтказ(): void
    {
        $result = seoSchemaDelete('net-takoy-stroki');

        self::assertFalse($result['ok']);
        self::assertNotSame('', (string) $result['error']);
    }

    // ── Сбор объекта ───────────────────────────────────────

    public function testОбъектСобираетсяИзЗаведённыхПолей(): void
    {
        $item = seoSchemaItem([
            'path' => '/obhod',
            'schema_type' => 'Article',
            'name' => 'Статья обхода',
            'description' => 'Описание статьи.',
            'image' => '/assets/img/brand/og-default.png',
            'author' => 'Иван Редактор',
            'date_published' => '2026-10-01',
            'date_modified' => '2026-10-21',
        ]);

        self::assertSame('https://schema.org', (string) $item['@context']);
        self::assertSame('Article', (string) $item['@type']);
        self::assertSame('Статья обхода', (string) $item['name']);
        self::assertSame('Описание статьи.', (string) $item['description']);
        self::assertSame(
            absoluteUrl('/assets/img/brand/og-default.png'),
            (string) $item['image'],
            'Картинка в разметке — полный адрес: поисковик ждёт ссылку, а не путь от корня'
        );
        self::assertSame('2026-10-01', (string) $item['datePublished']);
        self::assertSame('2026-10-21', (string) $item['dateModified']);
        self::assertSame('Иван Редактор', (string) ($item['author']['name'] ?? ''));
        self::assertStringEndsWith('/obhod', (string) $item['url']);
    }

    public function testПустыеПоляВОбъектНеПопадают(): void
    {
        $item = seoSchemaItem([
            'path' => '/obhod',
            'schema_type' => 'WebPage',
            'name' => 'Страница обхода',
            'description' => '',
            'image' => '',
            'author' => '',
            'date_published' => '',
            'date_modified' => '',
        ]);

        self::assertArrayNotHasKey('description', $item, 'Пустое поле в разметке хуже отсутствующего: оно читается как заявка');
        self::assertArrayNotHasKey('image', $item);
        self::assertArrayNotHasKey('author', $item);
    }

    public function testУОрганизацииКартинкаЭтоЛоготип(): void
    {
        $item = seoSchemaItem([
            'path' => '/obhod',
            'schema_type' => 'Organization',
            'name' => 'Школа',
            'description' => '',
            'image' => '/assets/img/brand/og-default.png',
            'author' => '',
            'date_published' => '',
            'date_modified' => '',
        ]);

        self::assertArrayHasKey('logo', $item);
        self::assertSame(absoluteUrl('/assets/img/brand/og-default.png'), (string) $item['logo']);
        self::assertArrayNotHasKey('image', $item);
        self::assertArrayNotHasKey('author', $item, 'У организации нет поля автора — одна форма на все типы означала бы пустые поля');
    }

    public function testПредпросмотрЭтоТотЖеJson(): void
    {
        $preview = seoSchemaPreview([
            'path' => '/obhod',
            'schema_type' => 'WebPage',
            'name' => 'Страница обхода',
            'description' => '',
            'image' => '',
            'author' => '',
            'date_published' => '',
            'date_modified' => '',
        ]);

        self::assertNotSame('', $preview);
        self::assertStringContainsString('"@type": "WebPage"', $preview);
    }
}
