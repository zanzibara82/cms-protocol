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
        foreach (['classifieds_favorite', 'classifieds_seller_profile', 'classifieds_listing_attribute', 'classifieds_listing_image', 'classifieds_listing', 'classifieds_attribute', 'classifieds_category', 'classifieds_registration_attempt'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'classifieds'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'classifieds'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        run('DELETE FROM ' . t('classifieds_registration_attempt'));
        run('DELETE FROM ' . t('classifieds_favorite'));
        run('DELETE FROM ' . t('classifieds_seller_profile'));
        run('DELETE FROM ' . t('classifieds_listing_attribute'));
        run('DELETE FROM ' . t('classifieds_listing_image'));
        run('DELETE FROM ' . t('classifieds_attribute'));
        run('DELETE FROM ' . t('login_attempt'));
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
        self::assertSame('PUBLISHED', $row['status']);

        $public = Kernel::get('/classifieds/stol-pismennyy');
        self::assertSame(200, $public['status']);
        self::assertStringContainsString('Стол письменный', $public['body']);
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

    public function testИстёкшееОбъявлениеСкрываетсяИПродлевается(): void
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

        $extended = Kernel::post('/classifieds/cabinet/' . self::$publishedId . '/action', [
            '_token' => $this->token($cabinet['body']),
            'op' => 'extend',
        ]);
        self::assertSame(302, $extended['status']);
        self::assertStringContainsString('notice=listing_extended', (string) ($extended['headers']['location'] ?? ''));

        $expires = fromDb((string) value(
            'SELECT expires_at FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [self::$publishedId]
        ));
        self::assertNotNull($expires);
        self::assertGreaterThan(nowUtc(), $expires);
        self::assertSame(200, Kernel::get('/classifieds/kofemashina')['status']);
        self::assertStringContainsString('/classifieds/kofemashina', Kernel::get('/sitemap.xml')['body']);
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
}
