<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Справочники проверяются на настоящей базе: уникальность названия, внешний ключ материала
// и каскадное удаление связей тега — свойства схемы, а не кода.
final class CatalogTest extends NewsDbTestCase
{
    public function testНоваяРубрикаВстаётВКонецСписка(): void
    {
        $result = createCategory('Олимпиады');

        self::assertTrue($result['ok']);
        self::assertSame('created', $result['notice']);
        self::assertSame('olimpiady', $this->column('SELECT slug FROM ' . t('news_category') . " WHERE title = 'Олимпиады'"));
        self::assertSame(
            ['Жизнь школы', 'Поступление', 'Методика', 'Олимпиады'],
            array_map(static fn (array $row): string => (string) $row['title'], adminCategoryRows())
        );
    }

    public function testНазваниеРубрикиНеПовторяетсяБезУчётаРегистра(): void
    {
        $cases = [
            'то же название' => 'Методика',
            'другой регистр' => 'МЕТОДИКА',
            'лишние пробелы и перенос' => "  Методика \n",
        ];

        foreach ($cases as $label => $title) {
            $result = createCategory($title);

            self::assertFalse($result['ok'], $label);
            self::assertSame('Рубрика с таким названием уже есть.', $result['error'], $label);
        }
    }

    public function testКороткоеНазваниеРубрикиОтклоняется(): void
    {
        $result = createCategory('А');

        self::assertFalse($result['ok']);
        self::assertSame('Название короче 2 символов — напишите его целиком.', $result['error']);
    }

    public function testНазваниеИзЗнаковПрепинанияНеДаётАдреса(): void
    {
        $result = createCategory('!!!');

        self::assertFalse($result['ok']);
        self::assertSame('Название должно содержать буквы или цифры: из него строится адрес рубрики.', $result['error']);
    }

    public function testСлишкомДлинноеНазваниеОбрезаетсяПоПределуПоля(): void
    {
        $result = createCategory(str_repeat('а', 80));
        $slug = str_repeat('a', 60);

        self::assertTrue($result['ok']);
        self::assertSame(60, mb_strlen($this->column('SELECT title FROM ' . t('news_category') . ' WHERE slug = ?', [$slug])));
    }

    public function testПереименованиеСохраняетАдресРубрики(): void
    {
        $id = categoryIdOf('metodika');

        $result = renameCategory($id, 'Методика преподавания');

        self::assertTrue($result['ok']);
        self::assertSame('renamed', $result['notice']);
        self::assertSame('metodika', $this->column('SELECT slug FROM ' . t('news_category') . ' WHERE id = ?', [$id]));
        self::assertSame('Методика преподавания', $this->column('SELECT title FROM ' . t('news_category') . ' WHERE id = ?', [$id]));
    }

    public function testПереименованиеНесуществующейРубрикиОтклоняется(): void
    {
        $result = renameCategory('нет-такой-рубрики', 'Другое название');

        self::assertFalse($result['ok']);
        self::assertSame('Рубрики уже нет — обновите страницу.', $result['error']);
    }

    // Уникальность названия — правило не только создания: переименование и правка описания обязаны
    // отказать по тому же тексту. Без их проверки человек получил бы ошибку базы на уникальном
    // индексе `news_category_title` вместо объяснения.
    public function testДубликатНазванияОтклоняетсяПриПереименованииИПравкеОписания(): void
    {
        $renamed = renameCategory(categoryIdOf('metodika'), "  Поступление \n");
        self::assertFalse($renamed['ok']);
        self::assertSame('Рубрика с таким названием уже есть.', $renamed['error']);

        $described = updateCategoryDescription(categoryIdOf('zhizn-shkoly'), 'МЕТОДИКА', 'Что смотрят на странице рубрики.');
        self::assertFalse($described['ok']);
        self::assertSame('Рубрика с таким названием уже есть.', $described['error']);
    }

    public function testОписаниеРубрикиСохраняетсяИОбнуляется(): void
    {
        $id = categoryIdOf('postuplenie');

        $saved = updateCategoryDescription($id, 'Поступление', 'Как школа готовит к экзаменам.');
        self::assertTrue($saved['ok']);
        self::assertSame('described', $saved['notice']);

        $category = findCategory('postuplenie');
        self::assertIsArray($category);
        self::assertSame('Как школа готовит к экзаменам.', $category['description']);

        // Пустое описание — снятое, а не «не задано»: в базе остаётся NULL, чтобы страница
        // рубрики не печатала пустой абзац.
        $cleared = updateCategoryDescription($id, 'Поступление', '   ');
        self::assertTrue($cleared['ok']);

        $after = findCategory('postuplenie');
        self::assertIsArray($after);
        self::assertNull($after['description']);
    }

