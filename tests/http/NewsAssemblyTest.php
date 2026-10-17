<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Приёмка целиком: установка экранами, лента после публикации, закрытая админка и карта сайтов
// проверяются в одном процессе на собранном стенде. Поодиночке эти проверки есть в задачах 8, 9,
// 16, 17 и 19; здесь они складываются в критерии §12.1–§12.5 и §12.9.
final class NewsAssemblyTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'длинный-тестовый-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        Kernel::post('/login', [
            '_token' => $matches[1] ?? '',
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    public function testУстановкаИдётСоЭкранаПриложения(): void
    {
        $this->login();
        $page = Kernel::get('/admin/apps');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Новости', $page['body']);

        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);
        $response = Kernel::post('/admin/apps', [
            '_token' => $matches[1] ?? '',
            'op' => 'install',
            'app' => 'news',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=installed', (string) $response['headers']['location']);

        $installed = appInstallation('news');

        self::assertNotNull($installed);
        self::assertSame('1.1.0', (string) $installed['version']);
        self::assertSame(3, (int) value('SELECT COUNT(*) FROM ' . t('app_migration') . ' WHERE app = ?', ['news']));
        self::assertSame(3, (int) value('SELECT COUNT(*) FROM ' . t('news_category')));
    }

    public function testЧужогоРазделаВСборкеНет(): void
    {
        // Этот тест идёт уже после установки, поэтому проверяет не «неустановленный раздел», а
        // другое: несуществующего приложения в сборке нет, и его адрес не открывается.
        self::assertSame(404, Kernel::get('/nalog/krasivo')['status']);
    }

    public function testПослеУстановкиЛентаОткрывается(): void
    {
        $this->login();
        $page = Kernel::get('/news');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('пока нет материалов', $page['body']);
    }

    public function testПубликацияПоказываетМатериалВЧетырёхМестах(): void
    {
        // Критерий §12.9: лента, страница рубрики, страница тега и карта сайтов.
        $this->login();
        $id = $this->createItemViaForm();

        $published = Kernel::post('/news/admin/items', $this->fields('publish', $id));
        self::assertSame(302, $published['status']);
        self::assertStringContainsString(
            'notice=published',
            (string) $published['headers']['location'],
            'Кнопка списка выпускает материал и сообщает об этом тем же ключом'
        );

        self::assertStringContainsString('Осенний бал', Kernel::get('/news')['body']);
        self::assertStringContainsString('Осенний бал', Kernel::get('/news/c/zhizn-shkoly')['body']);
        self::assertStringContainsString('Осенний бал', Kernel::get('/news/t/vypusknik')['body']);

        $sitemap = Kernel::get('/sitemap.xml')['body'];
        // Тот же порядок, что в шаге карты сайтов выше: `sitemapXml()` печатает `<loc>` абсолютным
        // адресом через `absoluteUrl()`, поэтому проверка берёт полный адрес с `SITE_URL`.
        self::assertStringContainsString('<loc>http://localhost:8080/news/osenniy-bal</loc>', $sitemap);
    }

    public function testАдминкаЗакрытаИндексироватьНечего(): void
    {
        // Критерии §12.5 в одном ответе: редирект анонима, noindex и robots.txt.
        $anonymous = Kernel::get('/news/admin/categories');
        self::assertSame(302, $anonymous['status']);
        self::assertStringContainsString('/login?next=', (string) $anonymous['headers']['location']);

        $this->login();
        foreach (['/news/admin', '/news/admin/items', '/news/admin/categories', '/news/admin/tags', '/news/admin/blocks'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status'], $path);
            self::assertStringContainsString('noindex, nofollow', $page['body'], $path);
        }

        $robots = Kernel::get('/robots.txt')['body'];
        self::assertStringContainsString('Disallow: /news/admin', $robots);
        self::assertStringNotContainsString('/news/admin', Kernel::get('/sitemap.xml')['body']);
    }

    public function testФормаРаботаетБезJavaScript(): void
    {
        // §12.17 в миниатюре: добавление рубрики — это POST формы без единого скрипта на странице.
        $this->login();
        $body = Kernel::get(NEWS_ADMIN_CATEGORIES_PATH)['body'];

        self::assertStringNotContainsString('<script', $body, 'Экран справочника не грузит скриптов');

        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);
        $response = Kernel::post(NEWS_ADMIN_CATEGORIES_PATH, [
            '_token' => $matches[1] ?? '',
            'op' => 'create',
            'title' => 'Без скриптов',
            'description' => '',
        ]);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=created', (string) $response['headers']['location']);
    }

    // ── Помощники ─────────────────────────────────────────

    /** @return array<string, string> */
    private function fields(string $op, string $id): array
    {
        $page = Kernel::get('/news/admin/items');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        return [
            '_token' => $matches[1] ?? '',
            'op' => $op,
            'id' => $id,
            'back' => '/news/admin/items',
        ];
    }

    private function createItemViaForm(): string
    {
        $page = Kernel::get('/news/admin/items/new');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);

        // Имена полей — NEWS_FORM_FIELDS (задача 12) плюс намерение кнопки и адрес возврата: их
        // читает newsItemSaveAction() (задача 18). Поле slug пустое — адрес берется из заголовка,
        // так форма и ведёт себя на стенде.
        $response = Kernel::post('/news/admin/items/new', [
            '_token' => $matches[1] ?? '',
            'back' => '/news/admin/items',
            'intent' => 'draft',
            'title' => 'Осенний бал',
            'slug' => '',
            'lead' => 'Приглашаем на бал.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => 'Танцы и чай.',
            'tags' => 'выпускник',
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => '',
            'pinned' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'noindex' => '',
        ]);

        self::assertSame(302, $response['status'], 'Форма обязана принять черновик: ' . $response['body']);
        self::assertSame(
            '/news/admin/items?notice=created',
            (string) $response['headers']['location'],
            'После сохранения — возврат в список с ключом уведомления (PRG)'
        );

        // Адрес ответа — список, идентификатора в нём нет, поэтому строку берут из базы по адресу
        // материала. Так же её находит экран правки, когда человек переходит из списка.
        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['osenniy-bal']);
        self::assertNotSame('', $id, 'Черновик «Осенний бал» появился в базе');

        return $id;
    }
}
