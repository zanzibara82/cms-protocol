<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Вставка паттерна на живую базу: блоки добавляются в конец страницы, чужой ключ отклоняется,
 * предел проверяется до вставки. Правила каталога держит страж PatternLibraryTest.
 */
final class PatternLibraryApplyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        foreach (['trash', 'page_old_slug', 'page_section', 'page'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    public function testВставкаДобавляетБлокиПаттерна(): void
    {
        $page = $this->page('pattern-prosto');
        $pattern = patternLibraryFind('three-steps');
        self::assertNotNull($pattern);

        $result = patternLibraryApply((string) $page['id'], 'three-steps');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(count($pattern['sections']), $result['created']);
        self::assertCount(count($pattern['sections']), pageSections((string) $page['id']));
        self::assertSame('steps', (string) pageSections((string) $page['id'])[0]['kind']);
    }

    public function testПаттернДобавляетсяКСуществующемуБлоку(): void
    {
        $pageId = (string) $this->page('pattern-dobavka')['id'];

        $old = createSection($pageId, ['kind' => 'prose', 'anchor' => 'old', 'title' => 'Прежний блок', 'lead_text' => 'Остаётся.']);
        self::assertTrue($old['ok'], $old['error']);

        $result = patternLibraryApply($pageId, 'three-questions');

        self::assertTrue($result['ok'], $result['error']);
        $sections = pageSections($pageId);
        self::assertCount(2, $sections);
        self::assertSame('prose', (string) $sections[0]['kind']);
        self::assertSame('faq', (string) $sections[1]['kind']);
    }

    public function testЧужойКлючОтклоняется(): void
    {
        $page = $this->page('pattern-chuzhoy');

        $result = patternLibraryApply((string) $page['id'], 'net-takogo-patterna');

        self::assertFalse($result['ok']);
        self::assertSame('Такого паттерна нет — обновите список.', $result['error']);
        self::assertSame(0, $result['created']);
        self::assertCount(0, pageSections((string) $page['id']));
    }

    public function testПределСекцийПроверяетсяДоВставки(): void
    {
        $pageId = (string) $this->page('pattern-predel')['id'];

        for ($index = 0; $index < PAGES_SECTIONS_LIMIT; $index++) {
            $added = createSection($pageId, [
                'kind' => 'prose',
                'anchor' => 'block-' . $index,
                'title' => 'Блок ' . $index,
                'lead_text' => 'Текст',
            ]);

            if (($added['ok'] ?? false) !== true) {
                self::fail('Подготовка страницы сорвалась: ' . (string) $added['error']);
            }
        }

        $result = patternLibraryApply($pageId, 'contacts');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('больше ' . PAGES_SECTIONS_LIMIT, $result['error']);
        self::assertCount(PAGES_SECTIONS_LIMIT, pageSections($pageId));
    }

    /** @return array<string, mixed> */
    private function page(string $slug): array
    {
        $created = createPage([
            'title' => 'Страница ' . $slug,
            'slug' => $slug,
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);

        self::assertTrue($created['ok'], $created['error']);

        $page = pageById((string) $created['id']);
        self::assertNotNull($page);

        return $page;
    }
}
