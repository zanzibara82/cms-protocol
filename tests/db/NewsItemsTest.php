<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Правила редактора проверяются на настоящей базе: вкладки списка, счётчики, поиск с LIKE,
// сохранение абзацев и тегов, прежние адреса и отказы кнопок состояния — свойства запросов
// и схемы, а не кода.
final class NewsItemsTest extends NewsDbTestCase
{
    /** Момент в прошлом: порядок списка проверяется на разных секундах, иначе его решила бы база. */
    private static function ago(int $minutes): string
    {
        return utcStamp(nowUtc()->sub(new DateInterval('PT' . $minutes . 'M')));
    }

    private static function ahead(int $minutes): string
    {
        return utcStamp(nowUtc()->add(new DateInterval('PT' . $minutes . 'M')));
    }

    /**
     * Поля в том виде, в котором их возвращает parseNewsForm(): createItem() и updateItem() читают
     * разобранные значения, а не сырой POST.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Вечер встречи',
            'slug' => '',
            'lead' => 'Короткий лид.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => ['Выпускник'],
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    /** @return array{id: string, email: string} */
    private function editor(): array
    {
        return ['id' => 'redaktor-1', 'email' => 'redaktor@shkola.ru'];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function slugs(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = (string) $item['slug'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function page(string $filter, int $page = 1): array
    {
        return adminItemPage(parseAdminFilters(['status' => $filter, 'page' => (string) $page]), nowUtc())['items'];
    }

    public function testСписокАдминкиПоказываетИЧерновики(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'chernovik', ['updated_at' => self::ago(120)]);
        self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);

        $list = adminItemPage(parseAdminFilters([]), nowUtc());

        self::assertSame(2, $list['total']);
        self::assertSame(['na-sajte', 'chernovik'], self::slugs($list['items']), 'свежая правка впереди');
        self::assertTrue($list['items'][0]['open']);
        self::assertFalse($list['items'][1]['open']);
        self::assertSame('Жизнь школы', $list['items'][1]['categoryTitle']);
        self::assertSame(1, $list['pages']);
        self::assertSame([1], $list['window']);
    }

    public function testВкладкиРазличаютЧерновикОпубликованноеИЖдущееСрока(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'zhdet', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ahead(120),
            'updated_at' => self::ago(30),
        ]);
        self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);
        self::makeItem($category, 'chernovik', ['updated_at' => self::ago(90)]);

        self::assertSame(['zhdet'], self::slugs(self::page('scheduled')));
        self::assertSame(['na-sajte'], self::slugs(self::page('PUBLISHED')));
        self::assertSame(['chernovik'], self::slugs(self::page('DRAFT')));
        self::assertSame(['zhdet', 'na-sajte', 'chernovik'], self::slugs(self::page('all')));

        $counts = adminItemCounts(nowUtc());

        self::assertSame(['all' => 3, 'DRAFT' => 1, 'scheduled' => 1, 'PUBLISHED' => 1], $counts);
    }

    public function testПоискСпискаНаходитПоЗаголовкуЛидуИАдресу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        self::makeItem($category, 'itogi-goda', ['title' => 'Итоги года', 'lead_text' => 'Что мы сделали']);
        self::makeItem($category, 'vecher', ['title' => 'Вечер встречи', 'lead_text' => 'Выпускники собрались']);

        // Подчёркивание в LIKE означает «любой символ», поэтому рядом кладётся подставной материал
        // с пробелом на том же месте: экранирование сломано — и запрос про `100_процентов` отдаст
        // оба материала вместо одного.
        self::makeItem($category, 'yubilej', ['title' => 'Юбилей 100_процентов']);
        self::makeItem($category, 'skidka', ['title' => 'Скидка 100 процентов']);

        $cases = [
            'заголовок' => ['итоги', ['itogi-goda']],
            'лид' => ['Выпускники', ['vecher']],
            'адрес' => ['vech', ['vecher']],
            'спецсимволы LIKE' => ['100%', []],
            'подчёркивание как текст' => ['100_процентов', ['yubilej']],
            'шаблон из подчёркивания' => ['_процентов', ['yubilej']],
            'ничего не найдено' => ['такого-нет', []],
        ];

        foreach ($cases as $label => $case) {
            $found = adminItemPage(parseAdminFilters(['q' => (string) $case[0]]), nowUtc());
            self::assertSame($case[1], self::slugs($found['items']), $label);
        }
    }

    public function testФильтрПоРубрикеСужаетСписок(): void
    {
        self::makeItem(categoryIdOf('zhizn-shkoly'), 'pervyy');
        self::makeItem(categoryIdOf('metodika'), 'vtoroy');

        $list = adminItemPage(parseAdminFilters(['cat' => categoryIdOf('metodika')]), nowUtc());

        self::assertSame(['vtoroy'], self::slugs($list['items']));
        self::assertSame('Методика', $list['items'][0]['categoryTitle']);
        self::assertStringContainsString('cat=', $list['href'], 'ссылки пагинатора несут фильтр рубрики');
    }

    public function testСтраницаСпискаРежетсяПоПределу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');

        for ($index = 1; $index <= ADMIN_NEWS_PER_PAGE + 5; $index++) {
            self::makeItem($category, 'm-' . $index, ['updated_at' => self::ago($index)]);
        }

        $first = adminItemPage(parseAdminFilters([]), nowUtc());

        self::assertSame(ADMIN_NEWS_PER_PAGE + 5, $first['total']);
        self::assertSame(ADMIN_NEWS_PER_PAGE, count($first['items']));
        self::assertSame(2, $first['pages']);
        self::assertSame('m-1', $first['items'][0]['slug'], 'свежая правка впереди');

        $second = adminItemPage(parseAdminFilters(['page' => '2']), nowUtc());

        self::assertSame(5, count($second['items']));
        self::assertSame('m-21', $second['items'][0]['slug']);
        self::assertSame(2, $second['current']);

        // Страница за концом ленты возвращается к последней: человек, пришедший по старой ссылке,
        // видит материалы, а не пустой список.
        self::assertSame(2, adminItemPage(parseAdminFilters(['page' => '99']), nowUtc())['current']);
    }

    public function testТегиСтрокЧитаютсяОднимЗапросом(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $first = self::makeItem($category, 'pervyy');
        $second = self::makeItem($category, 'vtoroy');
        $tag = resolveTagIds(['Выпускник', 'Олимпиада']);

        self::linkTag($first, $tag[0]);
        self::linkTag($first, $tag[1]);
        self::linkTag($second, $tag[1]);

        $tags = adminTagsOf([$first, $second]);

        self::assertSame(['Выпускник', 'Олимпиада'], $tags[$first]);
        self::assertSame(['Олимпиада'], $tags[$second]);
        self::assertSame([], adminTagsOf([]));
    }

    public function testФормаВозвращаетИЧерновик(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'vecher-vstrechi', [
            'title' => 'Вечер встречи',
            'lead_text' => 'Короткий лид.',
            'body' => json_encode([['title' => 'Подготовка', 'text' => 'Текст']], JSON_THROW_ON_ERROR),
            'status' => NEWS_STATUS['DRAFT'],
            'seo_title' => 'Вечер встречи выпускников',
            'noindex' => 1,
        ]);
        $tag = resolveTagIds(['Выпускник']);
        self::linkTag($id, $tag[0]);
        self::oldSlug($id, 'staryy-adres');

        $form = itemForForm($id);

        self::assertIsArray($form);
        self::assertSame('vecher-vstrechi', $form['slug']);
        self::assertSame("## Подготовка\nТекст", $form['bodyText']);
        self::assertSame('Выпускник', $form['tags']);
        self::assertSame('черновик', $form['statusLabel']);
        self::assertSame('', $form['publishedAt'], 'у черновика срока нет');
        self::assertSame('Короткий лид.', $form['lead']);
        self::assertSame('Вечер встречи выпускников', $form['seoTitle']);
        self::assertTrue($form['noindex']);
        self::assertSame(['staryy-adres'], $form['oldSlugs']);
        self::assertSame('Жизнь школы', $form['categoryTitle']);
        self::assertSame(NEWS_ADMIN_ITEMS_PATH . '/' . $id, $form['editHref']);
        self::assertNull(itemForForm('нет-такого-материала'));
        self::assertNull(itemForForm(''));
    }

    public function testСозданиеЧерновикаПишетАбзацыТегиИАдрес(): void
    {
        $result = createItem($this->input(), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['notice']);
        self::assertSame('vecher-vstrechi', $result['slug'], 'адрес собран из заголовка');

        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['vecher-vstrechi']);

        self::assertSame($result['id'], $id);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Короткий лид.', $this->column('SELECT lead_text FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'черновик снимок автора не ставит');
        // Абзацы читаются тем же разбором, которым их пишет страница: путь внутри JSON плейсхолдером
        // не передают — MySQL 5.7 путь параметром не принимает, а PHP-разбор проверяет и форму записи.
        $paragraphs = paragraphsOf(value('SELECT body FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Текст абзаца.', (string) ($paragraphs[0]['text'] ?? ''));
        self::assertSame(
            ['Выпускник'],
            array_map(
                static fn (array $row): string => (string) $row['label'],
                all('SELECT tg.label FROM ' . t('news_item_tag') . ' link JOIN ' . t('news_tag') . ' tg ON tg.id = link.tag_id WHERE link.item_id = ?', [$id])
            )
        );
    }

    public function testВыпускБезТекстаНеПроходит(): void
    {
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED'], 'body' => []]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, $result['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_item')));
    }

    public function testВыпускСтавитСрокСнимокАвтораИОткрываетМатериал(): void
    {
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED']]), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('published', $result['notice']);

        $id = $result['id'];

        self::assertSame('redaktor@shkola.ru', $this->column('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('redaktor-1', $this->column('SELECT author_id FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNotSame('', $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'выпуск без срока — сейчас');
        self::assertSame(['vecher-vstrechi'], self::slugs(findFeed()['items']), 'материал сразу виден в ленте');
    }

    public function testВыпускПоСрокуЖдётСвоегоЧаса(): void
    {
        $later = nowUtc()->add(new DateInterval('PT3H'));
        $result = createItem($this->input(['status' => NEWS_STATUS['PUBLISHED'], 'publishedAt' => $later]), $this->editor());

        self::assertTrue($result['ok']);
        self::assertSame('scheduled', $result['notice']);
        self::assertSame(utcStamp($later), $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$result['id']]));
        self::assertSame([], self::slugs(findFeed()['items']), 'до срока материала в ленте нет');
        self::assertSame(['vecher-vstrechi'], self::slugs(self::page('scheduled')));
    }

    public function testЗанятыйАдресНеОтдаётсяВторомуМатериалу(): void
    {
        $first = createItem($this->input(), $this->editor());
        $second = createItem($this->input(), $this->editor());

        self::assertSame('vecher-vstrechi', $first['slug']);
        self::assertSame('vecher-vstrechi-2', $second['slug'], 'адрес из заголовка нумеруется');

        // Вписанный руками адрес не нумеруется: человек дал ссылку, по которой её и будут ждать.
        $typed = createItem($this->input(['slug' => 'vecher-vstrechi']), $this->editor());

        self::assertFalse($typed['ok']);
        self::assertSame('По такому адресу уже есть материал — придумайте другой.', $typed['error']);
    }

    public function testСлужебныйАдресРазделаЗанят(): void
    {
        $result = createItem($this->input(['slug' => 'admin']), $this->editor());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('занят служебной страницей раздела', $result['error']);
    }

    public function testРубрикиБольшеНетОтказНазываетПричину(): void
    {
        $result = createItem($this->input(['categoryId' => newId()]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame('Рубрики, в которую вы кладёте материал, уже нет — выберите другую.', $result['error']);

        self::assertFalse(createItem($this->input(['categoryId' => '']), $this->editor())['ok']);
    }

    public function testСменаАдресаЗапоминаетПрежний(): void
    {
        $created = createItem($this->input(), $this->editor());
        $renamed = updateItem(
            $created['id'],
            $this->input(['slug' => 'novyy-adres', 'title' => 'Вечер встречи выпускников']),
            $this->editor()
        );

        self::assertTrue($renamed['ok']);
        self::assertSame('slugChanged', $renamed['notice']);
        self::assertSame('novyy-adres', $renamed['slug']);
        self::assertSame('novyy-adres', $this->column('SELECT slug FROM ' . t('news_item') . ' WHERE id = ?', [$created['id']]));
        self::assertSame(['vecher-vstrechi'], oldSlugsOf($created['id']));

        $form = itemForForm($created['id']);

        self::assertIsArray($form);
        self::assertSame(['vecher-vstrechi'], $form['oldSlugs']);

        // Правка без смены адреса не плодит прежние адреса.
        updateItem($created['id'], $this->input(['slug' => 'novyy-adres', 'lead' => 'Другой лид.']), $this->editor());

        self::assertSame(['vecher-vstrechi'], oldSlugsOf($created['id']));
    }

    // saveOldSlugs() — функция публичная: её могут позвать со всей историей правок, минуя
    // rememberOldSlug(), которая режет список на своём конце. Срез обязан стоять и в самой записи,
    // иначе в tbl_news_old_slug полезет весь хвост.
    public function testЗаписьПрежнихАдресовРежетсяПоПределу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'dvenadcat-adresov');
        $slugs = [];

        for ($index = 1; $index <= OLD_SLUGS_MAX + 2; $index++) {
            // Ширина два: ORDER BY slug возвращает список в том же порядке, что и переданный,
            // а не в порядке `adres-10` раньше `adres-2`.
            $slugs[] = sprintf('adres-%02d', $index);
        }

        saveOldSlugs($id, $slugs);

        $kept = array_map(
            static fn (array $row): string => (string) $row['slug'],
            all('SELECT slug FROM ' . t('news_old_slug') . ' WHERE item_id = ? ORDER BY slug', [$id])
        );

        self::assertCount(OLD_SLUGS_MAX, $kept, 'Из двенадцати адресов в таблице остаётся ровно десять');
        self::assertSame(array_slice($slugs, 0, OLD_SLUGS_MAX), $kept, 'Остаются первые по порядку, а не хвост списка');
        self::assertNotContains('adres-11', $kept);
        self::assertNotContains('adres-12', $kept);
    }

    public function testПравкаОбновляетПоляТегиИСостояние(): void
    {
        $created = createItem($this->input(), $this->editor());

        $updated = updateItem($created['id'], $this->input([
            'title' => 'Вечер встречи выпускников',
            'lead' => '',
            'tags' => ['Олимпиада'],
            'status' => NEWS_STATUS['PUBLISHED'],
            'pinned' => true,
            'seoDescription' => 'Описание для поисковика.',
        ]), $this->editor());

        self::assertTrue($updated['ok']);
        self::assertSame('published', $updated['notice'], 'переход в опубликованные называет себя, а не «обновлено»');

        $id = $created['id'];

        self::assertSame('Вечер встречи выпускников', $this->column('SELECT title FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNull(value('SELECT lead_text FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'пустой лид стирается в NULL');
        self::assertSame('1', $this->column('SELECT pinned FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('Описание для поисковика.', $this->column('SELECT seo_description FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame('redaktor@shkola.ru', $this->column('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$id]), 'выпуск поставил снимок автора');
        self::assertSame(
            ['Олимпиада'],
            array_map(
                static fn (array $row): string => (string) $row['label'],
                all('SELECT tg.label FROM ' . t('news_item_tag') . ' link JOIN ' . t('news_tag') . ' tg ON tg.id = link.tag_id WHERE link.item_id = ?', [$id])
            ),
            'прежние теги заменены, а не добавлены'
        );

        // Правка черновика не меняет автора: снимок ставит только выпуск на печать.
        $draft = createItem($this->input(['slug' => 'chernovik']), $this->editor());
        updateItem($draft['id'], $this->input(['slug' => 'chernovik', 'lead' => 'Новый лид.']), ['id' => 'drugoy', 'email' => 'drugoy@shkola.ru']);

        self::assertNull(value('SELECT author_email FROM ' . t('news_item') . ' WHERE id = ?', [$draft['id']]));
    }

    public function testПравкаМатериалаКоторогоНетОтвечаетТекстом(): void
    {
        $result = updateItem(newId(), $this->input(), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame('Материала уже нет — обновите страницу.', $result['error']);
    }

    public function testПравкаНеВыпускаетПустойТекст(): void
    {
        $created = createItem($this->input(), $this->editor());
        $result = updateItem($created['id'], $this->input(['status' => NEWS_STATUS['PUBLISHED'], 'body' => []]), $this->editor());

        self::assertFalse($result['ok']);
        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, $result['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$created['id']]));
    }

    public function testКнопкаНаПечатьВыпускаетМатериал(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'chernovik');

        $result = setItemStatus($id, NEWS_STATUS['PUBLISHED']);

        self::assertTrue($result['ok']);
        self::assertSame('published', $result['notice']);
        self::assertSame('PUBLISHED', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertNotSame('', $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame(['chernovik'], self::slugs(findFeed()['items']));
    }

    public function testКнопкаНаПечатьОтказываетБезТекстаИСкрытойРубрике(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $empty = self::makeItem($category, 'bez-teksta', ['body' => '[]']);
        $hidden = self::makeItem(categoryIdOf('metodika'), 'v-skrytoy');

        self::assertSame(NEWS_PUBLISH_NEEDS_TEXT, setItemStatus($empty, NEWS_STATUS['PUBLISHED'])['error']);

        toggleCategoryVisibility(categoryIdOf('metodika'));
        $refused = setItemStatus($hidden, NEWS_STATUS['PUBLISHED']);

        self::assertFalse($refused['ok']);
        self::assertSame('Рубрика «Методика» скрыта: сначала покажите её в справочнике.', $refused['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$hidden]));
    }

    public function testСнятиеССайтаСохраняетСрок(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        // Один вызов на запись и на проверку: пересчёт момента вторым вызовом ловил границу
        // секунды, и тест падал бы без вины кода.
        $publishedAt = self::ago(60);
        $id = self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => $publishedAt,
        ]);

        $result = setItemStatus($id, NEWS_STATUS['DRAFT']);

        self::assertTrue($result['ok']);
        self::assertSame('unpublished', $result['notice']);
        self::assertSame($publishedAt, $this->column('SELECT published_at FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertSame([], self::slugs(findFeed()['items']));
    }

    public function testНезнакомоеСостояниеНеПишетсяВБазу(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $id = self::makeItem($category, 'chernovik');

        $result = setItemStatus($id, 'ARCHIVED');

        self::assertFalse($result['ok']);
        self::assertSame('Незнакомое состояние материала.', $result['error']);
        self::assertSame('DRAFT', $this->column('SELECT status FROM ' . t('news_item') . ' WHERE id = ?', [$id]));
        self::assertFalse(setItemStatus(newId(), NEWS_STATUS['PUBLISHED'])['ok']);
    }

    public function testЗакрепитьМожноТолькоОткрытыйМатериал(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $draft = self::makeItem($category, 'chernovik');
        $open = self::makeItem($category, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(60),
            'updated_at' => self::ago(60),
        ]);

        $refused = setItemPinned($draft, true);

        self::assertFalse($refused['ok']);
        self::assertSame('Закреплять можно материал, который уже на сайте: в ленте он идёт первым, а черновика там нет.', $refused['error']);

        $pinned = setItemPinned($open, true);

        self::assertTrue($pinned['ok']);
        self::assertSame('pinned', $pinned['notice']);
        self::assertSame(1, pinnedVisibleCount(nowUtc()));

        // Закреплённый материал идёт первым, даже если правка старее.
        self::makeItem($category, 'svezhee', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => self::ago(10),
            'updated_at' => self::ago(10),
        ]);

        self::assertSame(['na-sajte', 'svezhee'], array_column(findFeed()['items'], 'slug'));

        $unpinned = setItemPinned($open, false);

        self::assertSame('unpinned', $unpinned['notice']);
        self::assertSame(0, pinnedVisibleCount(nowUtc()));
        self::assertFalse(setItemPinned(newId(), true)['ok']);
    }

    public function testПовторноеДействиеНичегоНеМеняет(): void
    {
        $category = categoryIdOf('zhizn-shkoly');
        $draft = self::makeItem($category, 'chernovik');

        self::assertSame('unchanged', setItemStatus($draft, NEWS_STATUS['DRAFT'])['notice']);
        self::assertSame('unchanged', setItemPinned($draft, false)['notice']);
    }
}
