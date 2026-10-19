<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use CURLFile;
use PHPUnit\Framework\TestCase;

final class CommerceShopTest extends TestCase
{
    private const EDITOR_EMAIL = 'magazin@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        CommerceHttpSetup::install();

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        CommerceHttpSetup::cleanup();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        CommerceHttpSetup::prepare();
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    public function testКаталогОткрываетсяАнониму(): void
    {
        $page = Kernel::get('/shop');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringNotContainsString('Черновой товар', $page['body']);
    }

    public function testЧерновикНеОткрываетсяПоАдресу(): void
    {
        $page = Kernel::get('/shop/draft-tovar');

        self::assertSame(404, $page['status']);
    }

    public function testКарточкаТовараПоказываетЦенуИРазметку(): void
    {
        $page = Kernel::get('/shop/kofemashina');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringContainsString('"@type":"Product"', $page['body']);
        self::assertStringContainsString('"@type":"Offer"', $page['body']);
        self::assertStringContainsString('COFFEE-1', $page['body']);
    }

    public function testСкрытаяРубрикаОтвечает404(): void
    {
        CommerceHttpSetup::hideCategory();

        $page = Kernel::get('/shop/c/drobnost');

        self::assertSame(404, $page['status']);
    }

    public function testАдминкаМагазинаМанитНаВход(): void
    {
        $page = Kernel::get('/commerce/admin');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login', (string) ($page['headers']['location'] ?? ''));
    }

    public function testПотокПокупкиСписываетОстатокИДержитПодтверждение(): void
    {
        // Карточка товара: токен формы живёт на странице
        $page = Kernel::get('/shop/kofemashina');
        self::assertSame(200, $page['status']);

        $added = Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);
        self::assertSame(302, $added['status']);

        $cart = Kernel::get('/shop/cart');
        self::assertSame(200, $cart['status']);
        self::assertStringContainsString('Кофемашина', $cart['body']);
        self::assertStringContainsString('2 240,00 ₽', $cart['body']);

        $checkout = Kernel::post('/shop/order', [
            '_token' => $this->token($cart['body']),
            'customer_name' => 'Анна Полугодова',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '+7 999 111 22 33',
            'address' => 'Псков, улица Ленина 1',
            'comment' => '',
        ]);

        self::assertSame(302, $checkout['status']);
        $location = (string) ($checkout['headers']['location'] ?? '');
        self::assertStringContainsString('/shop/order/', $location);

        // Подтверждение в собственной сессии печатает заказ покупателя.
        $confirm = Kernel::get($location);
        self::assertSame(200, $confirm['status']);
        self::assertStringContainsString('Анна Полугодова', $confirm['body']);

        // Без cookies тот же адрес — отказ: номер держит сессия, а не адрес.
        Kernel::freshCookies();
        $foreign = Kernel::get($location);
        self::assertSame(404, $foreign['status']);

