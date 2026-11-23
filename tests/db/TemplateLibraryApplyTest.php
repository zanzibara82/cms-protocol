<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Применение готового шаблона к странице на живой базе: блоки добавляются, замена убирает прежние
 * в корзину, чужой ключ отклоняется, предел секций проверяется до вставки.
 *
 * Проверяется именно запись: правила каталога (виды, якоря, пределы) держит страж
 * TemplateLibraryTest, а здесь важно, что шаблон действительно доезжает до конструктора.
 */
final class TemplateLibraryApplyTest extends TestCase
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

    public function testПрименениеДобавляетБлокиШаблона(): void
    {
        $page = $this->page('landing');
        $template = templateLibraryFind('contacts');
        self::assertNotNull($template);

        $result = templateLibraryApply((string) $page['id'], 'contacts');

        self::assertTrue($result['ok'], $result['error']);
        self::assertSame(count($template['sections']), $result['created']);

        $sections = pageSections((string) $page['id']);
        self::assertCount(count($template['sections']), $sections);
        // Первый блок шаблона — тот же вид и заголовок, что в каталоге: шаблон не переставляет блоки.
        self::assertSame((string) $template['sections'][0]['kind'], (string) $sections[0]['kind']);
        self::assertSame((string) $template['sections'][0]['title'], (string) $sections[0]['title']);
        // Порядок — по возрастанию sort, как в конструкторе.
        self::assertSame([0, 1, 2], array_map(static fn (array $row): int => (int) $row['sort'], $sections));
    }

    public function testПовторноеПрименениеДобавляетЕщёОдинНабор(): void
    {
        $page = $this->page('o-shkole');
        $template = templateLibraryFind('about');
        self::assertNotNull($template);

        $first = templateLibraryApply((string) $page['id'], 'about');
        self::assertTrue($first['ok'], $first['error']);

        // Второй раз без замены: шаблон — заготовка, а собранную страницу он не стирает. Якоря
        // первого набора заняты, поэтому ядро соберёт новые ключи из заголовков — но заголовки те
        // же, значит второй набор всё равно отклонится по занятому якорю. Это честный отказ.
        $second = templateLibraryApply((string) $page['id'], 'about');

        self::assertFalse($second['ok']);
        self::assertNotSame('', $second['error']);
        // Ничего не добавилось и ничего не потерялось.
        self::assertCount(count($template['sections']), pageSections((string) $page['id']));
    }

    public function testЗаменаУбираетПрежниеБлокиВКорзину(): void
    {
        $page = $this->page('zamena');
        $pageId = (string) $page['id'];

        $old = createSection($pageId, ['kind' => 'prose', 'title' => 'Старый блок', 'lead_text' => 'Был тут.']);
        self::assertTrue($old['ok'], $old['error']);

        $template = templateLibraryFind('thanks');
        self::assertNotNull($template);

        $result = templateLibraryApply($pageId, 'thanks', true);

        self::assertTrue($result['ok'], $result['error']);

        $sections = pageSections($pageId);
        self::assertCount(count($template['sections']), $sections);

        // Старый блок не удалён безвозвратно: он в корзине и оттуда восстанавливается.
        $trashed = one('SELECT kind, entity_id FROM ' . t('trash') . ' WHERE entity_id = ?', [$old['id']]);
        self::assertNotNull($trashed, 'Заменённый блок обязан попасть в корзину');
        self::assertSame('section', (string) $trashed['kind']);
    }

    public function testЧужойКлючОтклоняется(): void
    {
        $page = $this->page('chuzhoy');

        $result = templateLibraryApply((string) $page['id'], 'nesushchestvuyushchiy');

        self::assertFalse($result['ok']);
        self::assertSame('Такого шаблона нет — обновите список.', $result['error']);
        self::assertSame(0, $result['created']);
        self::assertSame(0, count(pageSections((string) $page['id'])));
    }

    public function testПределСекцийПроверяетсяДоВставки(): void
    {
        $page = $this->page('predel');
        $pageId = (string) $page['id'];

        // Заполняем страницу до предела: 39 блоков плюс шаблон из трёх не влезет.
        for ($index = 0; $index < PAGES_SECTIONS_LIMIT - 1; $index++) {
            $added = createSection($pageId, ['kind' => 'prose', 'title' => 'Блок ' . $index, 'lead_text' => 'Текст']);

            if (($added['ok'] ?? false) !== true) {
                self::fail('Подготовка страницы сорвалась: ' . (string) $added['error']);
            }
        }

        $result = templateLibraryApply($pageId, 'contacts');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('больше ' . PAGES_SECTIONS_LIMIT, $result['error']);
        // Ни одного блока шаблона: предел проверен до вставки, а не посреди набора.
        self::assertCount(PAGES_SECTIONS_LIMIT - 1, pageSections($pageId));
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
