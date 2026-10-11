<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

// Правила справочников — про договор экрана: какие действия вообще возможны, как они называются
// и какое уведомление обещано каждому. Тексты берутся из CATALOG_NOTICE_TEXT, а не печатаются
// здесь: иначе тест проверял бы сам себя.
use PHPUnit\Framework\TestCase;

final class NewsCatalogRulesTest extends TestCase
{
    public function testВкладокСталоЧетыреИОниВедутНаЭкраныРаздела(): void
    {
        self::assertSame(
            ['/news/admin', '/news/admin/items', '/news/admin/categories', '/news/admin/tags'],
            array_column(NEWS_ADMIN_TABS, 'href')
        );
        self::assertSame(
            ['Сводка', 'Материалы', 'Рубрики', 'Теги'],
            array_column(NEWS_ADMIN_TABS, 'label')
        );
    }

    public function testПолосаВкладокОтмечаетЭкранРубрик(): void
    {
        $nav = newsAdminNav(NEWS_ADMIN_CATEGORIES_PATH);

        self::assertSame(1, substr_count($nav, 'aria-current="page"'));
        self::assertStringContainsString('href="/news/admin/categories" aria-current="page"', $nav);
    }

    public function testДействияРубрикОбещаныРовноВосемь(): void
    {
        self::assertSame(
            ['create', 'rename', 'describe', 'show', 'hide', 'up', 'down', 'delete'],
            NEWS_CATEGORY_OPS
        );
        self::assertSame(NEWS_CATEGORY_OPS, array_keys(NEWS_CATEGORY_OP_LABEL), 'У каждого действия своя подпись');

        foreach (NEWS_CATEGORY_OP_LABEL as $label) {
            self::assertNotSame('', $label);
        }
    }

    public function testДействийТегаДваИСредиНихНетСоздания(): void
    {
        // Критерий §12.15: «создать тег в справочнике нельзя» — проверяется составом действий,
        // пока человек не увидел экран: кнопка «Добавить» здесь была бы обещанием, которое
        // действие не выполняет.
        self::assertSame(['rename', 'delete'], NEWS_TAG_OPS);
        self::assertSame(NEWS_TAG_OPS, array_keys(NEWS_TAG_OP_LABEL));
        self::assertArrayNotHasKey('create', NEWS_TAG_OP_LABEL);
    }

    public function testКаждоеДействиеОбещаетИзвестноеУведомление(): void
    {
        $expected = [
            'create' => ['created'],
            'rename' => ['renamed'],
            'describe' => ['described'],
            'show' => ['shown'],
            'hide' => ['hidden'],
            'up' => ['moved', 'topped'],
            'down' => ['moved', 'bottomed'],
            'delete' => ['deleted'],
            'tagRename' => ['tagRenamed'],
            'tagDelete' => ['tagDeleted'],
        ];

        foreach ($expected as $op => $keys) {
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, CATALOG_NOTICE_TEXT, $op . ' обещает ключ ' . $key);
                self::assertNotSame('', CATALOG_NOTICE_TEXT[$key], $op . ' / ' . $key);
            }
        }
    }

    public function testКнопкиСтрокиРубрикиЗависятОтВидимости(): void
    {
        $visible = ['op' => 'hide', 'label' => 'Скрыть'];
        $hidden = ['op' => 'show', 'label' => 'Показать'];

        $shown = newsCategoryRowActions(['id' => 'x', 'slug' => 'x', 'title' => 'X', 'description' => null, 'order' => 1, 'visible' => true, 'items' => ['total' => 2, 'visible' => 2]]);
        $off = newsCategoryRowActions(['id' => 'x', 'slug' => 'x', 'title' => 'X', 'description' => null, 'order' => 1, 'visible' => false, 'items' => ['total' => 0, 'visible' => 0]]);

        self::assertContains($visible, $shown, 'Видимой рубрике предлагается «Скрыть»');
        self::assertContains($hidden, $off, 'Скрытой — «Показать»');
        self::assertSame(['hide', 'up', 'down', 'delete'], array_column($shown, 'op'));
        self::assertSame(['show', 'up', 'down', 'delete'], array_column($off, 'op'));
    }

    public function testУдалениеЕстьУКаждойСтрокиНезависимоОтСчётчика(): void
    {
        // Правило 2: кнопка — подпись. Право проверяет deleteCategory(), и его отказ обязан
        // быть виден человеком, а не спрятан в разметке.
        $empty = newsTagRowActions(['id' => 'x', 'slug' => 'x', 'label' => 'X', 'items' => 0]);
        $used = newsTagRowActions(['id' => 'x', 'slug' => 'x', 'label' => 'X', 'items' => 4]);

        self::assertSame(['delete'], array_column($empty, 'op'));
        self::assertSame(['delete'], array_column($used, 'op'), 'Тег с материалами тоже получает кнопку — отказ придёт текстом');
    }

    public function testОтказДействияВсегдаИмеетТекст(): void
    {
        $result = catalogFailure('Неизвестное действие с рубрикой.');

        self::assertFalse($result['ok']);
        self::assertSame('', $result['notice'], 'Отказ не несёт ключа уведомления');
        self::assertSame('Неизвестное действие с рубрикой.', $result['error']);
    }

    public function testАдресСтраницыРубрикиСкладываетсяИзЕёАдреса(): void
    {
        self::assertSame('/news/c/zhizn-shkoly', newsHref(['category' => 'zhizn-shkoly']));
        self::assertSame('/news/t/vypusknik', newsHref(['tag' => 'vypusknik']));
    }
}