    public function testСкрытаяРубрикаНеОткрываетсяИВозвращаетсяОбратно(): void
    {
        $id = categoryIdOf('metodika');

        $hidden = toggleCategoryVisibility($id);
        self::assertTrue($hidden['ok']);
        self::assertSame('hidden', $hidden['notice']);
        // Скрытая рубрика не открывается вовсе: `findCategory()` отвечает null, а не пустым списком.
        // Отказ снимается в переменную, а не оставляется вызовом внутри assertNull(): статический
        // анализ считает повторный вызов `findCategory()` с тем же адресом тем же значением и не
        // поверил бы ниже, что после возврата видимости рубрика снова нашлась.
        $concealed = findCategory('metodika');
        self::assertNull($concealed);
        self::assertNotContains('Методика', self::titles(visibleCategories()));

        $shown = toggleCategoryVisibility($id);
        self::assertSame('shown', $shown['notice']);

        $again = findCategory('metodika');
        self::assertIsArray($again);
        self::assertSame('metodika', $again['slug']);
    }

    public function testПустаяРубрикаУдаляется(): void
    {
        createCategory('Олимпиады');
        $id = categoryIdOf('olimpiady');

        $result = deleteCategory($id);

        self::assertTrue($result['ok']);
        self::assertSame('deleted', $result['notice']);
        self::assertSame('', categoryIdOf('olimpiady'));
    }

    public function testРубрикуСМатериаламиУдалитьНельзя(): void
    {
        createCategory('Олимпиады');
        $id = categoryIdOf('olimpiady');

        $cases = [
            1 => 'В рубрике 1 материал.',
            2 => 'В рубрике 2 материала.',
            5 => 'В рубрике 5 материалов.',
        ];

        foreach ($cases as $count => $expected) {
            self::clearItems();
            for ($n = 1; $n <= $count; $n++) {
                self::makeItem($id, 'material-' . $n, ['status' => NEWS_STATUS['DRAFT']]);
            }

            $result = deleteCategory($id);
            $label = (string) $count;

            self::assertFalse($result['ok'], $label);
            self::assertStringStartsWith($expected, $result['error'], $label);
            self::assertStringContainsString('переведите новости в другую рубрику или скройте', $result['error'], $label);
        }
    }

    public function testПорядокРубрикМеняетсяКнопками(): void
    {
        $result = moveCategory(categoryIdOf('postuplenie'), 'up');

        self::assertTrue($result['ok']);
        self::assertSame('moved', $result['notice']);
        self::assertSame(
            ['Поступление', 'Жизнь школы', 'Методика'],
            array_map(static fn (array $row): string => (string) $row['title'], adminCategoryRows())
        );

        // Порядок пересобирается целиком: после перестановки номера идут 1, 2, 3 без дубликатов.
        self::assertSame([1, 2, 3], array_map(static fn (array $row): int => (int) $row['order'], adminCategoryRows()));
    }

    public function testКрайниеРубрикиНеВыходятЗаПределыСписка(): void
    {
        $topped = moveCategory(categoryIdOf('zhizn-shkoly'), 'up');
        self::assertTrue($topped['ok']);
        self::assertSame('topped', $topped['notice']);

        $bottomed = moveCategory(categoryIdOf('metodika'), 'down');
        self::assertTrue($bottomed['ok']);
        self::assertSame('bottomed', $bottomed['notice']);

        $absent = moveCategory('нет-такой-рубрики', 'up');
        self::assertFalse($absent['ok']);
        self::assertSame('Рубрики уже нет — обновите страницу.', $absent['error']);
    }

    public function testСписокАдминкиПоказываетИЧерновикиРубрики(): void
    {
        $id = categoryIdOf('zhizn-shkoly');
        self::makeItem($id, 'chernovik', ['status' => NEWS_STATUS['DRAFT']]);
        self::makeItem($id, 'na-sajte', [
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT1H'))),
        ]);

        $row = adminCategoryRows()[0];

        self::assertSame('Жизнь школы', $row['title']);
        self::assertSame(2, $row['items']['total']);
        self::assertSame(1, $row['items']['visible']);
    }

    // Счётчик открытых материалов в списке админки обязан совпадать с правилом открытости из
    // `visibility.php`, иначе рубрика врёт: в админке материал посчитан открытым, а на сайте его
    // нет. Поэтому здесь собраны все состояния, которые счётчик обязан пропустить, — черновик с
    // наступившим сроком (так возвращают материал в черновики), опубликованный, который ждёт срока,
    // опубликованный без срока и один настоящий материал на сайте.
    public function testСчётчикОткрытыхМатериаловПропускаетЧерновикИЖдущийСрока(): void
    {
        $id = categoryIdOf('zhizn-shkoly');
        $past = utcStamp(nowUtc()->sub(new DateInterval('PT1H')));
        $future = utcStamp(nowUtc()->add(new DateInterval('PT1H')));

        self::makeItem($id, 'chernovik-so-srokom', ['status' => NEWS_STATUS['DRAFT'], 'published_at' => $past]);
        self::makeItem($id, 'zhdet-sroka', ['status' => NEWS_STATUS['PUBLISHED'], 'published_at' => $future]);
        self::makeItem($id, 'bez-sroka', ['status' => NEWS_STATUS['PUBLISHED'], 'published_at' => null]);
        self::makeItem($id, 'na-sajte', ['status' => NEWS_STATUS['PUBLISHED'], 'published_at' => $past]);

        $row = adminCategoryRows()[0];

        self::assertSame(4, $row['items']['total']);
        self::assertSame(1, $row['items']['visible']);
    }

