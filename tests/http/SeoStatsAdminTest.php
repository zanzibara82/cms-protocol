<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экраны статистики раздела «SEO и статистика» через настоящий HTTP: право доступа, обзор,
 * страницы, источники, настройки сбора, фильтры и очистка сырья.
 *
 * Набор проверяет обещания панели: числа берутся из агрегатов, методика подписана, а «посетитель»
 * остаётся подписью номера, а не человеком.
 */
final class SeoStatsAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'seo-stats-redaktor@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const STATS_PATH = '/seo/admin/stats';
    private const PAGES_PATH = '/seo/admin/pages';
    private const SOURCES_PATH = '/seo/admin/sources';
    private const SETTINGS_PATH = '/seo/admin/settings';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

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

        foreach ([
            SEO_STATS_OPTION_ENABLED,
            SEO_STATS_OPTION_TIMEOUT,
            SEO_STATS_OPTION_RETENTION,
            SEO_STATS_OPTION_DNT,
        ] as $option) {
            deleteOption($option);
        }
    }

    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    /**
     * POST с токеном настоящей формы: токен берём с экрана настроек — там он и печатается.
     *
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(array $fields): array
    {
        $page = Kernel::get(self::SETTINGS_PATH);

        return Kernel::post(self::SETTINGS_PATH, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    // ── Право на экраны ────────────────────────────────────

    public function testАнонимНаОбзореУходитНаВход(): void
    {
        $page = Kernel::get(self::STATS_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fstats', $page['headers']['location']);
    }

    public function testАнонимСPostПолучаетЗапрет(): void
    {
        // Редирект на форму потерял бы тело запроса, поэтому приватный POST отвечает сразу.
        $response = Kernel::post(self::SETTINGS_PATH, ['op' => 'stats_settings', 'timeout' => '30', 'retention' => '90']);

        self::assertSame(403, $response['status']);
    }

    // ── Экраны ─────────────────────────────────────────────

    public function testРедакторВидитОбзор(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Обзор', $page['body']);
        self::assertStringContainsString('Просмотры по дням', $page['body']);
        self::assertStringContainsString('Популярные страницы', $page['body']);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page['body']);
        self::assertSame('no-store', $page['headers']['cache-control'] ?? '', 'Помеченный экран обязан запрещать хранение');
    }

    public function testМетодикаПодписана(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH);

        self::assertStringContainsString('cookie не используются', $page['body'], 'Панель обязана говорить, чем она меряет');
        self::assertStringContainsString('localStorage', $page['body'], 'Методика посетителя обязана быть названа прямо');
    }

    public function testЭкраныСтраницИИсточниковОткрываются(): void
    {
        $this->login();

        $pages = Kernel::get(self::PAGES_PATH);
        self::assertSame(200, $pages['status']);
        self::assertStringContainsString('Страницы', $pages['body']);
        self::assertStringContainsString('Кампании', $pages['body'], 'Кампании по меткам UTM видны рядом со страницами');

        $sources = Kernel::get(self::SOURCES_PATH);
        self::assertSame(200, $sources['status']);
        self::assertStringContainsString('Источники', $sources['body']);
        self::assertStringContainsString('Когда приходит трафик', $sources['body']);
    }

    public function testНастройкиПоказываютТекущиеЧисла(): void
    {
        $this->login();
        $page = Kernel::get(self::SETTINGS_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Настройки статистики', $page['body']);
        self::assertStringContainsString('value="' . SEO_STATS_DEFAULT_TIMEOUT . '"', $page['body'], 'Форма обязана показывать действующее окно сессии');
        self::assertStringContainsString('value="' . SEO_STATS_DEFAULT_RETENTION . '"', $page['body']);
    }

    // ── Числа на экране ────────────────────────────────────

    public function testСобытиеВидноВОбзоре(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-obzor');

        $this->login();
        $page = Kernel::get(self::STATS_PATH);

        self::assertStringContainsString('/news', $page['body'], 'Популярные страницы обязаны показать собранный адрес');
        self::assertStringContainsString('Прямой', $page['body'], 'Источник без меток и реферера — прямой');
    }

    public function testИсточникПоРефереруВиденНаЭкране(): void
    {
        seoStatsStoreEvents([['p' => '/about', 'r' => 'https://www.google.com/search?q=школа', 'u' => [], 'd' => 'desktop']], 'visitor-referrer');

        $this->login();
        $page = Kernel::get(self::SOURCES_PATH);

        self::assertStringContainsString('Органический поиск', $page['body'], 'Реферер поисковика обязан попасть в «органический поиск»');
    }

    // ── Фильтры ────────────────────────────────────────────

    public function testФильтрПериодаПомечаетСрок(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH . '?days=7');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="7" selected', $page['body'], 'Выбранный срок обязан быть виден в форме');
    }

    public function testФильтрИсточникаПомечаетИсточник(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH . '?source=organic');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="organic" selected', $page['body']);
    }

    public function testНеизвестныйИсточникСбрасывается(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH . '?source=' . rawurlencode('выдуманный-источник'));

        self::assertSame(200, $page['status'], 'Неизвестный источник — не ошибка, а «все источники»');
        self::assertStringContainsString('value="" selected', $page['body'], 'Выдуманный источник не должен молча фильтровать пустоту');
    }

    public function testЧужойПериодБерётсяЗапасным(): void
    {
        $this->login();
        $page = Kernel::get(self::STATS_PATH . '?days=3650');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('value="' . SEO_STATS_DEFAULT_PERIOD . '" selected', $page['body'], 'Период вне своих сроков берётся запасным, а не выдуманным');
    }

    // ── Действия настроек ──────────────────────────────────

    public function testСохранениеНастроекСообщаетОбУспехе(): void
    {
        $this->login();

        $response = $this->press([
            'op' => 'stats_settings',
            'enabled' => '1',
            'respect_dnt' => '1',
            'timeout' => '45',
            'retention' => '60',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString(self::SETTINGS_PATH, $response['headers']['location']);
        self::assertStringContainsString('notice=stats_saved', $response['headers']['location']);

        optionsReset();
        self::assertSame('45', getOption(SEO_STATS_OPTION_TIMEOUT), 'Сохранённое окно сессии обязано дойти до настроек');
        self::assertSame('60', getOption(SEO_STATS_OPTION_RETENTION));
    }

    public function testОтказПоГраницамПоказываетПричину(): void
    {
        $this->login();

        $response = $this->press([
            'op' => 'stats_settings',
            'enabled' => '1',
            'timeout' => '1',
            'retention' => '90',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);

        optionsReset();
        self::assertSame('', getOption(SEO_STATS_OPTION_TIMEOUT, ''), 'Отклонённые настройки не обязаны попасть в базу');

        $page = Kernel::get(self::SETTINGS_PATH);
        self::assertStringContainsString('Время бездействия', $page['body'], 'Причина отказа обязана быть видна человеку');
    }

    public function testЧужойТокенОтвергается(): void
    {
        $this->login();

        $response = Kernel::post(self::SETTINGS_PATH, [
            '_token' => str_repeat('a', 64),
            'op' => 'stats_settings',
            'enabled' => '1',
            'timeout' => '45',
            'retention' => '60',
        ]);

        self::assertSame(403, $response['status']);

        optionsReset();
        self::assertSame('', getOption(SEO_STATS_OPTION_TIMEOUT, ''), 'Без своего токена настройки не меняются');
    }

    public function testОчисткаСырьяСообщаетОбУдалении(): void
    {
        seoStatsStoreEvents([['p' => '/news', 'r' => '', 'u' => [], 'd' => 'desktop']], 'visitor-purge');

        $this->login();

        $response = $this->press(['op' => 'stats_purge']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=stats_purged', $response['headers']['location']);
    }

    public function testВыключенныйСборУбираетСкриптСоСтраницы(): void
    {
        $this->login();

        $this->press([
            'op' => 'stats_settings',
            'timeout' => '30',
            'retention' => '90',
        ]);

        $page = Kernel::get('/');

        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('seo-analytics.js', $page['body'], 'Выключенный счётчик не подключается к страницам');
    }
}
