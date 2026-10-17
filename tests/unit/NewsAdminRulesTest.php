<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

// Правила экранов админки: какие кнопки положены строке, как выглядит полоса вкладок раздела
// и какой адрес возврата принимает действие. База здесь не нужна ни одному правилу, поэтому
// проверка живёт в unit-наборе: редирект на чужой хост ловится до первого запроса.
final class NewsAdminRulesTest extends TestCase
{
    /**
     * Строка списка в том виде, как её отдаёт adminItemRow(): шаблон читает готовые поля
     * и не пересчитывает видимость сам.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge(
            ['id' => 'id-materiala', 'status' => NEWS_STATUS['DRAFT'], 'open' => false, 'pinned' => false],
            $overrides
        );
    }

    /**
     * Строка экрана «Блоки» в том виде, как её отдаёт `adminBlockRows()` (задача 8): шаблон читает
     * готовые поля и не пересчитывает видимость сам.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function block(array $overrides = []): array
    {
        return array_merge([
            'id' => 'id-bloka',
            'zone' => 'home',
            'kind' => 'list',
            'title' => 'Свежие материалы',
            'take' => 5,
            'visible' => true,
            'order' => 0,
            'target' => null,
        ], $overrides);
    }

    public function testЧерновикуТолькоПечать(): void
    {
        self::assertSame(
            [['op' => 'publish', 'label' => 'На печать']],
            newsRowActions($this->item()),
            'Черновик нельзя снять с сайта — его на сайте и нет'
        );
    }

    public function testВидуНаСайтеСнятиеИЗакрепление(): void
    {
        self::assertSame(
            [
                ['op' => 'unpublish', 'label' => 'Снять с сайта'],
                ['op' => 'pin', 'label' => 'Закрепить'],
            ],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => true]))
        );

        self::assertSame(
            [
                ['op' => 'unpublish', 'label' => 'Снять с сайта'],
                ['op' => 'unpin', 'label' => 'Открепить'],
            ],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => true, 'pinned' => true]))
        );
    }

    public function testЖдущемуСрокаЗакреплениеНеПредлагается(): void
    {
        // Материал уже со статусом «опубликована», но срок не наступил: в ленте его нет,
        // а закрепление имеет смысл только там. Снятие остаётся — строку надо вернуть в черновик.
        self::assertSame(
            [['op' => 'unpublish', 'label' => 'Снять с сайта']],
            newsRowActions($this->item(['status' => NEWS_STATUS['PUBLISHED'], 'open' => false]))
        );
    }

    public function testНезнакомомуСтатусуКнопокНет(): void
    {
        self::assertSame([], newsRowActions($this->item(['status' => 'ARCHIVED'])), 'Строки из будущего не печатают кнопок');
    }

    public function testПолосаВкладокЗнаетПятьЭкранов(): void
    {
        self::assertCount(5, NEWS_ADMIN_TABS);

        foreach (NEWS_ADMIN_TABS as $tab) {
            self::assertStringStartsWith('/news/admin', (string) $tab['href'], (string) $tab['label']);
            self::assertNotSame('', (string) $tab['label']);
        }
    }

    public function testПолосаВкладокОтмечаетЭкранБлоков(): void
    {
        $nav = newsAdminNav(NEWS_ADMIN_BLOCKS_PATH);

        self::assertSame(1, substr_count($nav, 'aria-current="page"'), 'Отмеченной бывает одна вкладка');
        self::assertStringContainsString('href="/news/admin/blocks" aria-current="page"', $nav);
        self::assertStringContainsString('>Блоки</a>', $nav);
    }

    public function testПолосаВкладокОтмечаетТекущийЭкран(): void
    {
        $nav = newsAdminNav('/news/admin/items');

        self::assertSame(1, substr_count($nav, 'aria-current="page"'), 'Отмеченной бывает одна вкладка');
        self::assertStringContainsString('href="/news/admin/items" aria-current="page"', $nav);
        self::assertStringContainsString('>Сводка</a>', $nav);
        self::assertStringContainsString('aria-label="Экраны раздела', $nav);
    }

    public function testВозвратПринимаетТолькоАдресСписка(): void
    {
        $cases = [
            ['/news/admin/items', '/news/admin/items'],
            ['/news/admin/items?status=DRAFT&page=2', '/news/admin/items?status=DRAFT&page=2'],
            ['', '/news/admin/items'],
            ['/news', '/news/admin/items'],
            ['/news/admin', '/news/admin/items'],
            ['/news/admin/items/123', '/news/admin/items'],
            ['/news/admin/items2', '/news/admin/items'],
            ['//evil.example/news/admin/items', '/news/admin/items'],
            ['https://evil.example/news/admin/items', '/news/admin/items'],
            ["/news/admin/items\nX-Forwarded-For: evil", '/news/admin/items'],
        ];

        foreach ($cases as [$given, $want]) {
            self::assertSame($want, newsAdminBackHref($given), 'Возврат: ' . str_replace("\n", '\n', $given));
        }
    }

    public function testВозвратОбрезаетсяПоПределу(): void
    {
        // Поле приходит из браузера и обязано иметь потолок: бесконечный Location — подарок
        // тому, кто решит раздуть заголовок.
        $long = '/news/admin/items?q=' . str_repeat('а', 400);

        // mb_strlen, а не strlen: обрезка идёт по символам, а кириллица в UTF-8 занимает два байта,
        // и байтовая длина ничего про предел не сказала бы.
        self::assertSame(300, mb_strlen(newsAdminBackHref($long)));
    }

    public function testОповещениеОПерекосеСобираетсяВОдномМесте(): void
    {
        // Оповещение печатают два экрана, и текст у них обязан быть дословно один: пока он был
        // переписан в оба шаблона, правка формулировки расходилась между экранами.
        self::assertSame('', newsPinnedSkewAlert(0), 'Нет закреплённых — не о чем предупреждать');
        self::assertSame('', newsPinnedSkewAlert(PINNED_WARN_LIMIT), 'Ровно предел — ещё не перекос');

        $alert = newsPinnedSkewAlert(PINNED_WARN_LIMIT + 1);

        self::assertStringStartsWith('<p class="rounded-lg border border-bad/30 bg-bad/10', $alert);
        self::assertStringContainsString('role="alert"', $alert, 'Оповещение обязан прочесть скринридер');
        self::assertStringContainsString('Закреплено 6 материалов — это больше 5', $alert);
    }

    public function testКаждаяКнопкаВедётКИзвестномуУведомлению(): void
    {
        self::assertSame(['publish', 'unpublish', 'pin', 'unpin'], array_keys(NEWS_ROW_OP_LABEL));

        // Ключи, которые эти четыре кнопки способны вернуть в адресную строку (publish даёт
        // published или scheduled, повторное нажатие — unchanged всегда).
        foreach (['published', 'scheduled', 'unpublished', 'pinned', 'unpinned', 'unchanged'] as $key) {
            self::assertArrayHasKey($key, NEWS_NOTICE_TEXT, $key);
        }
    }

    // ── Блоки вывода ─────────────────────────────────────

    public function testДействияБлоковОбещаныДевятьИУКаждогоЕстьПодпись(): void
    {
        // Порядок этого списка — порядок, в котором ветки `match` разбирают POST (задача 9), и
        // страж договора действий сверяет его с обработчиком: новое действие без ветки не проходит.
        self::assertSame(
            ['create', 'rename', 'retarget', 'limit', 'show', 'hide', 'up', 'down', 'delete'],
            NEWS_BLOCK_OPS
        );
        self::assertSame(NEWS_BLOCK_OPS, array_keys(NEWS_BLOCK_OP_LABEL), 'Действию без подписи нечего печатать');

        foreach (NEWS_BLOCK_OP_LABEL as $op => $label) {
            self::assertNotSame('', $label, (string) $op);
        }
    }

    public function testЗоныИВидыБлоковПодписаны(): void
    {
        // Подписи читают заголовки трёх таблиц и две формы. Ключи обязаны совпасть со списками
        // задачи 3: зона без подписи напечатала бы пустой заголовок таблицы.
        self::assertSame(NEWS_BLOCK_ZONES, array_keys(NEWS_BLOCK_ZONE_LABEL));
        self::assertSame(NEWS_BLOCK_KINDS, array_keys(NEWS_BLOCK_KIND_LABEL));
    }

    public function testКнопкиСтрокиБлокаЗависятОтСостоянияПоказа(): void
    {
        self::assertSame(
            [
                ['op' => 'hide', 'label' => 'Скрыть'],
                ['op' => 'up', 'label' => 'Вверх'],
                ['op' => 'down', 'label' => 'Вниз'],
                ['op' => 'delete', 'label' => 'Удалить'],
            ],
            newsBlockRowActions($this->block())
        );

        self::assertSame(
            'show',
            newsBlockRowActions($this->block(['visible' => false]))[0]['op'],
            'Скрытому блоку положена кнопка «Показать», а не «Скрыть»'
        );
    }

    public function testПравкиСтрокиНеКнопкиАПоля(): void
    {
        // Заголовок, цель и число — три формы внутри строки. Их подписи шаблон читает прямо из
        // словаря, поэтому в списке кнопок их нет: кнопка «Переименовать» рядом с полем ввода
        // означала бы два способа сделать одно и то же.
        $ops = array_column(newsBlockRowActions($this->block()), 'op');

        foreach (['rename', 'retarget', 'limit'] as $inline) {
            self::assertNotContains($inline, $ops);
        }
    }

    public function testСтрокиРаздаютсяПоЗонамВСпискеЗон(): void
    {
        $grouped = groupBlockRows([
            $this->block(['id' => 'a1', 'zone' => 'article', 'title' => 'Домашний список']),
            $this->block(['id' => 'h1', 'zone' => 'home']),
            $this->block(['id' => 'x1', 'zone' => 'sidebar']),
        ]);

        // Пустая зона обязана остаться в списке со своей таблицей и формой добавления: иначе
        // человек, который ещё ничего не настроил, не нашёл бы, где начинать.
        self::assertSame(NEWS_BLOCK_ZONES, array_keys($grouped));
        self::assertSame(['h1'], array_column($grouped['home'], 'id'));
        self::assertSame(['a1'], array_column($grouped['article'], 'id'));
        self::assertSame([], $grouped['category']);
    }
}
