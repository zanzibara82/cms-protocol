<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран контентной аналитики раздела «SEO и статистика» через настоящий HTTP: доступ, отчёт по
 * страницам и конверсионный курс.
 */
final class SeoContentAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'seo-content-admin@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';

    private const PATH = '/seo/admin/content';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        foreach (['seo_stat_day', 'seo_conversion', 'seo_goal'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        run('DELETE FROM ' . t('seo_stat_day'));
        run('DELETE FROM ' . t('seo_conversion'));
        run('DELETE FROM ' . t('seo_goal'));
    }

    private function loginAdmin(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    // ── Право и доступ ─────────────────────────────────────

    public function testАнонимНаЭкранеКонтентаПеренаправляется(): void
    {
        $page = Kernel::get(self::PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fcontent', $page['headers']['location']);
    }

    public function testЭкранКонтентаВиденАдминистратору(): void
    {
        $this->loginAdmin();

        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Контент', $page['body']);
        self::assertStringContainsString('Просмотры', $page['body']);
        self::assertStringContainsString('Конверсионный курс', $page['body']);
    }

    // ── Отчёт ──────────────────────────────────────────────

    public function testОтчетПоказываетСтраницыИКонверсии(): void
    {
        $this->loginAdmin();

        seoGoalCreate([
            'name' => 'Завершение заказа',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ]);
        $goalId = (string) one('SELECT id FROM ' . t('seo_goal') . ' WHERE name = ?', ['Завершение заказа'])['id'];

        $today = '2026-10-22';
        $stamp = utcStamp(nowUtc());
        $visitorId = str_repeat('a', 32);

        insert('seo_stat_day', ['day' => $today, 'path' => '/news/item/first', 'source' => 'organic', 'views' => 100, 'sessions' => 40]);
        insert('seo_stat_day', ['day' => $today, 'path' => '/about', 'source' => 'direct', 'views' => 50, 'sessions' => 20]);
        insert('seo_conversion', ['id' => newId(), 'goal_id' => $goalId, 'session_id' => 's1', 'visitor_id' => $visitorId, 'path' => '/news/item/first', 'day' => $today, 'created_at' => $stamp]);
        insert('seo_conversion', ['id' => newId(), 'goal_id' => $goalId, 'session_id' => 's2', 'visitor_id' => $visitorId, 'path' => '/news/item/first', 'day' => $today, 'created_at' => $stamp]);

        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('/news/item/first', $page['body']);
        self::assertStringContainsString('/about', $page['body']);
        self::assertStringContainsString('100', $page['body']);
        self::assertStringContainsString('2', $page['body']);
        self::assertStringContainsString('2,0%', $page['body']);
    }
}