    public function testФормаМатериалаВидитИскрытыеРубрики(): void
    {
        toggleCategoryVisibility(categoryIdOf('metodika'));

        self::assertContains('Методика', self::titles(categoryOptions()));
        self::assertNotContains('Методика', self::titles(visibleCategories()));
    }

    public function testТегиЗаводятсяИзСпискаНадписей(): void
    {
        $ids = resolveTagIds(['Выпускник', 'олимпиада', 'Выпускник', '  ВЫПУСКНИК  ']);

        self::assertCount(2, $ids);
        self::assertSame(array_values($ids), $ids);
        self::assertSame(['Выпускник', 'олимпиада'], self::labels(visibleTags()));
        self::assertSame(['vypusknik', 'olimpiada'], self::slugs(visibleTags()));
    }

    public function testКороткаяИПустаяНадписьНеСоздаютТег(): void
    {
        self::assertSame([], resolveTagIds(['', '   ', 'а']));
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_tag')));
    }

    public function testСовпавшийАдресТегаПолучаетСуффикс(): void
    {
        // «Мастер-класс» и «Мастер класс» — разные надписи, но один адрес: транслит приводит
        // пробел и дефис к «-». Второй тег обязан получить суффикс, а не занять чужой адрес.
        $first = resolveTagIds(['Мастер-класс']);
        $second = resolveTagIds(['Мастер класс']);

        self::assertSame('master-klass', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$first[0]]));
        self::assertSame('master-klass-2', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$second[0]]));
    }

    public function testДлиннаяНадписьТегаОбрезаетсяПоПределу(): void
    {
        $ids = resolveTagIds([str_repeat('б', 60)]);

        self::assertSame(40, mb_strlen($this->column('SELECT label FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]])));
    }

    public function testПереименованиеТегаСохраняетАдрес(): void
    {
        $ids = resolveTagIds(['олимпиада']);

        $result = renameTag($ids[0], 'Городская олимпиада');

        self::assertTrue($result['ok']);
        self::assertSame('tagRenamed', $result['notice']);
        self::assertSame('olimpiada', $this->column('SELECT slug FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]]));
        self::assertSame('Городская олимпиада', $this->column('SELECT label FROM ' . t('news_tag') . ' WHERE id = ?', [$ids[0]]));
    }

    public function testПереименованиеТегаПроверяетНадпись(): void
    {
        $ids = resolveTagIds(['олимпиада', 'конкурс']);

        $short = renameTag($ids[0], 'О');
        self::assertFalse($short['ok']);
        self::assertSame('Надпись короче 2 символов.', $short['error']);

        $taken = renameTag($ids[0], 'Конкурс');
        self::assertFalse($taken['ok']);
        self::assertSame('Тег с такой надписью уже есть.', $taken['error']);

        $absent = renameTag('нет-такого-тега', 'Другое');
        self::assertFalse($absent['ok']);
        self::assertSame('Тега уже нет — обновите страницу.', $absent['error']);
    }

    public function testПустойТегУдаляется(): void
    {
        $ids = resolveTagIds(['олимпиада']);

        $result = deleteTag($ids[0]);

        self::assertTrue($result['ok']);
        self::assertSame('tagDeleted', $result['notice']);
        self::assertNull(findTag('olimpiada'));
    }

    public function testТегСМатериаламиУдалитьНельзя(): void
    {
        $ids = resolveTagIds(['олимпиада']);
        $itemId = self::makeItem(categoryIdOf('zhizn-shkoly'), 'material', ['status' => NEWS_STATUS['DRAFT']]);
        self::linkTag($itemId, $ids[0]);

        $result = deleteTag($ids[0]);

        self::assertFalse($result['ok']);
        self::assertSame('Тег стоит на 1 материале — сначала снимите его в форме новости.', $result['error']);
        self::assertSame(1, tagCountOf($ids[0]));
    }

    public function testПодборкаПоТегуПроверяетСамТегАНеНаличиеМатериалов(): void
    {
        resolveTagIds(['олимпиада']);

        $tag = findTag('olimpiada');

        self::assertIsArray($tag);
        self::assertSame('олимпиада', $tag['label']);
        self::assertSame(0, tagCountOf($tag['id']));
        self::assertNull(findTag('takogo-tega-net'));
    }

    public function testУдалениеМатериалаСнимаетЕгоТегиНоОставляетСправочник(): void
    {
        $ids = resolveTagIds(['олимпиада']);
        $itemId = self::makeItem(categoryIdOf('zhizn-shkoly'), 'material', ['status' => NEWS_STATUS['DRAFT']]);
        self::linkTag($itemId, $ids[0]);

        self::assertSame(1, tagCountOf($ids[0]));

        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$itemId]);

        self::assertSame(0, tagCountOf($ids[0]));
        self::assertNotNull(findTag('olimpiada'));
    }

    // ── Помощники ────────────────────────────────────────
    // Подготовка базы (установка раздела, закладка, чистка, вставка материала) — в
    // `NewsDbTestCase`; здесь только то, что читает список рубрик или тегов.

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function labels(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['label'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function slugs(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['slug'], $rows);
    }
}
