<?php

declare(strict_types=1);

namespace Cms\Tests\db;

// Commerce API (M6): JSON-вид каталога на чтение. Собираем данные полностью
// из базы: список ведёт поток по отметке created_at, карточка разворачивает варианты
// с их ценами. Детали, которые для витрины ничего не решают, наружу не идут.

final class CommerceApiTest extends CommerceDbTestCase
{
    public function testСписокОтбираетТолькоОпубликованных(): void
    {
        self::makeProduct(['slug' => 'vidimyi', 'sku' => 'A-1', 'title' => 'Видимый', 'status' => 'PUBLISHED']);
        self::makeProduct(['slug' => 'skrytyi', 'sku' => 'D-1', 'title' => 'Скрытый', 'status' => 'DRAFT']);

        $rows = commerceApiProductList('');

        self::assertCount(1, $rows, 'Черновик не приглашается в публичный список');
        self::assertSame('vidimyi', (string) $rows[0]['slug']);
        self::assertSame('A-1', (string) $rows[0]['sku']);

        $line = commerceApiProductLine($rows[0]);
        self::assertSame('Видимый', (string) $line['title']);
        self::assertSame(100.0, (float) $line['price_retail']);
        self::assertNull($line['price_promo']);
        self::assertSame('RUB', (string) $line['currency']);
        self::assertTrue((bool) $line['available'], 'Остаток десять — товар доступен');
        self::assertStringEndsWith('/shop/vidimyi', (string) $line['url']);
        self::assertStringStartsWith('http:', (string) $line['url']);
        self::assertNotSame('', (string) $line['created_at']);
    }

    public function testПотокВедётсяОтметкойCreated_at(): void
    {
        self::makeProduct(['slug' => 'pervyi', 'sku' => 'C-1', 'title' => 'Первый']);
        self::makeProduct(['slug' => 'vtoroi', 'sku' => 'C-2', 'title' => 'Второй']);

        // Оба товара созданы в одну секунду; разносим старший на сутки назад,
        // чтобы порядок списка читался без ожидания тика.
        run('UPDATE ' . t('commerce_product') . ' SET created_at = DATE_SUB(created_at, INTERVAL 1 DAY), updated_at = DATE_SUB(updated_at, INTERVAL 1 DAY) WHERE slug = ?', ['pervyi']);

        $rows = commerceApiProductList('');
        self::assertSame('vtoroi', (string) $rows[0]['slug'], 'Свежие товары идут первыми');

        $after = (string) $rows[0]['created_at'];
        $next = commerceApiProductList($after);
        self::assertCount(1, $next, 'Отметка отделяет свежую строку от остального списка');
        self::assertSame('pervyi', (string) $next[0]['slug']);

        $before = commerceApiProductList(utcStamp(nowUtc()->modify('-2 days')));
        self::assertSame([], $before, 'Отметка старше всех строк — порция пуста');

        $broken = commerceApiProductList('это-не-дата');
        self::assertCount(2, $broken, 'Мусор в отметке ведёт к первой порции, а не к ярости');
    }

    public function testПорцияОграниченаДвадцатьюСтроками(): void
    {
        foreach (range(1, 21) as $i) {
            self::makeProduct(['slug' => 'porcia-api-' . $i, 'sku' => 'P-' . $i, 'title' => 'Поря ' . $i]);
        }

        $rows = commerceApiProductList('');

        self::assertSame(COMMERCE_API_PAGE_SIZE, count($rows), 'Порция ограничена, не отдаёт всё разом');
        self::assertSame(21, (int) value('SELECT COUNT(*) FROM ' . t('commerce_product')));
    }

