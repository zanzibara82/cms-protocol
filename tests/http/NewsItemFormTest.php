<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use CURLFile;
use PHPUnit\Framework\TestCase;

// Форма материала проверяется настоящим запросом: без JS браузер отправляет поля как есть, и
// только в ответе сервера видно, вернулся ли набранный текст, ушёл ли человек в список с плашкой
// и лёг ли файл на диск тем именем, которое решил сервер.
final class NewsItemFormTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor-form@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';
    private const EDITOR = ['id' => 'redaktor-form-1', 'email' => 'redaktor-form@shkola.ru'];

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        installApp('news');

        // Адрес заготовленной строки совпадает с тем, что даёт `slugify()` по таблице `NEWS_TRANSLIT`
        // (`й` → `y`), и только поэтому проверка «занятый адрес» ниже остаётся проверкой: форма
        // транслитерирует вписанный кириллицей адрес и обязана увидеть занятой адрес.
        $bal = createItem(self::data('Осенний бал', 'osenniy-bal'), self::EDITOR);
        $draft = createItem(self::data('Черновик экскурсии', 'chernovik-ekskursii', [
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), self::EDITOR);

        self::assertTrue($bal['ok'] && $draft['ok']);

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();

        // Загрузки проверяются настоящие, файл остаётся на диске: подчищаем только картинки,
        // `.gitkeep` обязан выжить.
        foreach (self::uploadFiles() as $file) {
            unlink($file);
        }
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function data(string $title, string $slug, array $overrides = []): array
    {
        return array_merge([
            'title' => $title,
            'slug' => $slug,
            'lead' => 'Короткий лид.',
            'cover' => null,
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => [['title' => '', 'text' => 'Текст абзаца.']],
            'tags' => [],
            'status' => NEWS_STATUS['PUBLISHED'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }

    /** @return list<string> */
    private static function uploadFiles(): array
    {
        $out = [];

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            foreach (glob(dirname(__DIR__, 2) . '/public/uploads/news/*.' . $ext) ?: [] as $file) {
                $out[] = $file;
            }
        }

        return $out;
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => $this->token($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    private function token(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $matches);

        return $matches[1] ?? '';
    }

    /**
     * Поля создания: токен берётся из формы нового материала. `cover_file` — CURLFile, поэтому
     * аннотация шире строк: её принимает `Kernel::post`.
     *
     * @param array<string, string|\CURLFile> $overrides
     *
     * @return array<string, string|\CURLFile>
     */
    private function fields(array $overrides = []): array
    {
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        $fields = [
            '_token' => $this->token($page['body']),
            'title' => 'Открытая запись',
            'slug' => 'otkrytaya-zapis',
            'lead' => 'Как попасть в класс.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => "## Кому интересно\nПриходите в пятницу.",
            'tags' => 'запись, открытый урок',
            'status' => '',
            'publishedAt' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'back' => NEWS_ADMIN_ITEMS_PATH,
            'intent' => 'draft',
        ];

        return array_merge($fields, $overrides);
    }

    /**
     * @param array<string, string|\CURLFile> $overrides
     * @return array<string, string|\CURLFile>
     */
    private function editFields(string $id, array $overrides = []): array
    {
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id));

        $fields = [
            '_token' => $this->token($page['body']),
            'title' => 'Открытая запись',
            'slug' => 'otkrytaya-zapis',
            'lead' => 'Как попасть в класс.',
            'cover' => '',
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'body' => "## Кому интересно\nПриходите в пятницу.",
            'tags' => 'запись',
            'status' => '',
            'publishedAt' => '',
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'back' => NEWS_ADMIN_ITEMS_PATH,
            'intent' => 'draft',
        ];

        return array_merge($fields, $overrides);
    }

    // ── Право на экран ────────────────────────────────────

    public function testАнонимНаФормуУходитНаВход(): void
    {
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('/login?next=%2Fnews%2Fadmin%2Fitems%2Fnew', $page['headers']['location']);
    }

    public function testАнонимСPostНаФормуПолучаетЗапрет(): void
    {
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, ['title' => 'что угодно']);

        self::assertSame(403, $response['status']);
    }

    public function testФормаБезТокенаОтвергнута(): void
    {
        $this->login();
        $fields = $this->fields();
        unset($fields['_token']);
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $fields);

        self::assertSame(403, $response['status']);
    }

    // ── Разметка экрана ───────────────────────────────────

    public function testНовыйМатериалОткрываетсяПустымФормой(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Новый материал — админка</title>', $page['body']);
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        self::assertStringContainsString('enctype="multipart/form-data"', $page['body'], 'Без enctype файл не дойдёт');
        self::assertStringContainsString('name="intent" value="draft"', $page['body']);
        self::assertStringContainsString('name="intent" value="publish"', $page['body']);
        self::assertStringNotContainsString('name="id"', $page['body'], 'Идентификатор в форме не нужен: решает адрес');

        foreach (NEWS_FORM_FIELDS as $field) {
            self::assertStringContainsString('name="' . $field . '"', $page['body'], 'Нет поля ' . $field);
        }
    }

    public function testЧерновикИдётПервымВразметкеАПечатьПервойНаЭкране(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        $draft = strpos($page['body'], 'value="draft"');
        $publish = strpos($page['body'], 'value="publish"');

        self::assertIsInt($draft);
        self::assertIsInt($publish);
        self::assertLessThan($publish, $draft, 'Enter обязан сохранять черновик, а не печатать');
        self::assertMatchesRegularExpression(
            '/class="[^"]*order-first"[^>]*value="publish"/',
            $page['body'],
            'Кнопка выпуска стоит первой визуально'
        );
    }

    public function testПодсказкаПроФорматКартинкиСтоитНаДвухПолях(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertSame(2, substr_count($page['body'], 'Картинка горизонтальная, 1200×630.'), '§8.5: у обложки и у OG-картинки');
    }

    public function testВкладкаМатериалыОстаётсяОтмеченной(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ITEM_NEW_PATH);

        self::assertStringContainsString('aria-label="Экраны раздела «Новости»"', $page['body']);
        self::assertStringContainsString('href="/news/admin/items" aria-current="page"', $page['body']);
    }

    public function testФормаПравкиВозвращаетСохранённое(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Поступление в 5 класс', 'postuplenie-v-5-klass'), self::EDITOR)['id'];
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id));

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Правка материала — админка</title>', $page['body']);
        self::assertStringContainsString('value="Поступление в 5 класс"', $page['body']);
        self::assertStringContainsString('Поступление в 5 класс', $page['body']);
        self::assertStringContainsString('опубликована', $page['body'], 'Статус обязан быть назван словами');
        self::assertStringContainsString('href="/news/postuplenie-v-5-klass"', $page['body'], 'Ссылка на карточку с формы');
    }

    public function testЧужойАдресФормыУводитВСписокСОтказом(): void
    {
        $this->login();
        $page = Kernel::get(NEWS_ADMIN_ITEMS_PATH . '/net-takogo-id');

        self::assertSame(302, $page['status']);
        self::assertStringContainsString('notice=migration-failed', $page['headers']['location']);
        self::assertStringNotContainsString(
            'error=',
            $page['headers']['location'],
            'Причина отказа не уезжает в адрес: её печатает плашка из flash, а адресная строка — нет'
        );
    }

    // ── Создание ──────────────────────────────────────────

    public function testПустаяФормаОтказываетсяИВозвращаетНабранное(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'ок',
            'slug' => '',
            'categoryId' => '',
            'lead' => 'Что я успел написать перед тем, как нажать кнопку',
        ]));

        self::assertSame(200, $response['status'], 'Отказ формы остаётся на том же экране');
        self::assertStringContainsString('role="alert"', $response['body']);
        self::assertStringContainsString('Заголовок короче ' . fieldLimit(NEWS_FIELDS, 'title', 'min') . ' символов', $response['body']);
        self::assertStringContainsString('Выберите рубрику', $response['body']);
        self::assertStringContainsString('value="ок"', $response['body'], '§8.3: набранный текст возвращается в форму');
        self::assertStringContainsString('Что я успел написать', $response['body']);
    }

    public function testЧерновикСохраняетсяБезТекстаИНеПопадаетВЛенту(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Заметка без текста',
            'slug' => 'zametka-bez-teksta',
            'body' => '',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=created', $response['headers']['location']);

        $feed = Kernel::get('/news');
        self::assertStringNotContainsString('Заметка без текста', $feed['body']);
        self::assertSame(404, Kernel::get('/news/zametka-bez-teksta')['status']);
    }

    public function testПечатьБезТекстаОтказывается(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Недописанный материал',
            'slug' => 'nedopisannyj-material',
            'body' => '',
            'intent' => 'publish',
        ]));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString(NEWS_PUBLISH_NEEDS_TEXT, $response['body']);
        self::assertStringContainsString('value="Недописанный материал"', $response['body']);
        self::assertNull(value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['nedopisannyj-material']));
    }

    public function testЗанятыйАдресОтказываетсяНаМесте(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Второй осенний бал',
            'slug' => 'Осенний бал',
        ]));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('role="alert"', $response['body']);
        self::assertStringContainsString('По такому адресу уже есть материал', $response['body'], 'Отказ обязан называть причину');
        self::assertStringContainsString('value="Второй осенний бал"', $response['body'], 'Набранное обязано остаться');
    }

    public function testСлужебныйАдресРазделаНеЗанимается(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields(['slug' => 'admin']));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('занят служебной страницей раздела', $response['body']);
    }

    public function testПечатьДелаетМатериалВидимым(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Выпускной 2026',
            'slug' => 'vypusknoj-2026',
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=published', $response['headers']['location']);
        self::assertStringContainsString('Выпускной 2026', Kernel::get('/news')['body']);
        self::assertStringContainsString('Выпускной 2026', Kernel::get('/news/vypusknoj-2026')['body']);
    }

    public function testСрокВБудущемЭтоРасписание(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Ёлка через неделю',
            'slug' => 'elka-cherez-nedelyu',
            'publishedAt' => date('Y-m-d\TH:i', time() + 604800),
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=scheduled', $response['headers']['location']);
        self::assertStringNotContainsString('Ёлка через неделю', Kernel::get('/news')['body']);
    }

    public function testОбратныйАдресСохраняетсяПослеЗаписи(): void
    {
        $this->login();
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Родительское собрание',
            'slug' => 'roditelskoe-sobranie',
            'back' => NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT',
        ]));

        self::assertSame(302, $response['status']);
        self::assertSame(
            NEWS_ADMIN_ITEMS_PATH . '?status=DRAFT&notice=created',
            $response['headers']['location'],
            'Действие обязано вернуть в ту же вкладку и на ту же страницу'
        );
    }

    // ── Правка ────────────────────────────────────────────

    public function testПравкаЧерновикаВозвращаетПлашкуОбОбновлении(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Правимый черновик', 'pravimyj-chernovik', [
            'status' => NEWS_STATUS['DRAFT'],
            'body' => [],
        ]), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Правимый черновик 2',
            'slug' => 'pravimyj-chernovik',
            'status' => NEWS_STATUS['DRAFT'],
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=updated', $response['headers']['location']);
    }

    public function testСменаАдресаЗапоминаетПрежний(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Старый заголовок', 'staryj-zagolovok'), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Старый заголовок',
            'slug' => 'novyj-zagolovok',
            'status' => NEWS_STATUS['PUBLISHED'],
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=slugChanged', $response['headers']['location']);

        $old = Kernel::get('/news/staryj-zagolovok');
        self::assertSame(301, $old['status']);
        self::assertStringContainsString('/news/novyj-zagolovok', $old['headers']['location']);
    }

    public function testПравкаУдалённойСтрокиУводитВСписок(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Удалённый материал', 'udalennyj-material'), self::EDITOR)['id'];
        run('DELETE FROM ' . t('news_item') . ' WHERE id = ?', [$id]);

        // Поля берутся из формы создания: форма правки удалённой строки уже не отдаст токен.
        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->fields());

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('notice=migration-failed', $response['headers']['location']);
        self::assertStringNotContainsString(
            'error=',
            $response['headers']['location'],
            'Причина отказа не уезжает в адрес: её печатает плашка из flash, а адресная строка — нет'
        );
    }

    // ── Загрузка обложки ──────────────────────────────────

    public function testКартинкаЛожитсяВКаталогЗагрузокИОтдаётсяПоАдресу(): void
    {
        $this->login();
        $png = dirname(__DIR__, 2) . '/public/assets/og-default.png';

        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Бал с обложкой',
            'slug' => 'bal-s-oblozhkoj',
            'cover_file' => new CURLFile($png, 'image/png', 'bal.png'),
            'intent' => 'publish',
        ]));

        self::assertSame(302, $response['status']);
        parse_str((string) parse_url($response['headers']['location'], PHP_URL_QUERY), $query);

        $id = (string) value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['bal-s-oblozhkoj']);
        $cover = (string) itemForForm($id)['cover'];

        self::assertMatchesRegularExpression('#^/uploads/news/[0-9a-z]{26}\.png$#', $cover);
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . $cover);
        self::assertSame(200, Kernel::get($cover)['status']);
        self::assertNotSame('', (string) $query['notice']);
    }

    public function testТекстПодВидомКартинкиНеСохраняется(): void
    {
        $this->login();
        $fake = tempnam(sys_get_temp_dir(), 'cms-fake-') . '.php.png';
        file_put_contents($fake, "<?php echo 'привет';");

        $before = count(self::uploadFiles());
        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Мнимая картинка',
            'slug' => 'mimaya-kartinka',
            'cover_file' => new CURLFile($fake, 'image/png', 'podrobnyj.php.png'),
        ]));
        unlink($fake);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('jpg, png или webp', $response['body']);
        self::assertCount($before, self::uploadFiles(), 'Отказ не оставляет файла на диске');
    }

    public function testОтказСлояДанныхУбираетУжеЗалитуюКартинку(): void
    {
        $this->login();
        $before = count(self::uploadFiles());

        $response = Kernel::post(NEWS_ITEM_NEW_PATH, $this->fields([
            'title' => 'Обложка без рубрики',
            'slug' => 'oblozhka-bez-rubriki',
            'categoryId' => 'net-takoj-rubriki',
            'cover_file' => new CURLFile(
                dirname(__DIR__, 2) . '/public/assets/og-default.png',
                'image/png',
                'izbyshok.png'
            ),
        ]));

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Рубрики, в которую вы кладёте материал, уже нет', $response['body']);
        self::assertCount($before, self::uploadFiles(), 'Отказ слоя данных не оставляет картинки на диске');
        self::assertNull(value('SELECT id FROM ' . t('news_item') . ' WHERE slug = ?', ['oblozhka-bez-rubriki']));
    }

    public function testНоваяКартинкаПеретираетПутьОбложки(): void
    {
        $this->login();
        $id = (string) createItem(self::data('Материал с обложкой', 'material-s-oblozhkoj', [
            'cover' => '/uploads/news/staroe-imenya-net.png',
        ]), self::EDITOR)['id'];

        $response = Kernel::post(NEWS_ADMIN_ITEMS_PATH . '/' . rawurlencode($id), $this->editFields($id, [
            'title' => 'Материал с обложкой',
            'slug' => 'material-s-oblozhkoj',
            'tags' => 'бал, обложка',
            'cover_file' => new CURLFile(dirname(__DIR__, 2) . '/public/assets/og-default.png', 'image/png', 'novoe.png'),
        ]));

        self::assertSame(302, $response['status']);
        self::assertMatchesRegularExpression(
            '#^/uploads/news/[0-9a-z]{26}\.png$#',
            (string) itemForForm($id)['cover']
        );
    }
}
