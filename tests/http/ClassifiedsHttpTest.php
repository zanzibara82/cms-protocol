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
        foreach (['classifieds_listing', 'classifieds_category', 'classifieds_registration_attempt'] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'classifieds'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'classifieds'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
        run('DELETE FROM ' . t('classifieds_registration_attempt'));
        run('DELETE FROM ' . t('login_attempt'));
        // Объявления, созданные прошлым тестом, не должны влиять на выдачу следующего.
        run('DELETE FROM ' . t('classifieds_listing') . ' WHERE id NOT IN (?, ?, ?)', [self::$publishedId, self::$draftId, self::$foreignId]);
        run('UPDATE ' . t('classifieds_listing') . " SET status = 'PUBLISHED', title = 'Кофемашина' WHERE id = ?", [self::$publishedId]);
        run('UPDATE ' . t('classifieds_listing') . " SET status = 'DRAFT', title = 'Черновик продавца' WHERE id IN (?, ?)", [self::$draftId, self::$foreignId]);
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
}
