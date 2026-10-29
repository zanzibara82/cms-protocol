<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Мониторинг раздела «SEO и статистика»: журнал 404, правила перехода и защита от замыкания
 * цепочки.
 *
 * Набор проверяет обещания раздела: в журнал попадает только адрес без строки запроса и хост
 * источника без полного адреса, служебные и файловые адреса не пишутся, а правило перехода
 * не может вести сам на себя и замыкать цепочку.
 */
final class SeoMonitorTest extends TestCase
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
        foreach (['seo_404', 'seo_redirect'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('seo_404'));
        run('DELETE FROM ' . t('seo_redirect'));
        deleteOption(SEO_MONITOR_OPTION_LOG);
        unset($_SERVER['HTTP_REFERER']);
    }

    // ── Что попадает в журнал ──────────────────────────────

    public function testСлужебныеИФайловыеАдресаНеЗаписываются(): void
    {
        self::assertTrue(seoMonitorPathIsWatchable('/net-takoy-stranicy'), 'Обычная страница в журнале нужна');
        self::assertFalse(seoMonitorPathIsWatchable(''), 'Пустой адрес — не адрес');
        self::assertFalse(seoMonitorPathIsWatchable('/admin'), 'Служебный адрес ничего не говорит о содержании сайта');
        self::assertFalse(seoMonitorPathIsWatchable('/assets/app.css'), 'Запрос файла — не страница');
        self::assertFalse(seoMonitorPathIsWatchable('/' . str_repeat('a', 300)), 'Обрезанный адрес — не адрес');
    }

    public function testПовторноеОбращениеУвеличиваетСчётчик(): void
    {
        seoMonitorRecordMiss('/net-takoy-stranicy');
        seoMonitorRecordMiss('/net-takoy-stranicy');

        $rows = seoMonitorMisses();

        self::assertCount(1, $rows, 'Журнал читается как список адресов, а не как лента запросов');
        self::assertSame(2, (int) $rows[0]['hits']);
        self::assertSame('/net-takoy-stranicy', (string) $rows[0]['path']);
    }

    public function testПовторноеОбращениеНеЗатираетХостПервойВстречи(): void
    {
        // Строка «один адрес — одна строка» живёт одной записью с обновлением на дубликате:
        // select-then-insert рушился бы на гонке двух первых запросов (второй INSERT падал
        // нарушением уникального ключа — и несуществующий адрес отвечал HTTP 500). Здесь
        // проверяется и ветка дубликата: счётчик растёт, referrer первой встречи остаётся.
        $_SERVER['HTTP_REFERER'] = 'https://www.google.com/x';
        seoMonitorRecordMiss('/bitaya');

        unset($_SERVER['HTTP_REFERER']);
        seoMonitorRecordMiss('/bitaya');

        $rows = seoMonitorMisses();

        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]['hits'], 'Повторное обращение увеличивает счётчик');
        self::assertSame('google.com', (string) $rows[0]['referrer_host'], 'Хост первой встречи остаётся без перезаписи');
    }

    public function testВЖурналПишетсяТолькоХостИсточника(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://www.google.com/search?q=secret';

        seoMonitorRecordMiss('/net-takoy-stranicy');

        $rows = seoMonitorMisses();

        self::assertCount(1, $rows);
        self::assertSame('google.com', (string) $rows[0]['referrer_host'], 'Полный адрес источника в журнал не попадает');
        self::assertStringNotContainsString('secret', (string) $rows[0]['referrer_host']);
    }

    public function testСтрокиЗапросаВЖурналНеПишутся(): void
    {
        seoMonitorRecordMiss('/stranica?token=secret');

        $rows = seoMonitorMisses();

        self::assertCount(1, $rows);
        self::assertSame('/stranica', (string) $rows[0]['path'], 'Строка запроса может нести личные данные — в журнал она не попадает');
    }

    public function testЖурналЧитаетсяПоЧислуОбращений(): void
    {
        seoMonitorRecordMiss('/chastaya');
        seoMonitorRecordMiss('/redkaya');
        seoMonitorRecordMiss('/chastaya');

        $rows = seoMonitorMisses();

        self::assertSame('/chastaya', (string) $rows[0]['path'], 'Самый востребованный адрес идёт первым');
        self::assertSame('/redkaya', (string) $rows[1]['path']);
    }

    public function testОчисткаЖурналаУбираетВсё(): void
    {
        seoMonitorRecordMiss('/chastaya');
        seoMonitorRecordMiss('/redkaya');

        $removed = seoMonitorMissesClear();

        self::assertSame(2, $removed);
        self::assertSame([], seoMonitorMisses());
    }

    // ── Правила перехода ───────────────────────────────────

    public function testПравилоСохраняетсяИОбновляется(): void
    {
        $first = seoMonitorRedirectSave(['from' => '/staryy-adres', 'to' => '/novyy-adres', 'code' => 301]);
        $second = seoMonitorRedirectSave(['from' => '/staryy-adres', 'to' => '/drugoy-adres', 'code' => 302]);

        self::assertTrue($first['ok'], (string) $first['error']);
        self::assertTrue($second['ok'], (string) $second['error']);
        self::assertSame('redirect_saved', (string) $second['notice']);

        $rows = seoMonitorRedirects();

        self::assertCount(1, $rows, 'Один адрес — одно правило: вторая правка обновляет первую');
        self::assertSame('/drugoy-adres', (string) $rows[0]['to_path']);
        self::assertSame(302, (int) $rows[0]['code']);
    }

    public function testДообновлениеПравилаПослеПроиграннойГонки(): void
    {
        // Гонку вставки в тесте не поднять (победитель обязан закоммитить между SELECT и INSERT
        // того же процесса), поэтому рука гонки-проигравшего проверяется напрямую: дообновление
        // перезаписывает строку победителя и не заводит второе правило на тот же адрес.
        seoMonitorRedirectSave(['from' => '/staryy-adres', 'to' => '/novyy-adres', 'code' => 301]);
        $winner = seoMonitorRedirectByPath('/staryy-adres');
        self::assertIsArray($winner);

        seoMonitorRedirectRowUpdate($winner, ['from' => '/staryy-adres', 'to' => '/drugoy-adres', 'code' => 302]);

        $rows = seoMonitorRedirects();

        self::assertCount(1, $rows, 'Дообновление не заводит второе правило');
        self::assertSame('/drugoy-adres', (string) $rows[0]['to_path']);
        self::assertSame(302, (int) $rows[0]['code']);
    }

    public function testПравилоУдаляется(): void
    {
        seoMonitorRedirectSave(['from' => '/staryy-adres', 'to' => '/novyy-adres', 'code' => 301]);

        $row = seoMonitorRedirectByPath('/staryy-adres');
        self::assertIsArray($row);

        $result = seoMonitorRedirectDelete((string) $row['id']);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertNull(seoMonitorRedirectByPath('/staryy-adres'));
    }

    public function testРедиректНеВедётСамНаСебя(): void
    {
        $check = seoMonitorRedirectValidate(['from' => '/odin', 'to' => '/odin', 'code' => 301]);

        self::assertNotSame([], $check['errors']);
    }

    public function testРедиректНеЗамыкаетЦепочку(): void
    {
        seoMonitorRedirectSave(['from' => '/a', 'to' => '/b', 'code' => 301]);

        $check = seoMonitorRedirectValidate(['from' => '/b', 'to' => '/a', 'code' => 301]);

        self::assertNotSame([], $check['errors'], 'Цепочка «а → б → а» — это круг, а не переезд');
    }

    public function testКодОтветаТолькоПереездИлиВременно(): void
    {
        $check = seoMonitorRedirectValidate(['from' => '/a', 'to' => '/b', 'code' => 307]);

        self::assertNotSame([], $check['errors'], 'Код 307 держит метод и не говорит поисковику о переезде');

        $ok = seoMonitorRedirectValidate(['from' => '/a', 'to' => '/b', 'code' => 302]);

        self::assertSame([], $ok['errors']);
    }

    public function testСлужебныйАдресПереводитьНельзя(): void
    {
        $check = seoMonitorRedirectValidate(['from' => '/admin', 'to' => '/b', 'code' => 301]);

        self::assertNotSame([], $check['errors']);
    }

    public function testПустыеАдресаОтвергаются(): void
    {
        $check = seoMonitorRedirectValidate(['from' => '', 'to' => '', 'code' => 301]);

        self::assertNotSame([], $check['errors']);
    }

    public function testПереходВедётВнутрьНашегоСайта(): void
    {
        $check = seoMonitorRedirectValidate(['from' => '/staryy-adres', 'to' => 'https://drugoy-site.dev/stranica', 'code' => 301]);

        self::assertNotSame([], $check['errors'], 'Переход на чужой домен — это не переезд внутри сайта');

        $ok = seoMonitorRedirectValidate(['from' => '/staryy-adres', 'to' => '/novyy-adres', 'code' => 301]);

        self::assertSame([], $ok['errors']);
    }

    public function testЦепочкаСчитаетсяПоПравилам(): void
    {
        seoMonitorRedirectSave(['from' => '/a', 'to' => '/b', 'code' => 301]);
        seoMonitorRedirectSave(['from' => '/b', 'to' => '/c', 'code' => 301]);

        $chain = seoMonitorChain('/a');

        self::assertSame(['/b', '/c'], $chain);
    }

    // ── Ответ на несуществующий адрес ──────────────────────

    public function testАдресБезПравилаПишетсяВЖурнал(): void
    {
        $handled = seoMonitorNotFound(null, '/net-takoy-stranicy');

        self::assertNull($handled, 'Без правила фильтр не отвечает: страница 404 печатается как прежде');
        self::assertCount(1, seoMonitorMisses());
    }

    public function testАдресСПравиломОтвечаетПереходомИНеПишетсяВЖурнал(): void
    {
        seoMonitorRedirectSave(['from' => '/staryy-adres', 'to' => '/novyy-adres', 'code' => 301]);

        $handled = seoMonitorNotFound(null, '/staryy-adres');

        self::assertIsArray($handled);
        self::assertSame('/novyy-adres', (string) $handled['url']);
        self::assertSame(301, (int) $handled['status']);
        self::assertSame([], seoMonitorMisses(), 'Адрес с правилом больше не отсутствует, и в журнал он не попадает');
    }

    public function testВыключенныйЖурналНичегоНеПишет(): void
    {
        setOption(SEO_MONITOR_OPTION_LOG, '0');

        seoMonitorRecordMiss('/net-takoy-stranicy');

        self::assertSame([], seoMonitorMisses());
    }

    // ── Поиск целевой страницы ────────────────────────────

    public function testПоискЦелиИщетПоНашимАдресам(): void
    {
        insert('seo_meta', [
            'id' => newId(),
            'path' => '/classifieds/c/velosipedy',
            'title' => 'Велосипеды',
            'description' => null,
            'canonical' => null,
            'robots' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image' => null,
            'noindex' => 0,
            'in_sitemap' => 1,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        $found = seoMonitorCandidates('velosiped');

        self::assertCount(1, $found);
        self::assertSame('/classifieds/c/velosipedy', (string) $found[0]['path']);
        self::assertSame('Велосипеды', (string) $found[0]['title']);

        self::assertSame([], seoMonitorCandidates('такого-адреса-нет'), 'Чужого адреса в выдаче быть не может');
    }
}
