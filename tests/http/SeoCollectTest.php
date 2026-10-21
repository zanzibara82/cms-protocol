<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Приёмник событий счётчика раздела «SEO и статистика» через настоящий HTTP: подпись в
 * разметке страницы, приём пачки событий, отказ по чужой подписи и работа без cookie.
 */
final class SeoCollectTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
            run('DELETE FROM ' . t($table));
        }

        setOption(SEO_STATS_OPTION_ENABLED, '1');
    }

    /**
     * Тело пачки событий в том виде, в котором его собирает счётчик.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function payload(array $events, string $visitorId = 'visitor-0001'): string
    {
        return (string) json_encode(['vid' => $visitorId, 'events' => $events]);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private static function collect(array $fields): array
    {
        return Kernel::post('/seo/collect', $fields);
    }

    // ── Разметка страницы ──────────────────────────────────

    public function testСтраницаПодключаетСчётчик(): void
    {
        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('src="/assets/js/seo-analytics.js"', $page['body'], 'Счётчик подключается файлом, а не инлайновым кодом');
        self::assertStringContainsString('data-endpoint="/seo/collect"', $page['body']);
        self::assertStringContainsString('data-token="' . seoCollectToken() . '"', $page['body'], 'Подпись раздела обязана лежать в разметке: приёмник сверяет её');
    }

    public function testСкриптСчётчикаОтдаётсяФайлом(): void
    {
        $script = Kernel::get('/assets/js/seo-analytics.js');

        self::assertSame(200, $script['status']);
        self::assertStringNotContainsString('<html', strtolower($script['body']), 'Адрес скрипта обязан отдавать скрипт, а не страницу');
        self::assertStringContainsString('localStorage', $script['body'], 'Идентификатор посетителя лежит в localStorage — и нигде больше');
    }

    public function testЗакрытаяСтраницаСчётчикаНеПолучает(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('seo-analytics.js', $page['body'], 'Открытие служебного экрана — не трафик сайта');
    }

    public function testВыключенныйСборСтраницуНеТрогает(): void
    {
        setOption(SEO_STATS_OPTION_ENABLED, '0');

        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('seo-analytics.js', $page['body'], 'Выключенный счётчик не подключается к страницам');
    }

    // ── Приём ──────────────────────────────────────────────

    public function testПриёмникБезПодписиОтвергает(): void
    {
        $response = self::collect(['payload' => self::payload([['p' => '/news']])]);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []), 'Без подписи события не попадают в сырьё');
    }

    public function testПриёмникСЧужойПодписьюОтвергает(): void
    {
        $response = self::collect([
            '_token' => 'подпись-из-чужаго-раздела',
            'payload' => self::payload([['p' => '/news']]),
        ]);

        self::assertSame(403, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
    }

    public function testПриёмникПринимаетПачкуСобытий(): void
    {
        $response = self::collect([
            '_token' => seoCollectToken(),
            'payload' => self::payload([
                ['p' => '/news'],
                ['p' => '/about', 'r' => 'https://google.com/search'],
            ]),
        ]);

        self::assertSame(204, $response['status'], 'Клиенту нечего читать, а пустой ответ не должен храниться');
        self::assertSame('', $response['body']);

        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []), 'Оба события пачки обязаны лечь в сырьё');
        self::assertSame(2, (int) value('SELECT SUM(views) FROM ' . t('seo_stat_day'), []), 'События пачки пополняют агрегаты');
    }

    public function testПриёмникРаботаетБезCookie(): void
    {
        $response = self::collect([
            '_token' => seoCollectToken(),
            'payload' => self::payload([['p' => '/news']]),
        ]);

        self::assertSame(204, $response['status']);
        self::assertArrayNotHasKey('set-cookie', $response['headers'], 'Счётчик не вправе заводить cookie: режим «без cookies» должен быть достижим');
    }

    public function testБитоеТелоОтвергается(): void
    {
        $response = self::collect([
            '_token' => seoCollectToken(),
            'payload' => 'не json',
        ]);

        self::assertSame(400, $response['status'], 'Молча проглоченное тело выглядело бы как «посетитель молчал»');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
    }

    public function testСобытиеБезАдресаНеЗаписывается(): void
    {
        $response = self::collect([
            '_token' => seoCollectToken(),
            'payload' => self::payload([['p' => 'не-адрес']]),
        ]);

        self::assertSame(204, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []), 'Адрес без ведущего слэша — не адрес сайта');
    }

    public function testВыключенныйСборДанныеНеПишет(): void
    {
        setOption(SEO_STATS_OPTION_ENABLED, '0');

        $response = self::collect([
            '_token' => seoCollectToken(),
            'payload' => self::payload([['p' => '/news']]),
        ]);

        self::assertSame(204, $response['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []), 'Выключенный приёмник не пишет сырьё');
    }
}
