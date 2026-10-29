<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экраны воронок раздела «SEO и статистика» через настоящий HTTP: право на экран, создание,
 * включение и удаление воронки, добавление шага, отчёт по воронке.
 */
final class SeoFunnelsAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'seo-funnels-admin@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';

    private const PATH = '/seo/admin/funnels';

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

        foreach (['seo_funnel_step', 'seo_funnel', 'seo_goal'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        run('DELETE FROM ' . t('seo_funnel_step'));
        run('DELETE FROM ' . t('seo_funnel'));
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

    public function testАнонимНаЭкранеВоронокПеренаправляется(): void
    {
        $page = Kernel::get(self::PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fseo%2Fadmin%2Ffunnels', $page['headers']['location']);
    }

    public function testЭкранВоронокВиденАдминистратору(): void
    {
        $this->loginAdmin();

        $page = Kernel::get(self::PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Воронки', $page['body']);
        self::assertStringContainsString('Создать воронку', $page['body']);
    }

    // ── Воронки ────────────────────────────────────────────

    public function testВоронкаСоздаетсяЧерезФорму(): void
    {
        $this->loginAdmin();

        $response = $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=funnel_created', $response['headers']['location']);

        $row = one('SELECT id, name, enabled FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу']);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['enabled']);
    }

    public function testВоронкаПереключается(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        $id = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        $response = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH)['body']),
            'op' => 'funnel_toggle',
            'id' => $id,
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=funnel_disabled', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT enabled FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]));
    }

    public function testКнопкиШаблонаПереключаютВоронкуИДатаСозданияОстаётся(): void
    {
        // Две находки: кнопки из шаблона шлют funnel_disable/funnel_enable — обработчик
        // о них не знал, и обе кнопки всегда отвечали «Неизвестное действие». А тот, кто
        // переключал воронку обходным путём, каждый раз затирал ей дату создания: в
        // таблице нет updated_at, и прежний UPDATE писал текущий штамп в created_at.
        $this->loginAdmin();

        $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        $created = one('SELECT id, created_at FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу']);
        $id = (string) $created['id'];

        $disable = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH)['body']),
            'op' => 'funnel_disable',
            'id' => $id,
        ]);

        self::assertSame(302, $disable['status']);
        self::assertStringContainsString('notice=funnel_disabled', $disable['headers']['location']);
        self::assertSame(0, (int) value('SELECT enabled FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]));

        $enable = $this->press(['op' => 'funnel_enable', 'id' => $id]);

        self::assertSame(302, $enable['status']);
        self::assertStringContainsString('notice=funnel_enabled', $enable['headers']['location']);
        self::assertSame(1, (int) value('SELECT enabled FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]));

        // Дата создания не сдвигается ни одним из переключений.
        self::assertSame((string) $created['created_at'], (string) value('SELECT created_at FROM ' . t('seo_funnel') . ' WHERE id = ?', [$id]));
    }

    public function testВоронкаУдаляется(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        $id = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        $response = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH)['body']),
            'op' => 'funnel_delete',
            'id' => $id,
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=funnel_deleted', $response['headers']['location']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_funnel')));
    }

    public function testШагВоронкиДобавляется(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        $funnelId = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        $response = Kernel::post(self::PATH, [
            '_token' => Kernel::tokenFrom(Kernel::get(self::PATH . '?funnel=' . $funnelId)['body']),
            'op' => 'funnel_step_create',
            'funnel_id' => $funnelId,
            'name' => 'Корзина',
            'pattern' => '/cart',
            'match_type' => SEO_GOAL_MATCH_EXACT,
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=step_created', $response['headers']['location']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_funnel_step') . ' WHERE funnel_id = ?', [$funnelId]));
    }

    public function testОтчетВоронки(): void
    {
        $this->loginAdmin();

        $this->press([
            'op' => 'funnel_create',
            'name' => 'Путь к заказу',
        ]);

        $funnelId = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        $this->press([
            'op' => 'funnel_step_create',
            'funnel_id' => $funnelId,
            'name' => 'Корзина',
            'pattern' => '/cart',
            'match_type' => SEO_GOAL_MATCH_EXACT,
        ]);

        $page = Kernel::get(self::PATH . '?funnel=' . $funnelId);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Путь к заказу', $page['body']);
        self::assertStringContainsString('Корзина', $page['body']);
        self::assertStringContainsString('/cart', $page['body']);
        self::assertStringContainsString('Сессий во воронке', $page['body']);
    }
}
