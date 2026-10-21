<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Аудит раздела «SEO и статистика»: замечания к собственным метаданным и их история.
 *
 * Набор проверяет обещания раздела: проверка идёт по нашим данным (справочник метаданных и
 * карта сайта), замечание о чужой странице не выдумывается, а находка живёт с историей и
 * отмечается исправленной, когда замечание перестало повторяться.
 */
final class SeoAuditTest extends TestCase
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
        run('DROP TABLE IF EXISTS ' . t('seo_audit_issue'));
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'seo'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'seo'");
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('seo_audit_issue'));
        run('DELETE FROM ' . t('seo_meta'));
    }

    /**
     * Строка метаданных для проверки: полей много, а проверка смотрит на шесть.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function row(string $path, array $overrides = []): array
    {
        return array_merge([
            'path' => $path,
            'title' => 'Заголовок страницы',
            'description' => 'Описание страницы',
            'canonical' => '',
            'noindex' => 0,
            'in_sitemap' => 1,
        ], $overrides);
    }

    /**
     * Коды замечаний по адресу.
     *
     * @param list<array<string, mixed>> $issues
     * @return list<string>
     */
    private static function codes(array $issues, string $path): array
    {
        $out = [];

        foreach ($issues as $issue) {
            if ((string) $issue['path'] === $path) {
                $out[] = (string) $issue['code'];
            }
        }

        return $out;
    }

    // ── Проверки ───────────────────────────────────────────

    public function testБезЗаголовкаИОписанияЕстьЗамечания(): void
    {
        $issues = seoAuditFindIssues([self::row('/obhod', ['title' => '', 'description' => ''])]);

        self::assertContains('missing_title', self::codes($issues, '/obhod'));
        self::assertContains('missing_description', self::codes($issues, '/obhod'));
    }

    public function testДлинныйЗаголовокИОписаниеЗамечаются(): void
    {
        $issues = seoAuditFindIssues([self::row('/obhod', [
            'title' => str_repeat('а', SEO_META_TITLE_RECOMMENDED + 1),
            'description' => str_repeat('б', SEO_META_DESCRIPTION_RECOMMENDED + 1),
        ])]);

        self::assertContains('title_too_long', self::codes($issues, '/obhod'));
        self::assertContains('description_too_long', self::codes($issues, '/obhod'));
    }

    public function testОдинаковыеЗаголовкиЗамечаютсяУОбоихАдресов(): void
    {
        $issues = seoAuditFindIssues([
            self::row('/pervyy', ['title' => 'Один и тот же заголовок']),
            self::row('/vtoroy', ['title' => 'Один и тот же заголовок']),
        ]);

        self::assertContains('duplicate_title', self::codes($issues, '/pervyy'));
        self::assertContains('duplicate_title', self::codes($issues, '/vtoroy'));
    }

    public function testКаноникНаЧужойСайтКритичен(): void
    {
        $issues = seoAuditFindIssues([self::row('/obhod', ['canonical' => 'https://drugoy-site.dev/page'])]);

        $codes = self::codes($issues, '/obhod');

        self::assertContains('canonical_foreign', $codes);
        self::assertNotContains('canonical_missing_target', $codes, 'Чужой каноник — это одно замечание, а не два');
    }

    public function testКаноникНаНеизвестныйНашАдресКритичен(): void
    {
        $issues = seoAuditFindIssues(
            [self::row('/obhod', ['canonical' => '/net-takoy-stranicy'])],
            ['/obhod']
        );

        self::assertContains('canonical_missing_target', self::codes($issues, '/obhod'));
    }

    public function testКаноникНаДругуюНашуСтраницуЗамечаниеРекомендательное(): void
    {
        $issues = seoAuditFindIssues(
            [self::row('/obhod', ['canonical' => '/drugaya-stranica'])],
            ['/obhod', '/drugaya-stranica']
        );

        $codes = self::codes($issues, '/obhod');

        self::assertContains('canonical_other_page', $codes);
        self::assertNotContains('canonical_missing_target', $codes, 'Страница есть в списке наших — пропажи тут не было');
    }

    public function testАдресВКартеИЗNoindexПротиворечитСебе(): void
    {
        $issues = seoAuditFindIssues([self::row('/obhod', ['noindex' => 1, 'in_sitemap' => 1])]);

        self::assertContains('sitemap_noindex_conflict', self::codes($issues, '/obhod'));
    }

    public function testЧистыеМетаданныеНеДаютЗамечаний(): void
    {
        $issues = seoAuditFindIssues([self::row('/obhod')], ['/obhod']);

        self::assertSame([], $issues, 'Замечаний без причины не бывает');
    }

    // ── Прогон и история ───────────────────────────────────

    public function testПрогонЗаписываетНаходки(): void
    {
        insert('seo_meta', [
            'id' => newId(),
            'path' => '/obhod',
            'title' => null,
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

        $summary = seoAuditRun();

        self::assertSame(2, $summary['total'], 'Без заголовка и без описания — два замечания');
        self::assertSame(2, $summary['fresh']);
        self::assertSame(0, $summary['fixed']);

        $issues = seoAuditIssues();
        self::assertCount(2, $issues);
    }

    public function testПовторныйПрогонНеПлодитДубликаты(): void
    {
        insert('seo_meta', [
            'id' => newId(),
            'path' => '/obhod',
            'title' => null,
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

        seoAuditRun();
        $summary = seoAuditRun();

        self::assertCount(2, seoAuditIssues(), 'Повторный прогон обновляет историю, а не заводит вторую строку');
        self::assertSame(0, $summary['fresh']);
        self::assertSame(2, (int) (seoAuditIssues()[0]['seen_count'] ?? 0));
    }

    public function testИсправленноеЗамечаниеОтмечается(): void
    {
        insert('seo_meta', [
            'id' => newId(),
            'path' => '/obhod',
            'title' => null,
            'description' => 'Описание есть',
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

        seoAuditRun();
        self::assertCount(1, seoAuditIssues());

        run('UPDATE ' . t('seo_meta') . " SET title = 'Заголовок появился' WHERE path = '/obhod'");

        $summary = seoAuditRun();

        self::assertSame(1, $summary['fixed'], 'Замечание, которое перестало повторяться, отмечается исправленным');
        self::assertSame(0, $summary['open']);

        $issues = seoAuditIssues();
        self::assertCount(1, $issues, 'Исправленное замечание остаётся в списке — с отметкой, когда его видели в последний раз');
        self::assertSame('fixed', (string) $issues[0]['status']);
        self::assertNotNull($issues[0]['fixed_at']);
    }

    public function testСводкаСчитаетПоВажности(): void
    {
        insert('seo_meta', [
            'id' => newId(),
            'path' => '/obhod',
            'title' => null,
            'description' => null,
            'canonical' => 'https://drugoy-site.dev/page',
            'robots' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image' => null,
            'noindex' => 0,
            'in_sitemap' => 1,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        seoAuditRun();

        $summary = seoAuditSummary();

        self::assertSame(1, $summary['critical'], 'Каноник на чужой сайт — критичное замечание');
        self::assertSame(1, $summary['warning'], 'Без заголовка — предупреждение');
        self::assertSame(1, $summary['recommendation'], 'Без описания — рекомендация');
        self::assertSame(3, $summary['open']);
    }

    public function testВажностьБерётсяИзКаталогаПроверок(): void
    {
        self::assertSame('critical', seoAuditSeverity('canonical_foreign'));
        self::assertSame('warning', seoAuditSeverity('missing_title'));
        self::assertSame('recommendation', seoAuditSeverity('missing_description'));
    }
}
