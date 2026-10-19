<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
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
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');
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

    public function testЧастныйПутьМагазинаЗакрытRobots(): void
    {
        $robots = Kernel::get('/robots.txt');

        self::assertSame(200, $robots['status']);
        self::assertStringContainsString('Disallow: /commerce/admin', $robots['body']);
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
}