        // Остаток товара уменьшился ровно на одну штуку.
        self::assertSame(34, CommerceHttpSetup::stockOf());
    }

    public function testЗаказБезТокенаОтклоняется(): void
    {
        $response = Kernel::post('/shop/order', [
            'customer_name' => 'Гадюка',
            'customer_email' => 'gadyaka@example.dev',
        ]);

        self::assertSame(403, $response['status']);
    }

    public function testКарточкаСВариантамиПоказываетВариации(): void
    {
        CommerceHttpSetup::addVariant(CommerceHttpSetup::$productId, 'COFFEE-20', '20 Вт, чёрная', 2500.0, 4, 1990.0);
        CommerceHttpSetup::addVariant(CommerceHttpSetup::$productId, 'COFFEE-30', '30 Вт, белая', 3000.0, 0, null, 1);

        $page = Kernel::get('/shop/kofemashina');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('20 Вт, чёрная', $page['body']);
        self::assertStringContainsString('30 Вт, белая', $page['body']);
        // Минимальная цена вариантов сменила Offer на AggregateOffer с границами.
        self::assertStringContainsString('"@type":"AggregateOffer"', $page['body']);
        self::assertStringNotContainsString('"@type":"Offer"', $page['body']);
        self::assertStringContainsString('от 1 990,00 ₽', $page['body']);
        self::assertStringContainsString('Нет в наличии', $page['body']);
    }

    public function testВариантУходитВКорзинуСоСвоейЦеной(): void
    {
        $variantId = CommerceHttpSetup::addVariant(CommerceHttpSetup::$productId, 'COFFEE-20', '20 Вт, чёрная', 2400.0, 2);

        $page = Kernel::get('/shop/kofemashina');
        $added = Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'variant' => $variantId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);
        self::assertSame(302, $added['status']);

        $cart = Kernel::get('/shop/cart');
        self::assertSame(200, $cart['status']);
        self::assertStringContainsString('20 Вт, чёрная', $cart['body']);
        self::assertStringContainsString('2 400,00 ₽', $cart['body']);
    }

    public function testФильтрРубрикиРежетСПискойПоХарактеристикам(): void
    {
        $categoryId = CommerceHttpSetup::categoryId('tehnika');
        $attributeId = CommerceHttpSetup::addAttribute($categoryId, 'moschnost', 'Мощность', 'NUMBER', 'Вт');
        CommerceHttpSetup::addProductValue(CommerceHttpSetup::$productId, $attributeId, '25', 25.0);

        $plain = Kernel::get('/shop/c/tehnika');

        self::assertSame(200, $plain['status']);
        self::assertStringContainsString('Мощность', $plain['body']);
        self::assertStringContainsString('Кофемашина', $plain['body']);

        $searched = Kernel::get('/shop/c/tehnika?attr_moschnost_from=24&attr_moschnost_to=26');

        self::assertSame(200, $searched['status']);
        self::assertStringContainsString('Кофемашина', $searched['body']);
        self::assertStringContainsString('по фильтрам', $searched['body']);

        $missed = Kernel::get('/shop/c/tehnika?attr_moschnost_from=100');

        self::assertSame(200, $missed['status']);
        self::assertStringNotContainsString('Кофемашина', $missed['body']);
        self::assertStringContainsString('Под фильтры ничего не подошло', $missed['body']);
    }

    public function testЭкраныВариантовИХарактеристикОткрываютсяРедактором(): void
    {
        $this->ensureEditor();
        $this->login();

        $variants = Kernel::get('/commerce/admin/products/' . CommerceHttpSetup::$productId . '/variants');
        self::assertSame(200, $variants['status']);
        self::assertStringContainsString('Варианты', $variants['body']);

        $categoryId = CommerceHttpSetup::categoryId('tehnika');
        $attributes = Kernel::get('/commerce/admin/categories/' . $categoryId);
        self::assertSame(200, $attributes['status']);
        self::assertStringContainsString('Характеристики рубрики', $attributes['body']);
        self::assertStringContainsString('Добавить характеристику', $attributes['body']);
    }

    public function testСтатусЗаказаИщетсяПоНомеруИПочте(): void
    {
        $order = CommerceHttpSetup::addOrder();

        Kernel::freshCookies();
        $form = Kernel::get('/shop/order');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Состояние заказа', $form['body']);
        self::assertStringContainsString('name="number"', $form['body']);
        self::assertStringContainsString('name="customer_email"', $form['body']);

        // «№» и регистр e-mail не мешают: письмо набирали в почтовом клиенте.
        $found = Kernel::post('/shop/order/lookup', [
            '_token' => $this->token($form['body']),
            'number' => '№ ' . $order['number'],
            'customer_email' => 'BORIS@example.dev',
        ]);

        self::assertSame(302, $found['status']);
        self::assertStringContainsString('/shop/order/' . $order['number'], (string) ($found['headers']['location'] ?? ''));

        $confirm = Kernel::get('/shop/order/' . $order['number']);
        self::assertSame(200, $confirm['status']);
        self::assertStringContainsString('Борис Колосово', $confirm['body']);
        self::assertStringContainsString('Новый', $confirm['body']);
    }

    public function testФормаПодбораЗаказовЗамораживается(): void
    {
        CommerceHttpSetup::addOrder();

        Kernel::freshCookies();
        $form = Kernel::get('/shop/order');
        $token = $this->token($form['body']);

        // Четыре провала — форма вежлива и держит отказ.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = Kernel::post('/shop/order/lookup', [
                '_token' => $token,
                'number' => '9999' . $attempt,
                'customer_email' => 'perestrelka@example.dev',
            ]);

            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Такого сочетания номера и e-mail нет', $response['body']);
        }

        // Пятый провал замораживает форму: переборщик остановлен, честный клиент подождёт.
        $frozen = Kernel::post('/shop/order/lookup', [
            '_token' => $token,
            'number' => '9999',
            'customer_email' => 'perestrelka@example.dev',
        ]);

        self::assertSame(200, $frozen['status']);
        self::assertStringContainsString('Форма временно закрыта', $frozen['body']);

        // И уже замороженная форма не вычисляет совпадения — даже честная пара не проходит.
        $held = Kernel::post('/shop/order/lookup', [
            '_token' => $token,
            'number' => '1',
            'customer_email' => 'boris@example.dev',
        ]);

        self::assertSame(200, $held['status']);
        self::assertStringContainsString('Форма временно закрыта', $held['body']);
    }

    public function testАдминПроводитЗаказПоЦепочке(): void
    {
        $order = CommerceHttpSetup::addOrder('boris@example.dev', 'NEW');
        $this->ensureEditor();
        $this->login();

        $page = Kernel::get('/commerce/admin/orders');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Оплачен', $page['body']);
        self::assertStringContainsString('Отменить', $page['body']);

        $paid = Kernel::post('/commerce/admin/orders', ['_token' => $this->token($page['body']), 'op' => 'paid', 'id' => $order['id']]);
        self::assertSame(302, $paid['status']);

        $page = Kernel::get('/commerce/admin/orders');
        self::assertStringContainsString('Отправить', $page['body']);

        $shipped = Kernel::post('/commerce/admin/orders', ['_token' => $this->token($page['body']), 'op' => 'ship', 'id' => $order['id']]);
        self::assertSame(302, $shipped['status']);

        $page = Kernel::get('/commerce/admin/orders');
        self::assertStringContainsString('Доставить', $page['body']);

        $done = Kernel::post('/commerce/admin/orders', ['_token' => $this->token($page['body']), 'op' => 'deliver', 'id' => $order['id']]);
        self::assertSame(302, $done['status']);

        // Финал: из «Доставлен» кнопок нет, и попытка отменить возвращает отказ.
        $page = Kernel::get('/commerce/admin/orders');
        self::assertStringContainsString('Доставлен', $page['body']);

        $cancel = Kernel::post('/commerce/admin/orders', ['_token' => $this->token($page['body']), 'op' => 'cancel', 'id' => $order['id']]);
        self::assertSame(302, $cancel['status']);

        $page = Kernel::get('/commerce/admin/orders');
        self::assertStringContainsString('Отправленный или завершённый заказ отменить нельзя', $page['body']);
    }

    public function testЧастныйПутьМагазинаЗакрытRobots(): void
    {
        $robots = Kernel::get('/robots.txt');

        self::assertSame(200, $robots['status']);
        self::assertStringContainsString('Disallow: /commerce/admin', $robots['body']);
    }

    public function testКупонПрименяетсяИСнимаетсяВКорзине(): void
    {
        CommerceHttpSetup::addCoupon('SUMMER', 'PERCENT', 10.0, 100.0);

        $page = Kernel::get('/shop/kofemashina');
        Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);

        $cart = Kernel::get('/shop/cart');
        $applied = Kernel::post('/shop/cart/coupon', [
            '_token' => $this->token($cart['body']),
            'code' => ' summer ',
        ]);

        self::assertSame(302, $applied['status']);

        $cart = Kernel::get('/shop/cart');
        self::assertStringContainsString('Скидка по купону SUMMER', $cart['body']);
        // 10% от 2 240,00: итог к оплате качественно сменён с подытога.
        self::assertStringContainsString('2 016,00 ₽', $cart['body']);
        self::assertStringContainsString('Итого к оплате', $cart['body']);

        $removed = Kernel::post('/shop/cart/coupon', [
            '_token' => $this->token($cart['body']),
            'code' => '',
            'remove' => '1',
        ]);

        self::assertSame(302, $removed['status']);

        $cart = Kernel::get('/shop/cart');
        self::assertStringNotContainsString('Скидка по купону', $cart['body']);
    }

    public function testКорзинаОбъясняетОтказКупона(): void
    {
        $page = Kernel::get('/shop/kofemashina');
        Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);

        $cart = Kernel::get('/shop/cart');
        Kernel::post('/shop/cart/coupon', [
            '_token' => $this->token($cart['body']),
            'code' => 'NoSuchCode',
        ]);

        $cart = Kernel::get('/shop/cart');

        self::assertStringContainsString('Купон NOSUCHCODE не применился', $cart['body']);
        self::assertStringContainsString('Купона с таким кодом нет', $cart['body']);
    }

    public function testЗаказСКупономСжигаетВизит(): void
    {
        CommerceHttpSetup::addCoupon('SUMMER', 'PERCENT', 10.0);

        $page = Kernel::get('/shop/kofemashina');
        Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);

        $cart = Kernel::get('/shop/cart');
        Kernel::post('/shop/cart/coupon', [
            '_token' => $this->token($cart['body']),
            'code' => 'SUMMER',
        ]);

        $cart = Kernel::get('/shop/cart');
        $checkout = Kernel::post('/shop/order', [
            '_token' => $this->token($cart['body']),
            'customer_name' => 'Анна Полугодова',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '',
            'address' => '',
            'comment' => '',
        ]);

        self::assertSame(302, $checkout['status']);
        $confirm = Kernel::get((string) ($checkout['headers']['location'] ?? ''));

        self::assertStringContainsString('Скидка по купону SUMMER', $confirm['body']);
        self::assertStringContainsString('Итого к оплате: 2 016,00 ₽', $confirm['body']);

        $row = (array) one('SELECT coupon_code, total FROM ' . t('commerce_order') . ' ORDER BY number DESC LIMIT 1');

        self::assertSame('SUMMER', (string) $row['coupon_code']);
        self::assertSame(2016.0, (float) $row['total']);
        self::assertSame(
            1,
            (int) value('SELECT used_count FROM ' . t('commerce_coupon') . " WHERE code = 'SUMMER'")
        );
    }

    public function testПромоПравилоСкидываетКорзинуБезКода(): void
    {
        CommerceHttpSetup::addPromo('Летняя скидка', 'PERCENT', 5.0, null, null, null, true);

        $page = Kernel::get('/shop/kofemashina');
        Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);

        $cart = Kernel::get('/shop/cart');
        self::assertStringContainsString('Скидка по правилу «Летняя скидка»', $cart['body']);
        self::assertStringContainsString('Итого к оплате: 2 128,00 ₽', $cart['body']);

        $checkout = Kernel::post('/shop/order', [
            '_token' => $this->token($cart['body']),
            'customer_name' => 'Анна Полугодова',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '',
            'address' => '',
            'comment' => '',
        ]);

        self::assertSame(302, $checkout['status']);

        $confirm = Kernel::get((string) ($checkout['headers']['location'] ?? ''));
        self::assertStringContainsString('Скидка по правилу «Летняя скидка»', $confirm['body']);
        self::assertStringContainsString('2 128,00 ₽', $confirm['body']);

        $row = (array) one('SELECT promo_title, promo_discount, total FROM ' . t('commerce_order') . ' ORDER BY number DESC LIMIT 1');
        self::assertSame('Летняя скидка', (string) $row['promo_title']);
        self::assertSame(112.0, (float) $row['promo_discount']);
        self::assertSame(2128.0, (float) $row['total']);
    }

    public function testОтменаЗаказаВозвращаетВизитКупона(): void
    {
        $this->ensureEditor();
        $this->login();

        CommerceHttpSetup::addCoupon('ONCE', 'PERCENT', 10.0, null, null, 2);

        $page = Kernel::get('/shop/kofemashina');
        Kernel::post('/shop/cart/add', [
            '_token' => $this->token($page['body']),
            'id' => CommerceHttpSetup::$productId,
            'qty' => '1',
            'back' => '/shop/kofemashina',
        ]);

        $cart = Kernel::get('/shop/cart');
        Kernel::post('/shop/cart/coupon', [
            '_token' => $this->token($cart['body']),
            'code' => 'ONCE',
        ]);

        $cart = Kernel::get('/shop/cart');
        $checkout = Kernel::post('/shop/order', [
            '_token' => $this->token($cart['body']),
            'customer_name' => 'Анна Полугодова',
            'customer_email' => 'anna@example.dev',
            'customer_phone' => '',
            'address' => '',
            'comment' => '',
        ]);

        self::assertSame(302, $checkout['status']);
        $orderId = (string) value('SELECT id FROM ' . t('commerce_order') . ' ORDER BY number DESC LIMIT 1');

        $page = Kernel::get('/commerce/admin/orders');
        $cancel = Kernel::post('/commerce/admin/orders', ['_token' => $this->token($page['body']), 'op' => 'cancel', 'id' => $orderId]);
        self::assertSame(302, $cancel['status']);

        // Отмена возвращает сгоревший в сделке визит: два заказа можно было унести.
        self::assertSame(
            0,
            (int) value('SELECT used_count FROM ' . t('commerce_coupon') . " WHERE code = 'ONCE'")
        );
    }

    public function testЭкраныСкидокОткрываютсяРедактором(): void
    {
        $this->ensureEditor();
        $this->login();

        $coupons = Kernel::get('/commerce/admin/coupons');
        self::assertSame(200, $coupons['status']);
        self::assertStringContainsString('Купоны', $coupons['body']);

        $promoId = CommerceHttpSetup::addPromo('Летняя скидка', 'PERCENT', 5.0, null, null, null, true);

        $promos = Kernel::get('/commerce/admin/promos');
        self::assertSame(200, $promos['status']);
        self::assertStringContainsString('Летняя скидка', $promos['body']);

        $saved = Kernel::post('/commerce/admin/promos/' . $promoId, [
            '_token' => $this->token($promos['body']),
            'op' => 'save',
            'title' => 'Летняя скидка',
            'kind' => 'PERCENT',
            'value' => '7',
            'min_total' => '',
            'starts' => '',
            'ends' => '',
            'active' => '1',
        ]);
        self::assertSame(302, $saved['status']);

        $page = Kernel::get((string) ($saved['headers']['location'] ?? '/commerce/admin/promos'));
        self::assertStringContainsString('Правило промо сохранено', $page['body']);

        $promo = (array) one('SELECT value FROM ' . t('commerce_promo') . ' WHERE id = ?', [$promoId]);
        self::assertSame(7.0, (float) $promo['value']);
    }

    public function testАдминСоздаётКупонСоСписка(): void
    {
        $this->ensureEditor();
        $this->login();

        $page = Kernel::get('/commerce/admin/coupons');

        $saved = Kernel::post('/commerce/admin/coupons', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'code' => 'spring-25',
            'kind' => 'FIXED',
            'value' => '250,00',
            'min_total' => '1 000',
            'expires' => '2099-12-31',
            'max_uses' => '5',
            'active' => '1',
        ]);
        self::assertSame(302, $saved['status']);

        $page = Kernel::get((string) ($saved['headers']['location'] ?? '/commerce/admin/coupons'));
        self::assertStringContainsString('Купон сохранён', $page['body']);
        self::assertStringContainsString('SPRING-25', $page['body']);

        $row = (array) one('SELECT kind, value, min_total, max_uses FROM ' . t('commerce_coupon') . " WHERE code = 'SPRING-25'");
        self::assertSame('FIXED', (string) $row['kind']);
        self::assertSame(250.0, (float) $row['value']);
        self::assertSame(1000.0, (float) $row['min_total']);
        self::assertSame(5, (int) $row['max_uses']);
    }

    public function testПоставщикиИПрайслистРаботаютФайлом(): void
    {
        $this->ensureEditor();
        $this->login();

        // Справочник поставщиков: экран, создание, подтверждение уведомлением.
        $page = Kernel::get('/commerce/admin/suppliers');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Поставщики', $page['body']);

        $saved = Kernel::post('/commerce/admin/suppliers', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'title' => 'Восток',
            'note' => 'Пн–Пт.',
        ]);
        self::assertSame(302, $saved['status']);

        $page = Kernel::get((string) ($saved['headers']['location'] ?? '/commerce/admin/suppliers'));
        self::assertStringContainsString('Поставщик сохранён.', $page['body']);

        $supplierId = (string) value('SELECT id FROM ' . t('commerce_supplier') . " WHERE title = 'Восток'");
        self::assertNotSame('', $supplierId);

        // Вариант получает поставщика прямо с экрана вариаций.
        $variantsPath = '/commerce/admin/products/' . CommerceHttpSetup::$productId . '/variants';
        $screen = Kernel::get($variantsPath);
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Поставщик', $screen['body']);

        $created = Kernel::post($variantsPath, [
            '_token' => $this->token($screen['body']),
            'op' => 'create',
            'title' => 'Сливочная',
            'sku' => 'PRB-HTTP-1',
            'price_retail' => '1290,50',
            'price_promo' => '',
            'stock' => '3',
            'supplier' => $supplierId,
        ]);
        self::assertSame(302, $created['status']);

        $page = Kernel::get((string) ($created['headers']['location'] ?? $variantsPath));
        self::assertStringContainsString('Вариант сохранён.', $page['body']);

        $variant = (array) one(
            'SELECT supplier_id, price_retail, stock FROM ' . t('commerce_variant') . " WHERE sku = 'PRB-HTTP-1'"
        );
        self::assertSame($supplierId, (string) $variant['supplier_id']);
        self::assertSame(1290.5, (float) $variant['price_retail']);
        self::assertSame(3, (int) $variant['stock']);

        // Экспорт: CSV с BOM скачиванием, помечен для роботов и без кэша.
        $csv = Kernel::get('/commerce/admin/pricebook/export');
        self::assertSame(200, $csv['status']);
        self::assertStringContainsString('pricebook-', (string) ($csv['headers']['content-disposition'] ?? ''));
        self::assertStringContainsString('.csv', (string) ($csv['headers']['content-disposition'] ?? ''));
        self::assertSame('noindex', (string) ($csv['headers']['x-robots-tag'] ?? ''));
        self::assertStringContainsString('no-store', (string) ($csv['headers']['cache-control'] ?? ''));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv['body']);
        self::assertStringContainsString('sku;товар;вариант;цена;промо;остаток', $csv['body']);
        self::assertStringContainsString('PRB-HTTP-1', $csv['body']);

        // Импорт файлом: экран остаётся открытым с отчётом, правка заходит в базу.
        $page = Kernel::get('/commerce/admin/pricebook');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Применить прайс-лист', $page['body']);
        self::assertStringContainsString('Восток', $page['body']);

        $file = tempnam(sys_get_temp_dir(), 'pricebook-http-') ?: '';
        self::assertNotSame('', $file);
        file_put_contents(
            $file,
            "sku;товар;вариант;цена;промо;остаток\r\nPRB-HTTP-1;Кофемашина;Сливочная;1 350,50;;7\r\n"
        );

        try {
            $applied = Kernel::post('/commerce/admin/pricebook', [
                '_token' => $this->token($page['body']),
                'supplier' => '',
                'csvfile' => new CURLFile($file, 'text/csv', 'pricebook.csv'),
            ]);
        } finally {
            @unlink($file);
        }

        self::assertSame(200, $applied['status']);
        self::assertStringContainsString('Применено строк: <strong>1</strong>', $applied['body']);
        self::assertStringContainsString('из 1.', $applied['body']);

        $variant = (array) one(
            'SELECT price_retail, stock FROM ' . t('commerce_variant') . " WHERE sku = 'PRB-HTTP-1'"
        );
        self::assertSame(1350.5, (float) $variant['price_retail']);
        self::assertSame(7, (int) $variant['stock']);

        // Неизвестный поставщик в форме — отказ на месте, без редиректа.
        $bad = Kernel::post('/commerce/admin/pricebook', [
            '_token' => $this->token($applied['body']),
            'supplier' => 'ZZZZ',
        ]);
        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('Выберите поставщика из списка значений.', $bad['body']);
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $token);

        Kernel::post('/login', [
            '_token' => $token[1] ?? '',
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    /** Редактор завода один на набор: второй тест в классе встречает его уже созданным. */
    private function ensureEditor(): void
    {
        $id = value('SELECT id FROM ' . t('user') . ' WHERE email = ?', [self::EDITOR_EMAIL]);
        if ($id === null) {
            TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');
        }
    }
}
