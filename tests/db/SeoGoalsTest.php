<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Цели и конверсии (S5): цели, запись конверсий, отчёты по целям, контентная аналитика и
 * воронки. Все отчёты считаются системой из зафиксированных посещений.
 */
final class SeoGoalsTest extends TestCase
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
        foreach (['seo_conversion_day', 'seo_conversion', 'seo_funnel_step', 'seo_funnel', 'seo_goal'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        foreach (['seo_goal', 'seo_conversion', 'seo_conversion_day', 'seo_funnel', 'seo_funnel_step', 'seo_visit', 'seo_session'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function goal(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Завершение заказа',
            'type' => SEO_GOAL_TYPE_PAGE_VIEW,
            'pattern' => '/order/success',
            'match_type' => SEO_GOAL_MATCH_PREFIX,
            'source' => '',
            'device' => '',
        ], $overrides);
    }

    private function goalIdByName(string $name): string
    {
        $row = one('SELECT id FROM ' . t('seo_goal') . ' WHERE name = ?', [$name]);

        self::assertNotNull($row, 'Цель «' . $name . '» должна быть в базе');

        return (string) $row['id'];
    }

    // ── Цели ──────────────────────────────────────────────

    public function testЦелиПустыеПоУмолчанию(): void
    {
        self::assertSame([], seoGoals(), 'Без целей список пуст');
    }

    public function testЦельСоздаетсяИВидитсяВСписке(): void
    {
        $result = seoGoalCreate($this->goal());

        self::assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        self::assertSame('goal_created', $result['notice']);

        $goals = seoGoals();

        self::assertCount(1, $goals);
        self::assertSame('Завершение заказа', $goals[0]['name']);
        self::assertSame('/order/success', $goals[0]['pattern']);
        self::assertSame('prefix', $goals[0]['matchType']);
        self::assertTrue($goals[0]['enabled']);
        self::assertSame(0, $goals[0]['conversions']);
    }

    public function testЦельБезНазванияОтвергается(): void
    {
        $result = seoGoalCreate(array_merge($this->goal(), ['name' => '']));

        self::assertFalse($result['ok']);
        self::assertSame([], seoGoals());
    }

    public function testЦельСНеизвестнымТипомОтвергается(): void
    {
        $result = seoGoalCreate(array_merge($this->goal(), ['type' => 'unknown']));

        self::assertFalse($result['ok']);
        self::assertSame([], seoGoals());
    }

    public function testЦельПереключается(): void
    {
        seoGoalCreate($this->goal());
        $id = $this->goalIdByName('Завершение заказа');

        $toggled = seoGoalToggle($id);

        self::assertTrue($toggled['ok'], (string) ($toggled['error'] ?? ''));
        self::assertSame('goal_disabled', $toggled['notice']);
        self::assertFalse(seoGoals()[0]['enabled']);

        $toggled = seoGoalToggle($id);

        self::assertTrue($toggled['ok']);
        self::assertSame('goal_enabled', $toggled['notice']);
        self::assertTrue(seoGoals()[0]['enabled']);
    }

    public function testЦельУдаляется(): void
    {
        seoGoalCreate($this->goal());
        $id = $this->goalIdByName('Завершение заказа');

        $deleted = seoGoalDelete($id);

        self::assertTrue($deleted['ok'], (string) ($deleted['error'] ?? ''));
        self::assertSame('goal_deleted', $deleted['notice']);
        self::assertSame([], seoGoals());
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion_day') . ' WHERE goal_id = ?', [$id]));
    }

    // ── Конверсии ─────────────────────────────────────────

    public function testКонверсияФиксируетсяОдинРазНаСессию(): void
    {
        seoGoalCreate($this->goal());
        $goalId = $this->goalIdByName('Завершение заказа');

        $sessionId = newId();
        $visitorId = str_repeat('a', 32);
        $stamp = utcStamp(nowUtc());
        $day = substr($stamp, 0, 10);

        seoStatsRecordConversion($sessionId, $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);
        seoStatsRecordConversion($sessionId, $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);
        seoStatsRecordConversion($sessionId, $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion') . ' WHERE goal_id = ? AND session_id = ?', [$goalId, $sessionId]));
        self::assertSame(1, (int) value('SELECT conversions FROM ' . t('seo_conversion_day') . ' WHERE goal_id = ? AND day = ?', [$goalId, $day]));
    }

    public function testКонверсияПоРазнымСессиям(): void
    {
        seoGoalCreate($this->goal());
        $goalId = $this->goalIdByName('Завершение заказа');

        $visitorId = str_repeat('a', 32);
        $stamp = utcStamp(nowUtc());
        $day = substr($stamp, 0, 10);

        seoStatsRecordConversion('session-1', $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);
        seoStatsRecordConversion('session-2', $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);

        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion')), 'Одна сессия — одна конверсия, разные сессии — разные');
    }

    public function testКонверсияСФильтромИсточника(): void
    {
        seoGoalCreate(array_merge($this->goal(), ['source' => 'direct']));
        $goalId = $this->goalIdByName('Завершение заказа');

        $visitorId = str_repeat('a', 32);
        $stamp = utcStamp(nowUtc());
        $day = substr($stamp, 0, 10);

        seoStatsRecordConversion('session-1', $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion')), 'Источник не совпал — конверсии нет');

        seoStatsRecordConversion('session-2', $visitorId, '/order/success', 'direct', 'desktop', $stamp, $day);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion')), 'Источник совпал — конверсия есть');
    }

    public function testКонверсияПоТочномуСовпадению(): void
    {
        seoGoalCreate(array_merge($this->goal(), ['match_type' => SEO_GOAL_MATCH_EXACT, 'pattern' => '/order/success']));
        $goalId = $this->goalIdByName('Завершение заказа');

        $visitorId = str_repeat('a', 32);
        $stamp = utcStamp(nowUtc());
        $day = substr($stamp, 0, 10);

        seoStatsRecordConversion('session-1', $visitorId, '/order/success/extra', 'organic', 'desktop', $stamp, $day);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion')), 'Точное совпадение — лишний хвост не подходит');

        seoStatsRecordConversion('session-2', $visitorId, '/order/success', 'organic', 'desktop', $stamp, $day);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('seo_conversion')));
    }

    public function testКонтентныйОтчет(): void
    {
        seoGoalCreate($this->goal());
        $goalId = $this->goalIdByName('Завершение заказа');

        $today = '2026-10-22';
        $stamp = utcStamp(nowUtc());
        $visitorId = str_repeat('a', 32);

        insert('seo_stat_day', ['day' => $today, 'path' => '/news/item/first', 'source' => 'organic', 'views' => 100, 'sessions' => 40]);
        insert('seo_stat_day', ['day' => $today, 'path' => '/about', 'source' => 'direct', 'views' => 50, 'sessions' => 20]);
        insert('seo_conversion', ['id' => newId(), 'goal_id' => $goalId, 'session_id' => 's1', 'visitor_id' => $visitorId, 'path' => '/news/item/first', 'day' => $today, 'created_at' => $stamp]);
        insert('seo_conversion', ['id' => newId(), 'goal_id' => $goalId, 'session_id' => 's2', 'visitor_id' => $visitorId, 'path' => '/news/item/first', 'day' => $today, 'created_at' => $stamp]);

        $report = seoContentReport($today, $today);

        self::assertCount(2, $report);
        self::assertSame('/news/item/first', $report[0]['path']);
        self::assertSame(100, $report[0]['views']);
        self::assertSame(40, $report[0]['sessions']);
        self::assertSame(2, $report[0]['conversions']);
        self::assertSame(2.0, $report[0]['conversionRate']);
        self::assertSame('/about', $report[1]['path']);
        self::assertSame(0, $report[1]['conversions']);
    }

    // ── Воронки ───────────────────────────────────────────

    public function testВоронкаСоздаетсяИУдаляется(): void
    {
        $created = seoFunnelCreate(['name' => 'Путь к заказу']);

        self::assertTrue($created['ok'], (string) ($created['error'] ?? ''));
        self::assertSame('funnel_created', $created['notice']);

        $funnels = seoFunnels();

        self::assertCount(1, $funnels);
        self::assertSame('Путь к заказу', $funnels[0]['name']);
        self::assertSame(0, $funnels[0]['steps']);
        self::assertTrue($funnels[0]['enabled']);

        $id = $funnels[0]['id'];
        $deleted = seoFunnelDelete($id);

        self::assertTrue($deleted['ok']);
        self::assertSame('funnel_deleted', $deleted['notice']);
        self::assertSame([], seoFunnels());
    }

    public function testШагВоронкиДобавляется(): void
    {
        $created = seoFunnelCreate(['name' => 'Путь к заказу']);
        $funnelId = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        $step = seoFunnelStepCreate(['funnel_id' => $funnelId, 'name' => 'Корзина', 'pattern' => '/cart', 'match_type' => SEO_GOAL_MATCH_EXACT]);

        self::assertTrue($step['ok'], (string) ($step['error'] ?? ''));
        self::assertSame('step_created', $step['notice']);

        $funnels = seoFunnels();

        self::assertSame(1, $funnels[0]['steps']);
    }

    public function testОтчетВоронки(): void
    {
        seoFunnelCreate(['name' => 'Путь к заказу']);
        $funnelId = (string) one('SELECT id FROM ' . t('seo_funnel') . ' WHERE name = ?', ['Путь к заказу'])['id'];

        seoFunnelStepCreate(['funnel_id' => $funnelId, 'name' => 'Главная', 'pattern' => '/', 'match_type' => SEO_GOAL_MATCH_PREFIX]);
        seoFunnelStepCreate(['funnel_id' => $funnelId, 'name' => 'Товар', 'pattern' => '/news/item/', 'match_type' => SEO_GOAL_MATCH_PREFIX]);
        seoFunnelStepCreate(['funnel_id' => $funnelId, 'name' => 'Заказ', 'pattern' => '/order/success', 'match_type' => SEO_GOAL_MATCH_EXACT]);

        $stamp = utcStamp(nowUtc());
        $day = substr($stamp, 0, 10);

        // Сессия прошла все три шага.
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-a', 'visitor_id' => str_repeat('a', 32), 'path' => '/', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'desktop', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-a', 'visitor_id' => str_repeat('a', 32), 'path' => '/news/item/1', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'desktop', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-a', 'visitor_id' => str_repeat('a', 32), 'path' => '/order/success', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'desktop', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);
        // Сессия прошла два шага.
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-b', 'visitor_id' => str_repeat('a', 32), 'path' => '/', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'mobile', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-b', 'visitor_id' => str_repeat('a', 32), 'path' => '/news/item/2', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'mobile', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);
        // Сессия прошла только первый шаг.
        insert('seo_visit', ['id' => newId(), 'session_id' => 's-c', 'visitor_id' => str_repeat('a', 32), 'path' => '/about', 'source' => 'direct', 'referrer_host' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'device' => 'desktop', 'day' => $day, 'hour' => 10, 'created_at' => $stamp]);

        $funnel = ['id' => $funnelId, 'name' => 'Путь к заказу'];
        $report = seoFunnelReport($funnel, $day, $day);

        self::assertSame(3, $report['sessions']);
        self::assertCount(3, $report['steps']);
        self::assertSame(1, $report['steps'][0]['position']);
        self::assertSame('Главная', $report['steps'][0]['name']);
        self::assertSame(3, $report['steps'][0]['sessions']);
        self::assertSame(100.0, $report['steps'][0]['rate']);
        self::assertSame(2, $report['steps'][1]['sessions']);
        self::assertSame(66.7, $report['steps'][1]['rate']);
        self::assertSame(1, $report['steps'][2]['sessions']);
        self::assertSame(50.0, $report['steps'][2]['rate']);
    }
}
