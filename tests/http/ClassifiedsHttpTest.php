<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Доска объявлений через настоящий HTTP: витрина, кабинет продавца,
 * регистрация с лимитом по IP, админка категорий и карта сайта.
 */
final class ClassifiedsHttpTest extends TestCase
{
    private const SELLER_EMAIL = 'prodavec@example.ru';
    private const SELLER_PASSWORD = 'parol-prodavca-1';
    private const OTHER_EMAIL = 'drugoy@example.ru';
    private const OTHER_PASSWORD = 'parol-drugogo-1';
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'длинный-тестовый-пароль';

    private static string $categoryId = '';
    private static string $sellerId = '';
    private static string $otherId = '';
    private static string $publishedId = '';
    private static string $draftId = '';
    private static string $foreignId = '';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        installApp('classifieds');
        self::installCommerce();

        $moment = utcStamp(nowUtc());
        self::$categoryId = newId();
        insert('classifieds_category', [
            'id' => self::$categoryId,
            'parent_id' => null,
            'slug' => 'tehnika',
            'title' => 'Техника',
            'order_index' => 1,
            'visible' => 1,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        self::$sellerId = TestDb::seedUser(self::SELLER_EMAIL, self::SELLER_PASSWORD, 'seller');
        self::$otherId = TestDb::seedUser(self::OTHER_EMAIL, self::OTHER_PASSWORD, 'seller');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        self::$publishedId = self::addListing(self::$sellerId, 'kofemashina', 'Кофемашина', 'PUBLISHED');
        self::$draftId = self::addListing(self::$sellerId, 'chernovik', 'Черновик продавца', 'DRAFT');
        self::$foreignId = self::addListing(self::$otherId, 'chuzhoe', 'Чужое объявление', 'DRAFT');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        foreach (['classifieds_webhook', 'classifieds_promotion', 'classifieds_notification', 'classifieds_report', 'classifieds_message', 'classifieds_favorite', 'classifieds_seller_profile', 'classifieds_listing_attribute', 'classifieds_listing_image', 'classifieds_listing', 'classifieds_attribute', 'classifieds_category', 'classifieds_registration_attempt'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'classifieds'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'classifieds'");
        self::dropCommerce();
    }

    /**
     * Магазин нужен доске C5: услуги продаются его заказами. Установка начинается с чистых
     * таблиц и отметок — иначе раздел, оставшийся установленным без таблиц, отказал бы
     * в установке, а экраны магазина отвечали бы ошибкой.
     */
    private static function installCommerce(): void
    {
        self::dropCommerce();

        $result = installApp('commerce');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Магазин» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    /** Магазин уходит вместе с доской: таблицы и отметки установки. */
    private static function dropCommerce(): void
    {
        foreach ([
            'commerce_order_item',
            'commerce_order',
            'commerce_variant',
            'commerce_product_attribute',
            'commerce_attribute_choice',
            'commerce_attribute',
            'commerce_product_category',
            'commerce_coupon',
            'commerce_promo',
            'commerce_supplier',
            'commerce_webhook',
            'commerce_product',
            'commerce_category',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }

        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'commerce'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'commerce'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        run('DELETE FROM ' . t('classifieds_registration_attempt'));
        run('DELETE FROM ' . t('classifieds_promotion'));
        run('DELETE FROM ' . t('classifieds_notification'));
        run('DELETE FROM ' . t('classifieds_report'));
        run('DELETE FROM ' . t('classifieds_message'));
        run('DELETE FROM ' . t('classifieds_favorite'));
        run('DELETE FROM ' . t('classifieds_seller_profile'));
        run('DELETE FROM ' . t('classifieds_listing_attribute'));
        run('DELETE FROM ' . t('classifieds_listing_image'));
        run('DELETE FROM ' . t('classifieds_attribute'));
        run('DELETE FROM ' . t('classifieds_webhook'));
        run('DELETE FROM ' . t('login_attempt'));
        // Заказы прошлого теста не должны попасть в выборку нового.
        run('DELETE FROM ' . t('commerce_order_item'));
        run('DELETE FROM ' . t('commerce_order'));
        // Объявления, созданные прошлым тестом, не должны влиять на выдачу следующего.
        run('DELETE FROM ' . t('classifieds_listing') . ' WHERE id NOT IN (?, ?, ?)', [self::$publishedId, self::$draftId, self::$foreignId]);
        run('UPDATE ' . t('classifieds_listing') . " SET status = 'PUBLISHED', title = 'Кофемашина', expires_at = NULL, slug = 'kofemashina' WHERE id = ?", [self::$publishedId]);
        run('UPDATE ' . t('classifieds_listing') . " SET status = 'DRAFT', title = 'Черновик продавца', expires_at = NULL, slug = 'chernovik' WHERE id = ?", [self::$draftId]);
        run('UPDATE ' . t('classifieds_listing') . " SET status = 'DRAFT', title = 'Чужое объявление', expires_at = NULL, slug = 'chuzhoe' WHERE id = ?", [self::$foreignId]);
        run('DELETE FROM ' . t('user') . " WHERE role = 'seller' AND email NOT IN (?, ?)", [self::SELLER_EMAIL, self::OTHER_EMAIL]);
    }

    private static function addListing(string $ownerId, string $slug, string $title, string $status): string
    {
        $id = newId();
        $moment = utcStamp(nowUtc());
        insert('classifieds_listing', [
            'id' => $id,
            'owner_id' => $ownerId,
            'category_id' => self::$categoryId,
            'slug' => $slug,
            'title' => $title,
            'body' => 'Подробное описание объявления для проверки.',
            'price' => 1500.00,
            'currency' => 'RUB',
            'negotiable' => 0,
            'location' => 'Москва',
            'image' => null,
            'status' => $status,
            'seo_title' => null,
            'seo_description' => null,
            'noindex' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    /**
     * @param list<string> $options
     */
    private static function addAttribute(
        string $key,
        string $title,
        string $kind = 'text',
        array $options = [],
        int $filterable = 1
    ): string {
        $id = newId();
        $moment = utcStamp(nowUtc());

        insert('classifieds_attribute', [
            'id' => $id,
            'category_id' => self::$categoryId,
            'key_slug' => $key,
            'title' => $title,
            'kind' => $kind,
            'unit' => null,
            'option_list' => $options === [] ? null : implode("\n", $options),
            'required' => 0,
            'filterable' => $filterable,
            'order_index' => 1,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        return $id;
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Строка кабинета с объявлением: кусок списка вокруг заголовка. Нужен, чтобы проверить
     * счётчик именно своего объявления, а не любого числа со страницы.
     */
    private static function listingRow(string $body, string $title): string
    {
        $position = strpos($body, $title);

        if ($position === false) {
            return '';
        }

        $start = strrpos(substr($body, 0, $position), '<li');
        $end = strpos($body, '</li>', $position);

        if ($start === false || $end === false) {
            return '';
        }

        return substr($body, $start, $end - $start);
    }

    /** Разметка Schema.org со страницы: разобранный JSON-LD.
     * @return array<string, mixed>
     */
    private static function jsonLd(string $body): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);
        $decoded = json_decode((string) ($matches[1] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>} */
    private function login(string $email, string $password): array
    {
        $page = Kernel::get('/login');
        $token = $this->token($page['body']);

        return Kernel::post('/login', [
            '_token' => $token,
            'email' => $email,
            'password' => $password,
            'next' => '/classifieds/cabinet',
        ]);
    }

    public function testКаталогПоказываетОпубликованноеИНеПоказываетЧерновик(): void
    {
        $page = Kernel::get('/classifieds');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringNotContainsString('Черновик продавца', $page['body']);
        self::assertStringContainsString('/classifieds/c/tehnika', $page['body']);
    }

    public function testСтраницаОбъявленияОткрываетсяСЦеной(): void
    {
        $page = Kernel::get('/classifieds/kofemashina');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringContainsString('1 500', $page['body']);
        self::assertStringContainsString('Москва', $page['body']);
    }

    public function testСчётчикПросмотровСчитаетсяИПоказывается(): void
    {
        $listingId = self::addListing(self::$sellerId, 'schyotchik', 'Объявление со счётчиком', 'PUBLISHED');

        $page = Kernel::get('/classifieds/schyotchik');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('1 просмотр', $page['body']);
        self::assertSame(1, (int) value('SELECT views_count FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$listingId]));

        $page = Kernel::get('/classifieds/schyotchik');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('2 просмотра', $page['body']);
        self::assertSame(2, (int) value('SELECT views_count FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$listingId]));

        // Свой просмотр владелец не засчитывает: счётчик он видит, но не накручивает.
        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $page = Kernel::get('/classifieds/schyotchik');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('2 просмотра', $page['body']);
        self::assertSame(2, (int) value('SELECT views_count FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$listingId]));
    }

    public function testСчётчикСкрытГостямНоВиденВладельцуВКабинете(): void
    {
        $listingId = self::addListing(self::$sellerId, 'skryty-schyotchik', 'Объявление со скрытым счётчиком', 'PUBLISHED');
        run('UPDATE ' . t('classifieds_listing') . ' SET views_public = 0 WHERE id = ?', [$listingId]);

        $page = Kernel::get('/classifieds/skryty-schyotchik');
        self::assertSame(200, $page['status']);
        self::assertStringNotContainsString('просмотр', $page['body']);
        self::assertSame(1, (int) value('SELECT views_count FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$listingId]));

        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');
        self::assertSame(200, $cabinet['status']);

        $row = self::listingRow($cabinet['body'], 'Объявление со скрытым счётчиком');
        self::assertStringContainsString('1 просмотр', $row);
    }

    public function testРазметкаОбъявленияОтдаётТоварИКрошки(): void
    {
        $page = Kernel::get('/classifieds/kofemashina');

        self::assertSame(200, $page['status']);

        $json = self::jsonLd($page['body']);
        self::assertSame('https://schema.org', $json['@context'] ?? '');

        $graph = is_array($json['@graph'] ?? null) ? $json['@graph'] : [];
        $product = null;
        $crumbs = null;

        foreach ($graph as $node) {
            if (($node['@type'] ?? '') === 'Product') {
                $product = $node;
            }

            if (($node['@type'] ?? '') === 'BreadcrumbList') {
                $crumbs = $node;
            }
        }

        self::assertIsArray($product);
        self::assertSame('Кофемашина', $product['name'] ?? '');
        self::assertSame(1500.0, (float) ($product['offers']['price'] ?? 0));
        self::assertSame('RUB', $product['offers']['priceCurrency'] ?? '');

        self::assertIsArray($crumbs);
        self::assertCount(3, $crumbs['itemListElement'] ?? []);
        self::assertSame('Доска объявлений', $crumbs['itemListElement'][0]['name'] ?? '');
        self::assertSame('Техника', $crumbs['itemListElement'][1]['name'] ?? '');
    }

    public function testГлавнаяДоскиОтдаётРазметкуСайта(): void
    {
        $page = Kernel::get('/classifieds');

        self::assertSame(200, $page['status']);

        $json = self::jsonLd($page['body']);
        $types = [];

        foreach (is_array($json['@graph'] ?? null) ? $json['@graph'] : [] as $node) {
            $types[] = (string) ($node['@type'] ?? '');
        }

        self::assertContains('WebSite', $types);
        self::assertContains('Organization', $types);
        self::assertContains('ItemList', $types);
    }

    public function testJsonВыдачаОтдаётСписокИКарточкуОбъявления(): void
    {
        $list = Kernel::get('/classifieds/api/listings');

        self::assertSame(200, $list['status']);
        self::assertSame('application/json; charset=utf-8', $list['headers']['content-type'] ?? '');
        self::assertSame('noindex', $list['headers']['x-robots-tag'] ?? '');
        self::assertSame('no-store', $list['headers']['cache-control'] ?? '');

        $decoded = json_decode($list['body'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('items', $decoded);
        self::assertArrayHasKey('next_after', $decoded);
        self::assertNotEmpty($decoded['items']);

        $card = Kernel::get('/classifieds/api/listings/kofemashina');
        self::assertSame(200, $card['status']);

        $item = json_decode($card['body'], true);
        self::assertIsArray($item);
        self::assertSame('Кофемашина', $item['title'] ?? '');
        self::assertSame(1500.0, (float) ($item['price'] ?? 0));
        self::assertSame('RUB', $item['currency'] ?? '');
        self::assertArrayHasKey('attributes', $item);
        self::assertArrayHasKey('body', $item);

        $missing = Kernel::get('/classifieds/api/listings/net-takogo-obyavleniya');
        self::assertSame(404, $missing['status']);
        self::assertSame('noindex', $missing['headers']['x-robots-tag'] ?? '');
    }

    public function testJsonВыдачаНеПоказываетЧерновикИЗакрытоеОбъявление(): void
    {
        $list = Kernel::get('/classifieds/api/listings');

        $decoded = json_decode($list['body'], true);
        self::assertIsArray($decoded);

        $slugs = [];

        foreach ($decoded['items'] as $item) {
            $slugs[] = (string) ($item['slug'] ?? '');
        }

        self::assertNotContains('chernovik', $slugs);
        self::assertContains('kofemashina', $slugs);
    }

    public function testСкрытаяКатегорияНеОткрывается(): void
    {
        run('UPDATE ' . t('classifieds_category') . ' SET visible = 0 WHERE id = ?', [self::$categoryId]);

        try {
            $page = Kernel::get('/classifieds/c/tehnika');
            self::assertSame(404, $page['status']);
        } finally {
            run('UPDATE ' . t('classifieds_category') . ' SET visible = 1 WHERE id = ?', [self::$categoryId]);
        }
    }

    public function testЧерновикВидитТолькоВладелец(): void
    {
        $guest = Kernel::get('/classifieds/chernovik');
        self::assertSame(404, $guest['status']);

        $this->login(self::OTHER_EMAIL, self::OTHER_PASSWORD);
        $stranger = Kernel::get('/classifieds/chernovik');
        self::assertSame(404, $stranger['status']);

        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $owner = Kernel::get('/classifieds/chernovik');
        self::assertSame(200, $owner['status']);
        self::assertStringContainsString('Предпросмотр', $owner['body']);
        self::assertStringContainsString('name="robots" content="noindex', $owner['body']);
    }

    public function testРегистрацияСоздаётПродавцаИОткрываетКабинет(): void
    {
        $page = Kernel::get('/classifieds/register');
        self::assertSame(200, $page['status']);

        $created = Kernel::post('/classifieds/register', [
            '_token' => $this->token($page['body']),
            'email' => 'novyy@example.ru',
            'name' => 'Новый продавец',
            'password' => 'parol-novogo-1',
        ]);

        self::assertSame(302, $created['status']);
        self::assertStringStartsWith('/classifieds/cabinet', (string) ($created['headers']['location'] ?? ''));

        $cabinet = Kernel::get('/classifieds/cabinet');
        self::assertSame(200, $cabinet['status']);
        self::assertStringContainsString('Мои объявления', $cabinet['body']);

        $row = one('SELECT role, name FROM ' . t('user') . ' WHERE email = ?', ['novyy@example.ru']);
        self::assertNotNull($row);
        self::assertSame('seller', $row['role']);
        self::assertSame('Новый продавец', $row['name']);
    }

    public function testРегистрацияУважаетЛимитПоАдресу(): void
    {
        $moment = utcStamp(nowUtc());

        for ($i = 0; $i < 10; $i++) {
            insert('classifieds_registration_attempt', [
                'email' => 'spam-' . $i . '@example.ru',
                'ip' => '127.0.0.1',
                'attempted_at' => $moment,
            ]);
        }

        $page = Kernel::get('/classifieds/register');
        $blocked = Kernel::post('/classifieds/register', [
            '_token' => $this->token($page['body']),
            'email' => 'pozdno@example.ru',
            'name' => 'Поздний гость',
            'password' => 'parol-pozdnego-1',
        ]);

        self::assertSame(200, $blocked['status']);
        self::assertStringContainsString('Слишком много попыток', $blocked['body']);
        self::assertNull(one('SELECT id FROM ' . t('user') . ' WHERE email = ?', ['pozdno@example.ru']));
    }

    public function testВходПродавцаВозвращаетВКабинет(): void
    {
        $response = $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);

        self::assertSame(302, $response['status']);
        self::assertSame('/classifieds/cabinet', (string) ($response['headers']['location'] ?? ''));
    }

    public function testПродавецСоздаётОбъявлениеИОноПоявляетсяНаДоске(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $form = Kernel::get('/classifieds/cabinet/new');
        self::assertSame(200, $form['status']);

        $saved = Kernel::post('/classifieds/cabinet/new', [
            '_token' => $this->token($form['body']),
            'title' => 'Стол письменный',
            'slug' => '',
            'category' => self::$categoryId,
            'body' => 'Стол в хорошем состоянии, без царапин.',
            'price' => '4500',
            'location' => 'Москва',
            'status' => 'PUBLISHED',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertStringContainsString('/classifieds/cabinet', (string) ($saved['headers']['location'] ?? ''));

        $row = one('SELECT id, slug, status FROM ' . t('classifieds_listing') . ' WHERE slug = ?', ['stol-pismennyy']);
        self::assertNotNull($row);
        self::assertSame('PENDING_REVIEW', $row['status']);
        Kernel::freshCookies();
        self::assertSame(404, Kernel::get('/classifieds/stol-pismennyy')['status']);

        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $queue = Kernel::get('/classifieds/admin/moderation');
        self::assertSame(200, $queue['status']);
        self::assertStringContainsString('Стол письменный', $queue['body']);

        $approved = Kernel::post('/classifieds/admin/moderation', [
            '_token' => $this->token($queue['body']),
            'id' => (string) $row['id'],
            'op' => 'approve',
        ]);
        self::assertSame(302, $approved['status']);
        self::assertSame('PUBLISHED', value('SELECT status FROM ' . t('classifieds_listing') . ' WHERE id = ?', [(string) $row['id']]));

        $public = Kernel::get('/classifieds/stol-pismennyy');
        self::assertSame(200, $public['status']);
        self::assertStringContainsString('Стол письменный', $public['body']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [self::$sellerId, 'moderation']
        ));
    }

    public function testПравкаСохраняетАдресОбъявления(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $form = Kernel::get('/classifieds/cabinet/' . self::$publishedId);
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('name="slug"', $form['body']);

        $saved = Kernel::post('/classifieds/cabinet/' . self::$publishedId, [
            '_token' => $this->token($form['body']),
            'title' => 'Кофемашина новая',
            'slug' => 'kofemashina',
            'category' => self::$categoryId,
            'body' => 'Новая кофемашина, гарантия.',
            'price' => '2500',
            'location' => 'Москва',
            'status' => 'PUBLISHED',
        ]);

        self::assertSame(302, $saved['status']);
        $row = one('SELECT title, slug, price FROM ' . t('classifieds_listing') . ' WHERE id = ?', [self::$publishedId]);
        self::assertNotNull($row);
        self::assertSame('Кофемашина новая', $row['title']);
        self::assertSame('kofemashina', $row['slug']);
        self::assertSame('2500.00', $row['price']);
    }

    public function testЧужоеОбъявлениеНеОткрываетсяВКабинете(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);

        $page = Kernel::get('/classifieds/cabinet/' . self::$foreignId);
        self::assertSame(404, $page['status']);

        $form = Kernel::get('/classifieds/cabinet/' . self::$publishedId);
        $action = Kernel::post('/classifieds/cabinet/' . self::$foreignId . '/action', [
            '_token' => $this->token($form['body']),
            'op' => 'publish',
        ]);
        self::assertSame(302, $action['status']);
        self::assertSame('DRAFT', value('SELECT status FROM ' . t('classifieds_listing') . ' WHERE id = ?', [self::$foreignId]));
    }

    public function testКабинетСнимаетИВозвращаетОбъявление(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');
        $token = $this->token($cabinet['body']);

        $hidden = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $token,
            'op' => 'hide',
        ]);
        self::assertSame(302, $hidden['status']);
        self::assertSame('DRAFT', value('SELECT status FROM ' . t('classifieds_listing') . ' WHERE id = ?', [self::$publishedId]));

        // Владелец видит свой снятый черновик как предпросмотр, гость - уже нет.
        $preview = Kernel::get('/classifieds/kofemashina');
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('Предпросмотр', $preview['body']);

        Kernel::freshCookies();
        self::assertSame(404, Kernel::get('/classifieds/kofemashina')['status']);

        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $fresh = Kernel::get('/classifieds/cabinet');
        $published = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $this->token($fresh['body']),
            'op' => 'publish',
        ]);
        self::assertSame(302, $published['status']);
        self::assertSame('PENDING_REVIEW', value('SELECT status FROM ' . t('classifieds_listing') . ' WHERE id = ?', [self::$publishedId]));
        Kernel::freshCookies();
        self::assertSame(404, Kernel::get('/classifieds/kofemashina')['status']);

        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $queue = Kernel::get('/classifieds/admin/moderation');
        Kernel::post('/classifieds/admin/moderation', [
            '_token' => $this->token($queue['body']),
            'id' => self::$publishedId,
            'op' => 'approve',
        ]);
        self::assertSame(200, Kernel::get('/classifieds/kofemashina')['status']);
    }

    public function testАдминкаКатегорийТолькоДляРедактора(): void
    {
        $guest = Kernel::get('/classifieds/admin/categories');
        self::assertSame(302, $guest['status']);
        self::assertStringContainsString('/login?next=%2Fclassifieds%2Fadmin', (string) ($guest['headers']['location'] ?? ''));

        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $page = Kernel::get('/classifieds/admin/categories');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Техника', $page['body']);

        $saved = Kernel::post('/classifieds/admin/categories', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'title' => 'Мебель',
            'slug' => '',
            'parent' => '',
        ]);
        self::assertSame(302, $saved['status']);

        $after = Kernel::get('/classifieds/admin/categories');
        self::assertStringContainsString('Мебель', $after['body']);
        self::assertSame('mebel', value('SELECT slug FROM ' . t('classifieds_category') . ' WHERE title = ?', ['Мебель']));
    }

    public function testКартаСайтаВключаетОпубликованноеОбъявление(): void
    {
        $page = Kernel::get('/sitemap.xml');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('/classifieds/kofemashina', $page['body']);
        self::assertStringContainsString('/classifieds/c/tehnika', $page['body']);
        self::assertStringNotContainsString('/classifieds/chernovik', $page['body']);
    }

    public function testКартаСайтаПроставляетПриоритетИДату(): void
    {
        $page = Kernel::get('/sitemap.xml');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<priority>0.9</priority>', $page['body']);
        self::assertStringContainsString('<priority>0.6</priority>', $page['body']);
        self::assertMatchesRegularExpression('#<lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $page['body']);
    }

    public function testПоискНаходитОбъявлениеИВсегдаЗакрытОтИндексации(): void
    {
        $page = Kernel::get('/classifieds/search?q=' . rawurlencode('кофемашина'));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Кофемашина', $page['body']);
        self::assertStringContainsString('name="robots" content="noindex', $page['body']);
        self::assertStringNotContainsString('Черновик продавца', $page['body']);

        $empty = Kernel::get('/classifieds/search?q=' . rawurlencode('невидимка'));
        self::assertSame(200, $empty['status']);
        self::assertStringContainsString('не нашлось', $empty['body']);
    }

    public function testФильтрыИСортировкаКатегорииРаботаютПоХарактеристикам(): void
    {
        $color = self::addAttribute('color', 'Цвет', 'select', ['Чёрный', 'Белый']);
        classifiedsReplaceListingAttributes(self::$publishedId, [
            $color => ['value_text' => 'Чёрный', 'value_number' => null],
        ]);

        $filtered = Kernel::get('/classifieds/c/tehnika?f[' . rawurlencode($color) . '][value]=' . rawurlencode('Чёрный'));
        self::assertSame(200, $filtered['status']);
        self::assertStringContainsString('Кофемашина', $filtered['body']);
        self::assertStringContainsString('name="f[' . $color . '][value]"', $filtered['body']);

        run('UPDATE ' . t('classifieds_listing') . ' SET price = 9000 WHERE id = ?', [self::$publishedId]);
        $cheap = self::addListing(self::$sellerId, 'noutbuk', 'Ноутбук', 'PUBLISHED');
        run('UPDATE ' . t('classifieds_listing') . ' SET price = 1000 WHERE id = ?', [$cheap]);

        $sorted = Kernel::get('/classifieds/c/tehnika?sort=price_asc');
        self::assertSame(200, $sorted['status']);
        self::assertLessThan(
            (int) strpos($sorted['body'], 'Кофемашина'),
            (int) strpos($sorted['body'], 'Ноутбук'),
            'Сортировка по цене поставила дешёвое объявление раньше'
        );

        $junk = Kernel::get('/classifieds/c/tehnika?f[' . rawurlencode($color) . '][value]=' . rawurlencode('Лифтбек') . '&sort=magic');
        self::assertSame(200, $junk['status']);
    }

    public function testКабинетСохраняетХарактеристикиОбъявления(): void
    {
        $condition = self::addAttribute('condition', 'Состояние', 'select', ['Новое', 'Б/у'], 1);
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);

        $form = Kernel::get('/classifieds/cabinet/new?category=' . rawurlencode(self::$categoryId));
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('name="attrs[' . $condition . ']"', $form['body']);
        self::assertStringContainsString('name="images[]"', $form['body']);

        $saved = Kernel::post('/classifieds/cabinet/new', [
            '_token' => $this->token($form['body']),
            'title' => 'Пылесос',
            'slug' => 'pylesos',
            'category' => self::$categoryId,
            'body' => 'Работает тихо, есть все насадки.',
            'price' => '3200',
            'location' => 'Москва',
            'status' => 'PUBLISHED',
            'attrs[' . $condition . ']' => 'Новое',
        ]);

        self::assertSame(302, $saved['status']);
        $listing = one('SELECT id FROM ' . t('classifieds_listing') . ' WHERE slug = ?', ['pylesos']);
        self::assertNotNull($listing);
        self::assertSame('Новое', value(
            'SELECT value_text FROM ' . t('classifieds_listing_attribute') . ' WHERE listing_id = ? AND attribute_id = ?',
            [(string) $listing['id'], $condition]
        ));

        // Проверка характеристик не про модерацию: объявление открываем напрямую.
        run(
            'UPDATE ' . t('classifieds_listing') . " SET status = 'PUBLISHED', expires_at = ? WHERE id = ?",
            [utcStamp(nowUtc()->modify('+30 days')), (string) $listing['id']]
        );
        $page = Kernel::get('/classifieds/pylesos');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Состояние', $page['body']);
        self::assertStringContainsString('Новое', $page['body']);

        $edit = Kernel::get('/classifieds/cabinet/' . (string) $listing['id']);
        self::assertSame(200, $edit['status']);
        self::assertStringContainsString('value="Новое"', $edit['body']);
    }

    public function testАдминкаХарактеристикСоздаётПолеИНеУдаляетЗанятое(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $page = Kernel::get('/classifieds/admin/attributes?category=' . rawurlencode(self::$categoryId));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Характеристики', $page['body']);
        self::assertStringContainsString('Техника', $page['body']);

        $created = Kernel::post('/classifieds/admin/attributes', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'category' => self::$categoryId,
            'title' => 'Состояние',
            'key' => 'condition',
            'kind' => 'select',
            'unit' => '',
            'options' => "Новое\nБ/у",
            'filterable' => '1',
        ]);

        self::assertSame(302, $created['status']);
        $attribute = one('SELECT id FROM ' . t('classifieds_attribute') . ' WHERE key_slug = ?', ['condition']);
        self::assertNotNull($attribute);
        $id = (string) $attribute['id'];

        classifiedsReplaceListingAttributes(self::$publishedId, [
            $id => ['value_text' => 'Новое', 'value_number' => null],
        ]);

        $blocked = Kernel::post('/classifieds/admin/attributes', [
            '_token' => $this->token($page['body']),
            'op' => 'delete',
            'category' => self::$categoryId,
            'id' => $id,
        ]);
        self::assertSame(302, $blocked['status']);
        self::assertNotNull(one('SELECT id FROM ' . t('classifieds_attribute') . ' WHERE id = ?', [$id]));

        classifiedsReplaceListingAttributes(self::$publishedId, []);
        $deleted = Kernel::post('/classifieds/admin/attributes', [
            '_token' => $this->token($page['body']),
            'op' => 'delete',
            'category' => self::$categoryId,
            'id' => $id,
        ]);
        self::assertSame(302, $deleted['status']);
        self::assertNull(one('SELECT id FROM ' . t('classifieds_attribute') . ' WHERE id = ?', [$id]));
    }

    public function testПрофильПродавцаСохраняетсяИПубликуется(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);

        $form = Kernel::get('/classifieds/cabinet/profile');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Профиль продавца', $form['body']);

        $saved = Kernel::post('/classifieds/cabinet/profile', [
            '_token' => $this->token($form['body']),
            'name' => 'Анна Продавец',
            'phone' => '+7 999 123-45-67',
            'city' => 'Казань',
            'about' => 'Продаю проверенную технику с гарантией.',
            'show_phone' => '1',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertStringStartsWith('/classifieds/cabinet/profile?notice=profile_saved', (string) ($saved['headers']['location'] ?? ''));
        self::assertSame('Анна Продавец', value('SELECT name FROM ' . t('user') . ' WHERE id = ?', [self::$sellerId]));
        self::assertSame('+7 999 123-45-67', value(
            'SELECT phone FROM ' . t('classifieds_seller_profile') . ' WHERE user_id = ?',
            [self::$sellerId]
        ));

        $public = Kernel::get('/classifieds/seller/' . self::$sellerId);
        self::assertSame(200, $public['status']);
        self::assertStringContainsString('Анна Продавец', $public['body']);
        self::assertStringContainsString('Казань', $public['body']);
        self::assertStringContainsString('+7 999 123-45-67', $public['body']);
        self::assertStringContainsString('проверенную технику', $public['body']);
        self::assertStringContainsString('Кофемашина', $public['body']);
        self::assertStringNotContainsString(self::SELLER_EMAIL, $public['body']);

        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $hidden = Kernel::post('/classifieds/cabinet/profile', [
            '_token' => $this->token(Kernel::get('/classifieds/cabinet/profile')['body']),
            'name' => 'Анна Продавец',
            'phone' => '+7 999 123-45-67',
            'city' => 'Казань',
            'about' => 'Продаю проверенную технику с гарантией.',
        ]);

        self::assertSame(302, $hidden['status']);
        self::assertSame(0, (int) value(
            'SELECT show_phone FROM ' . t('classifieds_seller_profile') . ' WHERE user_id = ?',
            [self::$sellerId]
        ));

        Kernel::freshCookies();
        $withoutPhone = Kernel::get('/classifieds/seller/' . self::$sellerId);
        self::assertSame(200, $withoutPhone['status']);
        self::assertStringNotContainsString('+7 999 123-45-67', $withoutPhone['body']);
        self::assertSame(404, Kernel::get('/classifieds/seller/' . str_repeat('x', 26))['status']);
    }

    public function testПрофильПродавцаТребуетИмя(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $form = Kernel::get('/classifieds/cabinet/profile');

        $invalid = Kernel::post('/classifieds/cabinet/profile', [
            '_token' => $this->token($form['body']),
            'name' => '   ',
            'phone' => '',
            'city' => '',
            'about' => '',
        ]);

        self::assertSame(200, $invalid['status']);
        self::assertStringContainsString('Напишите имя', $invalid['body']);
        self::assertNull(one(
            'SELECT user_id FROM ' . t('classifieds_seller_profile') . ' WHERE user_id = ?',
            [self::$sellerId]
        ));
    }

    public function testИзбранноеДобавляетсяИУбирается(): void
    {
        $guest = Kernel::get('/classifieds/kofemashina');
        self::assertSame(200, $guest['status']);
        self::assertStringContainsString('Войти, чтобы сохранить', $guest['body']);

        $guestPost = Kernel::post('/classifieds/kofemashina/favorite', [
            '_token' => $this->token(Kernel::get('/login')['body']),
            'op' => 'add',
        ]);
        self::assertSame(302, $guestPost['status']);
        self::assertStringStartsWith('/login?next=%2Fclassifieds%2Fkofemashina', (string) ($guestPost['headers']['location'] ?? ''));

        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $listing = Kernel::get('/classifieds/kofemashina');
        self::assertStringContainsString('В избранное', $listing['body']);

        $added = Kernel::post('/classifieds/kofemashina/favorite', [
            '_token' => $this->token($listing['body']),
            'op' => 'add',
        ]);
        self::assertSame(302, $added['status']);
        self::assertStringContainsString('notice=favorite_added', (string) ($added['headers']['location'] ?? ''));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_favorite') . ' WHERE user_id = ? AND listing_id = ?',
            [self::$sellerId, self::$publishedId]
        ));

        $again = Kernel::post('/classifieds/kofemashina/favorite', [
            '_token' => $this->token(Kernel::get('/classifieds/kofemashina')['body']),
            'op' => 'add',
        ]);
        self::assertSame(302, $again['status']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_favorite') . ' WHERE user_id = ? AND listing_id = ?',
            [self::$sellerId, self::$publishedId]
        ));

        $favorites = Kernel::get('/classifieds/cabinet/favorites');
        self::assertSame(200, $favorites['status']);
        self::assertStringContainsString('Кофемашина', $favorites['body']);
        self::assertStringContainsString('Убрать из избранного', $favorites['body']);

        $removed = Kernel::post('/classifieds/cabinet/favorites', [
            '_token' => $this->token($favorites['body']),
            'listing_id' => self::$publishedId,
        ]);
        self::assertSame(302, $removed['status']);
        self::assertStringContainsString('notice=favorite_removed', (string) ($removed['headers']['location'] ?? ''));
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_favorite') . ' WHERE user_id = ? AND listing_id = ?',
            [self::$sellerId, self::$publishedId]
        ));

        $empty = Kernel::get('/classifieds/cabinet/favorites');
        self::assertStringContainsString('Пока пусто', $empty['body']);
    }

    public function testИстёкшееОбъявлениеСкрываетсяИПродлеваетсяПослеОплаты(): void
    {
        run(
            'UPDATE ' . t('classifieds_listing') . ' SET expires_at = ? WHERE id = ?',
            [utcStamp(nowUtc()->sub(new \DateInterval('P1D'))), self::$publishedId]
        );

        self::assertSame(404, Kernel::get('/classifieds/kofemashina')['status']);
        self::assertStringNotContainsString('Кофемашина', Kernel::get('/classifieds/c/tehnika')['body']);
        self::assertStringNotContainsString('/classifieds/kofemashina', Kernel::get('/sitemap.xml')['body']);

        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');
        self::assertStringContainsString('Истекло', $cabinet['body']);

        // Истёкшее объявление продлевается платной услугой: поднять и выделить его
        // бессмысленно, пока срок публикации не восстановлен.
        self::assertStringContainsString('Продлить', $cabinet['body']);
        self::assertStringNotContainsString('Поднять', $cabinet['body']);
        self::assertStringNotContainsString('Выделить', $cabinet['body']);

        $ordered = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'extend',
        ]);
        self::assertSame(302, $ordered['status']);
        self::assertStringContainsString('notice=promotion_ordered', (string) ($ordered['headers']['location'] ?? ''));

        // До оплаты услуга не работает: срок объявления не меняется. Владелец по прямой
        // ссылке видит своё объявление и истёкшим (предпросмотр), поэтому судим о сроке.
        $stillExpired = fromDb((string) value(
            'SELECT expires_at FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [self::$publishedId]
        ));
        self::assertNotNull($stillExpired);
        self::assertLessThan(nowUtc(), $stillExpired);

        $orderId = (string) value(
            'SELECT id FROM ' . t('commerce_order') . " WHERE status = 'NEW' ORDER BY created_at DESC LIMIT 1"
        );
        self::assertNotSame('', $orderId);

        // Оплату подтверждает админ магазина: после неё услуга применяется к объявлению.
        Kernel::freshCookies();
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $orders = Kernel::get('/commerce/admin/orders');
        self::assertSame(200, $orders['status']);
        $paid = Kernel::post('/commerce/admin/orders', [
            '_token' => $this->token($orders['body']),
            'id' => $orderId,
            'op' => 'paid',
        ]);
        self::assertSame(302, $paid['status']);
        self::assertSame('PAID', (string) value('SELECT status FROM ' . t('commerce_order') . ' WHERE id = ?', [$orderId]));

        $expires = fromDb((string) value(
            'SELECT expires_at FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [self::$publishedId]
        ));
        self::assertNotNull($expires);
        self::assertGreaterThan(nowUtc(), $expires);
        self::assertSame(200, Kernel::get('/classifieds/kofemashina')['status']);
        self::assertStringContainsString('/classifieds/kofemashina', Kernel::get('/sitemap.xml')['body']);

        // Страница услуг продавца показывает заказ.
        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $promotions = Kernel::get('/classifieds/cabinet/promotions');
        self::assertSame(200, $promotions['status']);
        self::assertStringContainsString('Продление', $promotions['body']);
        self::assertStringContainsString('Кофемашина', $promotions['body']);
    }

    public function testКабинетПоказываетЦеныУслугИСписокЗаказов(): void
    {
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');
        self::assertSame(200, $cabinet['status']);

        // Цена услуги — цена товара магазина с фиксированным SKU: кабинет и заказ
        // показывают одну и ту же сумму.
        self::assertStringContainsString('Поднять (300,00 ₽)', $cabinet['body']);
        self::assertStringContainsString('Выделить (800,00 ₽)', $cabinet['body']);
        self::assertStringContainsString('Продлить (200,00 ₽)', $cabinet['body']);

        $ordered = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'bump',
        ]);
        self::assertSame(302, $ordered['status']);
        self::assertStringContainsString('notice=promotion_ordered', (string) ($ordered['headers']['location'] ?? ''));

        $promotions = Kernel::get('/classifieds/cabinet/promotions');
        self::assertSame(200, $promotions['status']);
        self::assertStringContainsString('Поднятие', $promotions['body']);
        self::assertStringContainsString('Кофемашина', $promotions['body']);
        self::assertStringContainsString('Ожидает оплаты', $promotions['body']);

        // Заказ ждёт оплаты: объявление не поднято.
        self::assertNull(classifiedsListingById(self::$publishedId)['boosted_at']);
    }

    public function testКлонированиеСоздаётЧерновикСАтрибутамиБезФотоИТолькоДляВладельца(): void
    {
        $condition = self::addAttribute('condition', 'Состояние', 'select', ['Новое', 'Б/у']);
        classifiedsReplaceListingAttributes(self::$publishedId, [
            $condition => ['value_text' => 'Б/у', 'value_number' => null],
        ]);
        run(
            'UPDATE ' . t('classifieds_listing') . ' SET image = ? WHERE id = ?',
            ['/uploads/classifieds/original.jpg', self::$publishedId]
        );

        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');

        $cloned = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'clone',
        ]);
        self::assertSame(302, $cloned['status']);
        self::assertStringContainsString('notice=listing_cloned', (string) ($cloned['headers']['location'] ?? ''));

        $copy = one(
            'SELECT id, slug, title, status, image, expires_at FROM ' . t('classifieds_listing')
            . ' WHERE title = ? AND owner_id = ?',
            ['Кофемашина (копия)', self::$sellerId]
        );
        self::assertNotNull($copy);
        self::assertNotSame(self::$publishedId, (string) $copy['id']);
        self::assertSame('DRAFT', $copy['status']);
        self::assertNull($copy['image']);
        self::assertNull($copy['expires_at']);
        self::assertSame('Б/у', value(
            'SELECT value_text FROM ' . t('classifieds_listing_attribute') . ' WHERE listing_id = ? AND attribute_id = ?',
            [(string) $copy['id'], $condition]
        ));
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_listing_image') . ' WHERE listing_id = ?',
            [(string) $copy['id']]
        ));
        self::assertSame('/uploads/classifieds/original.jpg', value(
            'SELECT image FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [self::$publishedId]
        ));

        $foreign = Kernel::post('/classifieds/cabinet/' . self::$foreignId . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'clone',
        ]);
        self::assertSame(302, $foreign['status']);
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_listing') . ' WHERE title = ?',
            ['Чужое объявление (копия)']
        ));
    }

    public function testРедакторОтклоняетОбъявлениеСПричинойИПродавецОтправляетСнова(): void
    {
        $id = self::addListing(self::$sellerId, 'na-proverku', 'На проверке', 'PENDING_REVIEW');

        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $queue = Kernel::get('/classifieds/admin/moderation');
        self::assertSame(200, $queue['status']);
        self::assertStringContainsString('На проверке', $queue['body']);

        $rejected = Kernel::post('/classifieds/admin/moderation', [
            '_token' => $this->token($queue['body']),
            'id' => $id,
            'op' => 'reject',
            'note' => 'Фотография не относится к товару.',
        ]);
        self::assertSame(302, $rejected['status']);
        $row = one('SELECT status, moderation_note FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$id]);
        self::assertNotNull($row);
        self::assertSame('REJECTED', $row['status']);
        self::assertSame('Фотография не относится к товару.', $row['moderation_note']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [self::$sellerId, 'moderation']
        ));

        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $cabinet = Kernel::get('/classifieds/cabinet');
        self::assertStringContainsString('Причина отказа: Фотография не относится к товару.', $cabinet['body']);

        $preview = Kernel::get('/classifieds/na-proverku');
        self::assertSame(200, $preview['status']);
        self::assertStringContainsString('Проверка не пройдена', $preview['body']);

        $again = Kernel::post('/classifieds/cabinet/' . $id . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'publish',
        ]);
        self::assertSame(302, $again['status']);
        $againRow = one('SELECT status, moderation_note FROM ' . t('classifieds_listing') . ' WHERE id = ?', [$id]);
        self::assertNotNull($againRow);
        self::assertSame('PENDING_REVIEW', $againRow['status']);
        self::assertNull($againRow['moderation_note']);
    }

    public function testПерепискаПоОбъявлениюДоходитДоПродавцаИОбратноСУведомлениями(): void
    {
        $this->login(self::OTHER_EMAIL, self::OTHER_PASSWORD);
        $listing = Kernel::get('/classifieds/kofemashina');
        self::assertSame(200, $listing['status']);
        self::assertStringContainsString('Написать продавцу', $listing['body']);

        $sent = Kernel::post('/classifieds/kofemashina/message', [
            '_token' => $this->token($listing['body']),
            'body' => 'Здравствуйте! Кофемашина ещё в продаже?',
        ]);
        self::assertSame(302, $sent['status']);
        self::assertStringContainsString(
            '/classifieds/cabinet/messages/' . self::$publishedId,
            (string) ($sent['headers']['location'] ?? '')
        );
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_message')));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [self::$sellerId, 'message']
        ));

        Kernel::freshCookies();
        $this->login(self::SELLER_EMAIL, self::SELLER_PASSWORD);
        $thread = Kernel::get('/classifieds/cabinet/messages/' . self::$publishedId . '?with=' . self::$otherId);
        self::assertSame(200, $thread['status']);
        self::assertStringContainsString('Кофемашина ещё в продаже', $thread['body']);

        $replied = Kernel::post('/classifieds/cabinet/messages/' . self::$publishedId, [
            '_token' => $this->token($thread['body']),
            'with' => self::$otherId,
            'body' => 'Да, в продаже. Можете посмотреть сегодня.',
        ]);
        self::assertSame(302, $replied['status']);
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_message')));
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_message') . ' WHERE recipient_id = ? AND read_at IS NULL',
            [self::$sellerId]
        ));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [self::$otherId, 'message']
        ));

        Kernel::freshCookies();
        $this->login(self::OTHER_EMAIL, self::OTHER_PASSWORD);
        $inbox = Kernel::get('/classifieds/cabinet/messages');
        self::assertSame(200, $inbox['status']);
        self::assertStringContainsString('Кофемашина', $inbox['body']);

        $thread = Kernel::get('/classifieds/cabinet/messages/' . self::$publishedId . '?with=' . self::$sellerId);
        self::assertSame(200, $thread['status']);
        self::assertStringContainsString('Можете посмотреть сегодня', $thread['body']);

        $notifications = Kernel::get('/classifieds/cabinet/notifications');
        self::assertSame(200, $notifications['status']);
        self::assertStringContainsString('Новое сообщение', $notifications['body']);

        $read = Kernel::post('/classifieds/cabinet/notifications', [
            '_token' => $this->token($notifications['body']),
        ]);
        self::assertSame(302, $read['status']);
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND read_at IS NULL',
            [self::$otherId]
        ));
    }

    public function testЖалобаСоздаётсяОдинРазИЗакрываетсяРедактором(): void
    {
        $this->login(self::OTHER_EMAIL, self::OTHER_PASSWORD);
        $form = Kernel::get('/classifieds/kofemashina/report');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Спам или реклама', $form['body']);

        $sent = Kernel::post('/classifieds/kofemashina/report', [
            '_token' => $this->token($form['body']),
            'reason' => 'spam',
            'comment' => 'Одинаковый текст в десятке объявлений.',
        ]);
        self::assertSame(302, $sent['status']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_report')));

        $again = Kernel::post('/classifieds/kofemashina/report', [
            '_token' => $this->token(Kernel::get('/classifieds/kofemashina/report')['body']),
            'reason' => 'fraud',
            'comment' => '',
        ]);
        self::assertSame(302, $again['status']);
        self::assertStringContainsString('notice=report_duplicate', (string) ($again['headers']['location'] ?? ''));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_report')));

        Kernel::freshCookies();
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $reports = Kernel::get('/classifieds/admin/reports');
        self::assertSame(200, $reports['status']);
        self::assertStringContainsString('Одинаковый текст', $reports['body']);

        $reportId = (string) value('SELECT id FROM ' . t('classifieds_report') . " WHERE status = 'NEW'");
        $resolved = Kernel::post('/classifieds/admin/reports', [
            '_token' => $this->token($reports['body']),
            'id' => $reportId,
            'op' => 'resolve',
        ]);
        self::assertSame(302, $resolved['status']);
        self::assertSame('RESOLVED', value('SELECT status FROM ' . t('classifieds_report') . ' WHERE id = ?', [$reportId]));
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [self::$otherId, 'report']
        ));
    }

    public function testЭкранВебхуковТолькоДляРедактора(): void
    {
        $guest = Kernel::get('/classifieds/admin/webhooks');
        self::assertSame(302, $guest['status']);
        self::assertStringContainsString(
            '/login?next=%2Fclassifieds%2Fadmin%2Fwebhooks',
            (string) ($guest['headers']['location'] ?? '')
        );

        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $page = Kernel::get('/classifieds/admin/webhooks');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вебхуки', $page['body']);
        self::assertStringContainsString('name="url"', $page['body']);
        self::assertStringContainsString('name="event"', $page['body']);
        self::assertStringContainsString('noindex', $page['body']);
    }

    public function testВебхукСоздаётсяПравитсяИУдаляется(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $page = Kernel::get('/classifieds/admin/webhooks');

        $created = Kernel::post('/classifieds/admin/webhooks', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'event' => 'listing.published',
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-xyz',
            'active' => '1',
        ]);
        self::assertSame(302, $created['status']);

        $id = (string) value('SELECT id FROM ' . t('classifieds_webhook'));
        self::assertNotSame('', $id, 'Приёмник обязан появиться в таблице после сохранения');
        self::assertSame('listing.published', (string) value('SELECT event FROM ' . t('classifieds_webhook') . ' WHERE id = ?', [$id]));

        $list = Kernel::get('/classifieds/admin/webhooks');
        self::assertStringContainsString('https://приёмник.рф/board', $list['body']);
        self::assertStringContainsString('Объявление опубликовано', $list['body']);

        $edit = Kernel::get('/classifieds/admin/webhooks/' . rawurlencode($id));
        self::assertSame(200, $edit['status']);
        self::assertStringContainsString('https://приёмник.рф/board', $edit['body']);

        $saved = Kernel::post('/classifieds/admin/webhooks/' . rawurlencode($id), [
            '_token' => $this->token($edit['body']),
            'op' => 'save',
            'event' => 'message.received',
            'url' => 'https://приёмник.рф/сообщения',
            'secret' => 'secret-20-symbols-xyz',
        ]);
        self::assertSame(302, $saved['status']);
        self::assertSame('message.received', (string) value('SELECT event FROM ' . t('classifieds_webhook') . ' WHERE id = ?', [$id]));

        $deleted = Kernel::post('/classifieds/admin/webhooks/' . rawurlencode($id), [
            '_token' => $this->token($edit['body']),
            'op' => 'delete',
        ]);
        self::assertSame(302, $deleted['status']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_webhook')));
    }

    public function testОтказСекретаЧитаетсяНаЭкранеВебхуков(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);
        $page = Kernel::get('/classifieds/admin/webhooks');

        $refused = Kernel::post('/classifieds/admin/webhooks', [
            '_token' => $this->token($page['body']),
            'op' => 'create',
            'event' => 'listing.published',
            'url' => 'https://приёмник.рф/board',
            'secret' => 'короткий',
        ]);
        self::assertSame(302, $refused['status']);

        $after = Kernel::get('/classifieds/admin/webhooks');
        self::assertStringContainsString('Секрет не короче', $after['body']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_webhook')));
    }
}