    public function testСтрокиОднойСекундыДоезжаютВторойПорцией(): void
    {
        // Дефект курсора: отметка «последний штамп» при выборке «строже отметки» теряла
        // товары, созданные (импортированные) в одну секунду с границей порции. Композитный
        // курсор «штамп|id» держит границу парой: одна и та же секунда доедает по идентификатору.
        foreach (range(1, 21) as $i) {
            self::makeProduct(['slug' => 'sekunda-' . $i, 'sku' => 'S-' . $i, 'title' => 'Сек ' . $i]);
        }

        // Двадцать одна строка в один и тот же штамп: разрыв страницы обязан падать внутрь группы.
        run('UPDATE ' . t('commerce_product') . ' SET created_at = ?, updated_at = created_at WHERE slug LIKE ?', [utcStamp(nowUtc()), 'sekunda-%']);

        $stamp = (string) value('SELECT created_at FROM ' . t('commerce_product') . ' WHERE slug = ?', ['sekunda-1']);
        self::assertNotSame('', $stamp);

        $first = commerceApiProductList('');
        self::assertCount(COMMERCE_API_PAGE_SIZE, $first);

        // Курсор последней строки первой порции: строка с этим же штампом, но старым идентификатором.
        $cursor = $stamp . '|' . (string) $first[COMMERCE_API_PAGE_SIZE - 1]['id'];
        $second = commerceApiProductList($cursor);

        self::assertCount(1, $second, 'Хвост группы той же секунды не теряется между порциями');
        self::assertNotSame((string) $first[COMMERCE_API_PAGE_SIZE - 1]['id'], (string) $second[0]['id']);

        // Дальше курса нет: третья порция пуста.
        $cursorEnd = $stamp . '|' . (string) $second[0]['id'];
        self::assertSame([], commerceApiProductList($cursorEnd), 'После последней строки потока порция пуста');

        // Курсор середины группы отдаёт ровно остаток группы с меньшими идентификаторами.
        $midCursor = $stamp . '|' . (string) $first[10]['id'];
        self::assertCount(10, commerceApiProductList($midCursor));

        // Чистый штамп прежнего формата совместим: отдаёт только строки строго старше секунды.
        self::assertSame([], commerceApiProductList($stamp), 'Прежний формат отметки продолжает читаться');

        // Мусор в отметке снимается: отвечает первая порция (строк не больше размера порции),
        // без ярости и без пустой выборки.
        self::assertCount(COMMERCE_API_PAGE_SIZE, commerceApiProductList('это-не-курсор|чужой'));
        self::assertCount(COMMERCE_API_PAGE_SIZE, commerceApiProductList('не-штамп'));

        // Хвост композита обязан быть формой идентификатора: подделка «штамп|id|мусор»
        // раньше делала хвост «id|мусор», и строковое сравнение включало саму строку-границу
        // — порция отдавала пару строк вперемешку. Форма не сошлась — курсор мусор целиком,
        // отвечает первая порция, а не полуверная выборка.
        $forged = $stamp . '|' . (string) $first[COMMERCE_API_PAGE_SIZE - 1]['id'] . '|мусор';
        self::assertCount(
            COMMERCE_API_PAGE_SIZE,
            commerceApiProductList($forged),
            'Курсор с чужим хвостом — не курсор: отвечает первая порция, а не строка-граница с хвостом'
        );
    }

    public function testКартаAPIРазворачиваетВарианты(): void
    {
        $productId = self::makeProduct(['slug' => 'variativnyi', 'sku' => 'V-1', 'title' => 'Вариативный']);
        insert('commerce_variant', [
            'id' => newId(),
            'product_id' => $productId,
            'sku' => 'VAR-S',
            'title' => 'Малый',
            'price_retail' => 700.00,
            'price_promo' => 600.50,
            'stock' => 3,
            'position' => 0,
            'supplier_id' => null,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);
        insert('commerce_variant', [
            'id' => newId(),
            'product_id' => $productId,
            'sku' => 'VAR-L',
            'title' => 'Большой',
            'price_retail' => 800.00,
            'price_promo' => null,
            'stock' => 0,
            'position' => 1,
            'supplier_id' => null,
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        $product = commerceProductBySlug('variativnyi');
        self::assertIsArray($product);

        $card = commerceApiProductCard($product);
        $variants = (array) $card['variants'];
        self::assertCount(2, $variants);

        self::assertSame(
            ['VAR-S', 'VAR-L'],
            array_map(static fn (array $row): string => (string) $row['sku'], $variants),
            'Порядок вариантов виден как на карточке: позиция и SKU решают'
        );

        $small = $variants[0];
        self::assertSame(700.0, (float) $small['price_retail']);
        self::assertSame(600.50, (float) $small['price_promo']);
        self::assertTrue((bool) $small['available']);

        $large = $variants[1];
        self::assertNull($large['price_promo']);
        self::assertFalse((bool) $large['available'], 'Нулевой остаток варианта остуден');
    }

    public function testВнутренниеДеталиВариантаНаружуНеИдут(): void
    {
        $productId = self::makeProduct(['slug' => 'zametnyi', 'sku' => 'W-1']);
        insert('commerce_variant', [
            'id' => newId(),
            'product_id' => $productId,
            'sku' => 'W-S',
            'title' => 'Один',
            'price_retail' => 900.00,
            'price_promo' => null,
            'stock' => 1,
            'position' => 0,
            'supplier_id' => newId(),
            'created_at' => utcStamp(nowUtc()),
            'updated_at' => utcStamp(nowUtc()),
        ]);

        $product = commerceProductBySlug('zametnyi');
        self::assertIsArray($product);
        $card = commerceApiProductCard($product);

        $variants = (array) $card['variants'];
        self::assertArrayNotHasKey('supplier_id', $variants[0], 'Поставщик варианта — внутренняя строка, наружу не идёт');
        self::assertArrayNotHasKey('position', $variants[0], 'Порядок расстановки вариантов — внутренняя деталь');
    }
}
