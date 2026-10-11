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

    public function testПолосаВкладокЗнаетДваЭкрана(): void
    {
        self::assertCount(2, NEWS_ADMIN_TABS);

        foreach (NEWS_ADMIN_TABS as $tab) {
            self::assertStringStartsWith('/news/admin', (string) $tab['href'], (string) $tab['label']);
            self::assertNotSame('', (string) $tab['label']);
        }
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
}
