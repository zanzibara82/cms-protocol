<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экраны целей и конверсий раздела «SEO и статистика» через настоящий HTTP: право на экран,
 * создание, включение и удаление цели, отказ на невалидные данные.
 */
final class SeoGoalsAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'seo-goals-admin@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';
    private const EDITOR_EMAIL = 'seo-goals-editor@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private const PATH = '/seo/admin/conversions';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('seo');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «SEO и статистика» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        foreach (['seo_goal', 'seo_conversion', 'seo_conversion_day'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        run('DELETE FROM ' . t('seo_goal'));
        run('DELETE FROM ' . t('seo_conversion'));
        run('DELETE FROM ' . t('seo_conversion_day'));
    }

    private function login(string $email, string $password): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function loginAdmin(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
    }

    private function loginEditor(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(array $fields): array
    {
        $page = Kernel::get(self::PATH);

        return Kernel::post(self::PATH, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    // ── Право и доступ ─────────────────────────────────────

    public function testАнонимНаЭкранеЦелейПеренаправляется(): void
    {
        $page = Kernel::get(self::PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Fconversions', $page['headers']['location']);
    }

    public function testЭкранЦелейВиденРедактору(): void
    {
        $this->loginEditor();

        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Цели', $page['body']);
    }

    // ── Цели ───────────────────────────────────────────────

    public function testЦельСоздаетсяЧерезФорму(): void
    {
        $this->loginAdmin();

        $response = $this->press([
            'op' => 'goal_create',
            'name' => 'Завершение заказа',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=goal_created', $response['headers']['location']);

        $row = one('SELECT id, name, pattern, match_type, enabled FROM ' . t('seo_goal') . ' WHERE name = ?', ['Завершение заказа']);

        self::assertNotNull($row);
        self::assertSame('/order/success', (string) $row['pattern']);
        self::assertSame('prefix', (string) $row['match_type']);
        self::assertSame(1, (int) $row['enabled']);
    }

    public function testЦельПереключается(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'goal_create',
            'name' => 'Завершение заказа',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ]);

        $id = (string) one('SELECT id FROM ' . t('seo_goal') . ' WHERE name = ?', ['Завершение заказа'])['id'];

        $response = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH)['body']),
            'op' => 'goal_toggle',
            'id' => $id,
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=goal_disabled', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT enabled FROM ' . t('seo_goal') . ' WHERE id = ?', [$id]));
    }

    public function testЦельУдаляется(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'goal_create',
            'name' => 'Завершение заказа',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ]);

        $id = (string) one('SELECT id FROM ' . t('seo_goal') . ' WHERE name = ?', ['Завершение заказа'])['id'];

        $response = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH)['body']),
            'op' => 'goal_delete',
            'id' => $id,
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=goal_deleted', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_goal')));
    }

    public function testЦельБезНазванияОтвергается(): void
    {
        $this->loginAdmin();

        $response = $this->press([
            'op' => 'goal_create',
            'name' => '',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_goal')));
    }
}
