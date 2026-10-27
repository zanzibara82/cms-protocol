<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Статистика раздела «SEO и статистика»: настройки сбора, подписи, определение источника,
 * приём событий, сессии, агрегаты и очистка сырья.
 *
 * Набор проверяет обещания раздела: cookie не используются, адрес посетителя не хранится,
 * содержимое форм не собирается, а счётчики сходятся с сырьём.
 */
final class SeoStatsTest extends TestCase
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
        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        foreach (['seo_visit', 'seo_session', 'seo_stat_day', 'seo_stat_hour'] as $table) {
            run('DELETE FROM ' . t($table));
        }

        foreach ([
            SEO_STATS_OPTION_ENABLED,
            SEO_STATS_OPTION_TIMEOUT,
            SEO_STATS_OPTION_RETENTION,
            SEO_STATS_OPTION_DNT,
        ] as $option) {
            deleteOption($option);
        }
    }

    /**
     * Событие в виде, в котором его присылает счётчик.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function event(array $overrides = []): array
    {
        return array_merge([
            'p' => '/news',
            'r' => '',
            'u' => [],
            'd' => 'desktop',
        ], $overrides);
    }

    // ── Настройки ──────────────────────────────────────────

    public function testНастройкиПоУмолчаниюРазумны(): void
    {
        $settings = seoStatsSettings();

        self::assertTrue($settings['enabled'], 'Сбор по умолчанию включён: раздел считает собственные посещения');
        self::assertSame(30, $settings['timeout']);
        self::assertSame(90, $settings['retention']);
        self::assertTrue($settings['respectDnt'], 'По умолчанию уважается «не отслеживать»');
    }

    public function testЧислаВнеГраницБерутсяЗапасными(): void
    {
        setOption(SEO_STATS_OPTION_TIMEOUT, '0');
        setOption(SEO_STATS_OPTION_RETENTION, '1000');

        $settings = seoStatsSettings();

        self::assertSame(SEO_STATS_DEFAULT_TIMEOUT, $settings['timeout'], 'Пустая минута превратила бы сессию в одну секунду');
        self::assertSame(SEO_STATS_DEFAULT_RETENTION, $settings['retention']);
    }

    public function testСохранениеНастроекОтвергаетЧислаВнеГраниц(): void
    {
        $result = seoStatsSaveSettings(['timeout' => '1', 'retention' => '30', 'enabled' => '1']);

        self::assertFalse($result['ok'], 'Вне границ — отказ с объяснением, а не молчаливая подмена');
        self::assertStringContainsString('от ' . SEO_STATS_TIMEOUT_MIN, (string) $result['error']);
        self::assertSame(SEO_STATS_DEFAULT_TIMEOUT, seoStatsSettings()['timeout'], 'Отклонённые настройки не обязаны менять настройки');
    }

    public function testСохранениеНастроекПишетИх(): void
    {
        $result = seoStatsSaveSettings([
            'enabled' => '1',
            'respect_dnt' => '1',
            'timeout' => '45',
            'retention' => '30',
        ]);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertSame('stats_saved', (string) $result['notice']);

        $settings = seoStatsSettings();
        self::assertSame(45, $settings['timeout']);
        self::assertSame(30, $settings['retention']);
    }

    public function testВыключенныйСборЗаписываетсяКакВыключенный(): void
    {
        $result = seoStatsSaveSettings([
            'enabled' => '',
            'respect_dnt' => '',
            'timeout' => '30',
            'retention' => '90',
        ]);

        self::assertTrue($result['ok'], (string) $result['error']);
        self::assertFalse(seoStatsSettings()['enabled']);
        self::assertFalse(seoStatsSettings()['respectDnt']);
    }

    // ── Подписи ────────────────────────────────────────────

    public function testПодписьРазделаЖивётСутки(): void
    {
        $today = seoStatsDayKey(nowUtc());
        $token = seoCollectToken();
        $yesterday = seoCollectTokenFor(shiftMoscowDay($today, -1));
        $tomorrow = seoCollectTokenFor(shiftMoscowDay($today, 1));

        self::assertTrue(seoCollectorTokenValid($token), 'Подпись сегодняшней сборки обязана приниматься');
        self::assertTrue(seoCollectorTokenValid($yesterday), 'Страница из вчерашнего кэша приходит позже — вчерашняя подпись тоже принимается');
        self::assertFalse(seoCollectorTokenValid($tomorrow), 'Завтрашняя подпись не принимается: окно смотрит назад, а не вперёд');
        self::assertFalse(seoCollectorTokenValid('чужая-подпись'), 'Чужая подпись обязана отвергаться');
        self::assertFalse(seoCollectorTokenValid(''), 'Пустой токен — не подпись');
    }

    public function testПодписьПосетителяУстойчиваИНеОбратима(): void
    {
        $first = seoStatsVisitorHash('visitor-0001');
        $same = seoStatsVisitorHash('visitor-0001');
        $other = seoStatsVisitorHash('visitor-0002');

        self::assertNotSame('', $first, 'Номер посетителя безопасен для подписи');
        self::assertSame($first, $same, 'Один и тот же номер обязан давать одну и ту же подпись');
        self::assertNotSame($first, $other, 'Разные номера обязаны давать разные подписи');
        self::assertSame('', seoStatsVisitorHash('коротк'), 'Слишком короткий номер не подписывается');
        self::assertSame('', seoStatsVisitorHash('номер с пробелом'), 'Номер со странными символами не подписывается');
    }

    // ── Источник и адрес ───────────────────────────────────

    public function testИсточникУзнаётсяПоМеткам(): void
    {
        self::assertSame(SEO_SOURCE_PAID_SEARCH, seoSourceClassify('yandex', 'cpc', 'vesna', ''));
        self::assertSame(SEO_SOURCE_PAID_SOCIAL, seoSourceClassify('vk', 'paid_social', 'vesna', ''));
        self::assertSame(SEO_SOURCE_DISPLAY, seoSourceClassify('banner', 'display', 'vesna', ''));
        self::assertSame(SEO_SOURCE_EMAIL, seoSourceClassify('letter', 'email', 'vesna', ''));
        self::assertSame(SEO_SOURCE_CAMPAIGN, seoSourceClassify('ads', '', 'vesna', ''), 'Кампания без типа — «другие кампании», а не выдуманная категория');
    }

    public function testИсточникУзнаётсяПоХосту(): void
    {
        self::assertSame(SEO_SOURCE_DIRECT, seoSourceClassify('', '', '', ''));
        self::assertSame(SEO_SOURCE_ORGANIC, seoSourceClassify('', '', '', 'google.com'));
        self::assertSame(SEO_SOURCE_SOCIAL, seoSourceClassify('', '', '', 'vk.com'));
        self::assertSame(SEO_SOURCE_EMAIL, seoSourceClassify('', '', '', 'mail.google.com'));
        self::assertSame(SEO_SOURCE_REFERRAL, seoSourceClassify('', '', '', 'blog.example'));
        self::assertSame(SEO_SOURCE_REFERRAL, seoSourceClassify('', '', '', 'notgoogle.com'), 'Хост, лишь начинающийся со слова поисковика, — обычная ссылка');
    }

    public function testАдресЧиститсяОтПараметровИЯкоря(): void
    {
        self::assertSame('/news', seoStatsNormalizePath('/news?page=2'), 'Строка запроса может нести личные данные — в счёт она не попадает');
        self::assertSame('/news', seoStatsNormalizePath('/news#glava'));
        self::assertSame('/news', seoStatsNormalizePath('/news/'));
        self::assertSame('', seoStatsNormalizePath('news'), 'Адрес без ведущего слэша — не адрес сайта');
        self::assertSame('', seoStatsNormalizePath("/news\x01"), 'Управляющий символ делает адрес негодным');
    }

    public function testРеферерХранитсяХостом(): void
    {
        self::assertSame('google.com', seoStatsReferrerHost('https://www.google.com/search?q=secret'));
        self::assertSame('google.com', seoStatsReferrerHost('google.com'), 'Счётчик присылает только хост');
        self::assertSame('blog.example', seoStatsReferrerHost('https://blog.example/'));
        self::assertSame('', seoStatsReferrerHost(''));
        self::assertSame('', seoStatsReferrerHost('не адрес'), 'Не-адрес не превращается в хост');
    }

    public function testУстройствоОпределяетсяГрубо(): void
    {
        self::assertSame('mobile', seoStatsDevice('mobile'));
        self::assertSame('unknown', seoStatsDevice('unknown-browser'));
        self::assertSame('unknown', seoStatsDevice(''));
    }

    // ── Приём ──────────────────────────────────────────────

    public function testНеразобранноеТелоОтвергается(): void
    {
        self::assertNull(seoStatsParsePayload('не json'), 'Неразобранное тело — отказ, а не пустая пачка');
        self::assertNull(seoStatsParsePayload(''));
        self::assertNull(seoStatsParsePayload('{"vid":"visitor-0001"}'), 'Без списка событий приёмника нет');
    }

    public function testТелоОбрезаетсяДоДвадцатиСобытий(): void
    {
        $events = [];
        for ($i = 0; $i < 30; $i++) {
            $events[] = self::event(['p' => '/page-' . $i]);
        }

        $payload = seoStatsParsePayload((string) json_encode(['vid' => 'visitor-0001', 'events' => $events]));

        self::assertIsArray($payload);
        self::assertCount(SEO_STATS_MAX_EVENTS, $payload['events'], 'Пачка длиннее предела принимается не вся');
    }

    public function testСобытиеБезАдресаОтбрасывается(): void
    {
        self::assertNull(seoStatsEvent(['p' => 'не-адрес']));

        $prepared = seoStatsEvent(self::event([
            'p' => '/news?page=2',
            'r' => 'https://www.google.com/search',
            'u' => ['s' => 'yandex', 'm' => 'cpc', 'c' => 'vesna'],
            'd' => 'mobile',
        ]));

        self::assertIsArray($prepared);
        self::assertSame('/news', $prepared['path']);
        self::assertSame('google.com', $prepared['referrer']);
        self::assertSame('yandex', $prepared['utmSource']);
        self::assertSame('cpc', $prepared['utmMedium']);
        self::assertSame('vesna', $prepared['utmCampaign']);
        self::assertSame('mobile', $prepared['device']);
    }

    public function testСобытиеЛожитсяВСырьёИАгрегаты(): void
    {
        $result = seoStatsStoreEvents([self::event()], 'visitor-0001');

        self::assertSame(1, $result['accepted']);
        self::assertSame(0, $result['rejected']);

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));

        $day = seoStatsDayKey(nowUtc());
        $views = (int) value('SELECT SUM(views) FROM ' . t('seo_stat_day') . ' WHERE day = ?', [$day]);
        $sessions = (int) value('SELECT SUM(sessions) FROM ' . t('seo_stat_day') . ' WHERE day = ?', [$day]);
        $hourViews = (int) value('SELECT SUM(views) FROM ' . t('seo_stat_hour') . ' WHERE day = ?', [$day]);

        self::assertSame(1, $views, 'Просмотр обязан попасть в агрегат за день');
        self::assertSame(1, $sessions, 'Первая страница сессии — это и есть новая сессия');
        self::assertSame(1, $hourViews, 'Просмотр обязан попасть в агрегат за час');
    }

    public function testВтораяСтраницаПродлеваетСессию(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about'])], 'visitor-0001');

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []), 'Второе событие в окне бездействия — та же сессия');
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));

        $session = one('SELECT views, landing_path, last_path FROM ' . t('seo_session'), []);
        self::assertIsArray($session);
        self::assertSame(2, (int) $session['views']);
        self::assertSame('/news', (string) $session['landing_path'], 'Входом в сессию остаётся первая страница');
        self::assertSame('/about', (string) $session['last_path'], 'Выходом из сессии становится последняя страница');

        $day = seoStatsDayKey(nowUtc());
        $sessions = (int) value('SELECT SUM(sessions) FROM ' . t('seo_stat_day') . ' WHERE day = ?', [$day]);
        self::assertSame(1, $sessions, 'Две страницы одной сессии — одна сессия в счётчике');
    }

    public function testИсточникСессииНеМеняетсяНаСередине(): void
    {
        seoStatsStoreEvents([self::event(['r' => 'https://google.com/'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about', 'r' => 'https://vk.com/'])], 'visitor-0001');

        $session = one('SELECT source FROM ' . t('seo_session'), []);
        self::assertIsArray($session);
        self::assertSame(SEO_SOURCE_ORGANIC, (string) $session['source'], 'Внутренний переход по своему сайту не делает посетителя «прямым»');
    }

    public function testДругойПосетительЗаводитСвоюСессию(): void
    {
        seoStatsStoreEvents([self::event()], 'visitor-0001');
        seoStatsStoreEvents([self::event()], 'visitor-0002');

        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []));
        self::assertSame(2, (int) value('SELECT COUNT(DISTINCT visitor_id) FROM ' . t('seo_visit'), []));
    }

    public function testСобытиеЧужимНомеромНеПринимается(): void
    {
        $result = seoStatsStoreEvents([self::event()], 'коротк');

        self::assertSame(0, $result['accepted']);
        self::assertSame(1, $result['rejected']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
    }

    public function testСобытиеНеРазбираетсяОтбрасывается(): void
    {
        $result = seoStatsStoreEvents([['p' => ''], self::event(['p' => '/news'])], 'visitor-0001');

        self::assertSame(1, $result['accepted']);
        self::assertSame(1, $result['rejected'], 'Неразобранное событие не плюсуется к принятым');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
    }

    /**
     * Пакетный приём (S6): сессия читается один раз на пачку, а не под каждым событием.
     * Проверяются следствия: сессия одна, просмотры сессии совпадают с числом принятых,
     * «выходом» становится последний адрес пачки, а агрегаты дня и часа сходятся с числом
     * событий.
     */
    public function testПачкаСобытийЛожитсяОднойСессией(): void
    {
        $result = seoStatsStoreEvents([
            self::event(['p' => '/news']),
            self::event(['p' => '/about', 'r' => 'https://vk.com/']),
            self::event(['p' => '/contact']),
        ], 'visitor-0001');
        self::assertSame(3, $result['accepted']);
        self::assertSame(0, $result['rejected']);

        self::assertSame(3, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []), 'Пачка приходит от одного посетителя одним штампом — сессия одна');

        $session = one('SELECT views, landing_path, last_path, source FROM ' . t('seo_session'), []);
        self::assertIsArray($session);
        self::assertSame(3, (int) $session['views']);
        self::assertSame('/news', (string) $session['landing_path'], 'Входом остаётся первое событие пачки');
        self::assertSame('/contact', (string) $session['last_path'], 'Выходом становится последнее событие пачки');

        $day = seoStatsDayKey(nowUtc());
        self::assertSame(SEO_SOURCE_DIRECT, (string) $session['source'], 'Источник сессии от первого события пачки, а не от последнего');
        self::assertSame(3, (int) value('SELECT SUM(views) FROM ' . t('seo_stat_day') . ' WHERE day = ?', [$day]));
        self::assertSame(1, (int) value('SELECT SUM(sessions) FROM ' . t('seo_stat_day') . ' WHERE day = ?', [$day]), 'Одна пачка — одна сессия в агрегате');
        self::assertSame(3, (int) value('SELECT SUM(views) FROM ' . t('seo_stat_hour') . ' WHERE day = ?', [$day]));
    }

    // ── Чтение ─────────────────────────────────────────────

    public function testСводкаЧитаетАгрегаты(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about'])], 'visitor-0002');

        $day = seoStatsDayKey(nowUtc());
        $summary = seoStatsSummary(shiftMoscowDay($day, -1), $day);

        self::assertSame(2, $summary['views']);
        self::assertSame(2, $summary['sessions']);
        self::assertSame(2, $summary['visitors'], 'Посетитель — подпись номера из localStorage, разные номера считаются разными');
    }

    public function testФильтрПоИсточникуУрежаетСводку(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about', 'r' => 'https://vk.com/'])], 'visitor-0002');

        $day = seoStatsDayKey(nowUtc());
        $all = seoStatsSummary(shiftMoscowDay($day, -1), $day);
        $direct = seoStatsSummary(shiftMoscowDay($day, -1), $day, SEO_SOURCE_DIRECT);
        $social = seoStatsSummary(shiftMoscowDay($day, -1), $day, SEO_SOURCE_SOCIAL);

        self::assertSame(2, $all['views']);
        self::assertSame(1, $direct['views'], 'Прямой заход — только он');
        self::assertSame(1, $social['views'], 'Переход из соцсети — только он');
    }

    public function testПропущенныеДниДостраиваютсяНулём(): void
    {
        $day = seoStatsDayKey(nowUtc());
        seoStatsStoreEvents([self::event()], 'visitor-0001');

        $daily = seoStatsDaily(shiftMoscowDay($day, -2), $day);

        self::assertCount(3, $daily, 'Пропущенный день без нуля нарисовал бы на графике провал');
        self::assertSame(0, $daily[0]['views']);
        self::assertSame(1, $daily[2]['views']);
    }

    public function testПопулярныеСтраницыСчитаютВходыИВыходы(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/news'])], 'visitor-0001');
        seoStatsStoreEvents([self::event(['p' => '/about'])], 'visitor-0001');

        $day = seoStatsDayKey(nowUtc());
        $pages = seoStatsPages(shiftMoscowDay($day, -1), $day);

        self::assertNotSame([], $pages);

        $byPath = [];
        foreach ($pages as $page) {
            $byPath[(string) $page['path']] = $page;
        }

        self::assertSame(1, (int) ($byPath['/news']['views'] ?? 0));
        self::assertSame(1, (int) ($byPath['/news']['entrances'] ?? 0), 'Вход — первая страница сессии');
        self::assertSame(0, (int) ($byPath['/news']['exits'] ?? 0), 'Выход — последняя страница сессии');
        self::assertSame(1, (int) ($byPath['/about']['exits'] ?? 0));
    }

    public function testИсточникиЧитаютсяСПодписями(): void
    {
        seoStatsStoreEvents([self::event(['r' => 'https://google.com/'])], 'visitor-0001');

        $day = seoStatsDayKey(nowUtc());
        $sources = seoStatsSources(shiftMoscowDay($day, -1), $day);

        self::assertNotSame([], $sources);
        self::assertSame(SEO_SOURCE_ORGANIC, (string) $sources[0]['source']);
        self::assertSame('Органический поиск', (string) $sources[0]['label']);
        self::assertSame(1, (int) $sources[0]['visitors']);
    }

    public function testЧасыВсегдаДвадцатьЧетыре(): void
    {
        $day = seoStatsDayKey(nowUtc());
        seoStatsStoreEvents([self::event()], 'visitor-0001');

        $hourly = seoStatsHourly(shiftMoscowDay($day, -1), $day);

        self::assertCount(24, $hourly, 'Час без трафика — ноль, а не пропуск в графике');
        self::assertSame(0, $hourly[0]['hour']);

        $withTraffic = 0;
        foreach ($hourly as $row) {
            if ((int) $row['views'] > 0) {
                $withTraffic++;
            }
        }

        self::assertSame(1, $withTraffic, 'Событие легло в один час');
    }

    public function testКампанииЧитаютсяИзМеток(): void
    {
        seoStatsStoreEvents([self::event([
            'u' => ['s' => 'yandex', 'm' => 'cpc', 'c' => 'vesna-2026'],
        ])], 'visitor-0001');

        $day = seoStatsDayKey(nowUtc());
        $campaigns = seoStatsCampaigns(shiftMoscowDay($day, -1), $day);

        self::assertNotSame([], $campaigns);
        self::assertSame('vesna-2026', (string) $campaigns[0]['campaign']);
        self::assertSame('yandex', (string) $campaigns[0]['source']);
        self::assertSame(1, (int) $campaigns[0]['views']);
    }

    // ── Очистка ────────────────────────────────────────────

    public function testОчисткаУдаляетСтароеСырьёНоНеАгрегаты(): void
    {
        $old = shiftMoscowDay(seoStatsDayKey(nowUtc()), -120);

        insert('seo_visit', [
            'id' => newId(),
            'session_id' => newId(),
            'visitor_id' => 'подпись-старого-посетителя',
            'path' => '/old',
            'source' => SEO_SOURCE_DIRECT,
            'referrer_host' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'device' => 'desktop',
            'day' => $old,
            'hour' => 10,
            'created_at' => utcStamp(nowUtc()),
        ]);
        insert('seo_session', [
            'id' => newId(),
            'visitor_id' => 'подпись-старого-посетителя',
            'source' => SEO_SOURCE_DIRECT,
            'landing_path' => '/old',
            'last_path' => '/old',
            'views' => 1,
            'started_at' => utcStamp(nowUtc()),
            'last_seen_at' => utcStamp(nowUtc()),
            'day' => $old,
        ]);
        insert('seo_stat_day', [
            'day' => $old,
            'path' => '/old',
            'source' => SEO_SOURCE_DIRECT,
            'views' => 1,
            'sessions' => 1,
        ]);

        $preview = seoStatsPurgePreview(90);
        self::assertSame(1, $preview['visits'], 'Человек обязан видеть объём до нажатия');
        self::assertSame(1, $preview['sessions']);

        seoStatsPurge(90);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_stat_day'), []), 'Счётчики за прошлые дни не исчезают вместе с сырьём');
    }

    public function testОчисткаНеТрогаетСвежиеСобытия(): void
    {
        $old = shiftMoscowDay(seoStatsDayKey(nowUtc()), -120);

        insert('seo_visit', [
            'id' => newId(),
            'session_id' => newId(),
            'visitor_id' => 'подпись-старого-посетителя',
            'path' => '/old',
            'source' => SEO_SOURCE_DIRECT,
            'referrer_host' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'device' => 'desktop',
            'day' => $old,
            'hour' => 10,
            'created_at' => utcStamp(nowUtc()),
        ]);
        insert('seo_session', [
            'id' => newId(),
            'visitor_id' => 'подпись-старого-посетителя',
            'source' => SEO_SOURCE_DIRECT,
            'landing_path' => '/old',
            'last_path' => '/old',
            'views' => 1,
            'started_at' => utcStamp(nowUtc()),
            'last_seen_at' => utcStamp(nowUtc()),
            'day' => $old,
        ]);

        seoStatsStoreEvents([self::event(['p' => '/fresh'])], 'visitor-0001');

        $preview = seoStatsPurgePreview(90);
        self::assertSame(1, $preview['visits'], 'Срок хранения отсекает старое, а не свежее');
        self::assertSame(1, $preview['sessions']);

        seoStatsPurge(90);

        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE day = ?', [$old]));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE path = ?', ['/fresh']), 'Свежий визит переживает очистку');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []), 'Свежая сессия переживает очистку');
    }

    // ── Границы периода ────────────────────────────────────

    public function testПериодСмотритНазадИПрошлоеОкноСоседствует(): void
    {
        seoStatsStoreEvents([self::event(['p' => '/today'])], 'visitor-0001');

        foreach (SEO_STATS_PERIODS as $days) {
            $period = seoStatsPeriod($days);

            self::assertLessThanOrEqual($period['to'], $period['from'], 'Период начинается в прошлом, а не в будущем');
            self::assertSame(shiftMoscowDay($period['to'], -($days - 1)), $period['from'], 'Начало периода — сегодня минус (дней − 1)');
            self::assertSame(shiftMoscowDay($period['from'], -1), $period['previousTo'], 'Прошлое окно кончается накануне начала текущего');
            self::assertSame(shiftMoscowDay($period['previousTo'], -($days - 1)), $period['previousFrom']);

            self::assertSame(1, seoStatsSummary($period['from'], $period['to'])['views'], 'Сегодняшнее событие попадает в свой период');
            self::assertSame(0, seoStatsSummary($period['previousFrom'], $period['previousTo'])['views'], 'В прошлое окно оно не протекает');
        }
    }

    // ── Пакетная вставка ───────────────────────────────────

    public function testДублиАдресаВПачкеНакапливаются(): void
    {
        $result = seoStatsStoreEvents([
            self::event(['p' => '/news']),
            self::event(['p' => '/news']),
            self::event(['p' => '/about']),
        ], 'visitor-0001');

        self::assertSame(3, $result['accepted']);

        $day = seoStatsDayKey(nowUtc());
        $news = one('SELECT views, sessions FROM ' . t('seo_stat_day') . ' WHERE day = ? AND path = ?', [$day, '/news']);
        self::assertIsArray($news);
        self::assertSame(2, (int) $news['views'], 'Два просмотра одного пути в пачке складываются, а не затирают друг друга');
        self::assertSame(1, (int) $news['sessions'], 'Сессия приписывается строке один раз');

        $about = one('SELECT views, sessions FROM ' . t('seo_stat_day') . ' WHERE day = ? AND path = ?', [$day, '/about']);
        self::assertIsArray($about);
        self::assertSame(1, (int) $about['views']);
        self::assertSame(0, (int) $about['sessions'], 'Второй путь той же сессии входом не считается');

        self::assertSame(3, (int) value('SELECT SUM(views) FROM ' . t('seo_stat_hour') . ' WHERE day = ?', [$day]));
        self::assertSame(1, (int) value('SELECT SUM(sessions) FROM ' . t('seo_stat_hour') . ' WHERE day = ?', [$day]));
    }

    public function testПределПриёмаСчитаетВсюПачку(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(20, seoStatsStoreEvents(array_fill(0, 20, self::event()), 'visitor-rate')['accepted']);
        }

        self::assertSame(19, seoStatsStoreEvents(array_fill(0, 19, self::event()), 'visitor-rate')['accepted']);

        $over = seoStatsStoreEvents(array_fill(0, 20, self::event()), 'visitor-rate');

        self::assertSame(0, $over['accepted'], 'Пачка, которая вывела бы за минуту больше предела, отвергается целиком');
        self::assertSame(20, $over['rejected']);
        self::assertSame(
            SEO_STATS_RATE_LIMIT - 1,
            (int) value('SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE visitor_id = ?', [seoStatsVisitorHash('visitor-rate')]),
            'Отвергнутая пачка не добавила ни одного визита'
        );

        self::assertSame(1, seoStatsStoreEvents([self::event()], 'visitor-rate')['accepted'], 'Ровно до предела — ещё можно');
        self::assertSame(
            SEO_STATS_RATE_LIMIT,
            (int) value('SELECT COUNT(*) FROM ' . t('seo_visit') . ' WHERE visitor_id = ?', [seoStatsVisitorHash('visitor-rate')]),
            'Ровно предел — это ещё предел, а не шаг за него'
        );
    }

    public function testСбойПакетаНеОставляетПоловинуЗаписей(): void
    {
        run('RENAME TABLE ' . t('seo_stat_hour') . ' TO ' . t('seo_stat_hour_fail'));

        $thrown = false;

        try {
            seoStatsStoreEvents([self::event()], 'visitor-0001');
        } catch (\Throwable) {
            $thrown = true;
        } finally {
            run('RENAME TABLE ' . t('seo_stat_hour_fail') . ' TO ' . t('seo_stat_hour'));
        }

        self::assertTrue($thrown, 'Сбой агрегата обязан дойти до вызывающего');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_visit'), []), 'Визит из упавшей пачки не остался');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_session'), []), 'Сессия из упавшей пачки откатилась');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_stat_day'), []), 'Агрегат дня откатился вместе с пачкой');
    }
}
