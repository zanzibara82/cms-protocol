<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Конструктор страниц проверяется настоящим запросом: гость уходит на вход или получает 403,
 * редактор входит и исполняет действия одним POST-обработчиком с op (механика ForbiddenTest).
 * Каждое действие — PRG-редирект с ключом уведомления из словаря ядра.
 */
final class PagesAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

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

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);
    }

    /** Токен с экрана: скрытое поле есть в каждой форме, берём первое. */
    private function token(string $screen): string
    {
        return Kernel::tokenFrom(Kernel::get($screen)['body']);
    }

    public function testГостюКонструкторНедоступен(): void
    {
        $page = Kernel::get('/admin/pages', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход, как на остальные приватные адреса');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fpages', $page['headers']['location']);

        // POST анонима не редиректится: редирект потерял бы тело запроса.
        $denied = Kernel::post('/admin/pages', ['op' => 'create-page', 'title' => 'Взлом', 'slug' => 'vzlom']);

        self::assertSame(403, $denied['status']);
        self::assertStringContainsString('Раздел открыт только редактору и администратору.', $denied['body']);
    }

    public function testСписокОткрытРедакторуИВедётВРедакторы(): void
    {
        $page = createPage([
            'title' => 'О нас',
            'slug' => 'o-nas',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $this->login();

        $list = Kernel::get('/admin/pages');

        self::assertSame(200, $list['status']);
        self::assertStringContainsString('О нас', $list['body'], 'созданная страница в списке');
        self::assertStringContainsString('href="/admin/pages/home"', $list['body'], 'блоки главной редактируются отдельным экраном');
        self::assertStringContainsString('href="/admin/pages/' . (string) $page['id'] . '"', $list['body'], 'страница ведёт в свой редактор');
        self::assertStringContainsString('name="op" value="create-page"', $list['body'], 'создание — форма с op, а не отдельный адрес');
    }

    public function testСозданиеСтраницыПереноситВРедакторСПлашкой(): void
    {
        $this->login();
        $token = $this->token('/admin/pages');

        $created = Kernel::post('/admin/pages', [
            '_token' => $token,
            'op' => 'create-page',
            'title' => 'Цены',
            'slug' => 'ceny',
            'description' => 'Стоимость занятий',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);

        self::assertSame(302, $created['status']);
        self::assertMatchesRegularExpression(
            // id — 26 символов Crockford base32 (без i, l, o, u) — core/id.php.
            '~^/admin/pages/[0-9a-hjkmnp-tv-z]{26}\?notice=page_created$~',
            $created['headers']['location'],
            'успех — PRG в редактор с ключом из словаря'
        );

        $list = Kernel::get('/admin/pages');

        self::assertStringContainsString('Цены', $list['body'], 'страница появилась в списке');

        $editor = Kernel::get($created['headers']['location']);

        self::assertSame(200, $editor['status']);
        self::assertStringContainsString('value="Цены"', $editor['body'], 'редактор открывает поля формы заполненными');
        self::assertStringContainsString('Страница создана.', $editor['body'], 'плашка читается из словаря ядра по ключу');
    }

    public function testПравкаСтраницыПишетСтарыйАдрес(): void
    {
        $page = createPage([
            'title' => 'Оплата',
            'slug' => 'oplata',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $saved = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'save-page',
            'title' => 'Оплата и рассрочка',
            'slug' => 'oplata-rassrochka',
            'description' => 'Оплата занятий',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertStringContainsString('?notice=page_saved', $saved['headers']['location']);

        $old = Kernel::get('/oplata');

        self::assertSame(301, $old['status'], 'прежний адрес продолжает работать после правки');
        self::assertSame('/oplata-rassrochka', $old['headers']['location']);
    }

    public function testСекцияГлавнойДобавляетсяВПорядок(): void
    {
        $this->login();
        $token = $this->token('/admin/pages/home');

        $added = Kernel::post('/admin/pages/home', [
            '_token' => $token,
            'op' => 'create-section',
            'kind' => 'prose',
            'anchor' => 'glavnaya-polosa',
            'badge' => '',
            'title' => 'Главная полоса',
            'lead_text' => 'Первый блок главной.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'visible' => '1',
        ]);

        self::assertSame(302, $added['status']);
        self::assertStringContainsString('?notice=section_created', $added['headers']['location']);

        $home = Kernel::get('/');

        self::assertStringContainsString('Главная полоса', $home['body'], 'секция главной печатается на «/»');
        self::assertStringContainsString('Первый блок главной.', $home['body']);
    }

    public function testДействияСекциейИсполняются(): void
    {
        $first = createSection(PAGES_HOME_ID, [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Первая секция',
            'lead_text' => 'Текст первой.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $second = createSection(PAGES_HOME_ID, [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Вторая секция',
            'lead_text' => 'Текст второй.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/home');

        // Перестановка: вторая уходит вверх, порядок на «/» меняется.
        $moved = Kernel::post('/admin/pages/home', [
            '_token' => $token,
            'op' => 'move-section',
            'section' => (string) $second['id'],
            'dir' => 'up',
        ]);

        self::assertSame(302, $moved['status']);
        self::assertStringContainsString('?notice=section_moved', $moved['headers']['location']);

        $home = Kernel::get('/');

        self::assertTrue(
            mb_strpos((string) $home['body'], 'Вторая секция') < mb_strpos((string) $home['body'], 'Первая секция'),
            'после перестановки вторая секция печатается раньше первой'
        );

        // Скрытие: секция остаётся в конструкторе, но уходит с публичной страницы.
        $hidden = Kernel::post('/admin/pages/home', [
            '_token' => $token,
            'op' => 'toggle-section',
            'section' => (string) $first['id'],
            'visible' => '0',
        ]);

        self::assertSame(302, $hidden['status']);
        self::assertStringContainsString('?notice=section_saved', $hidden['headers']['location']);

        $home = Kernel::get('/');

        self::assertStringNotContainsString('Первая секция', $home['body'], 'скрытая секция не печатается гостю');

        $screen = Kernel::get('/admin/pages/home');

        self::assertStringContainsString('Первая секция', $screen['body'], 'конструктор видит и скрытые секции');

        // Удаление: строка уходит целиком.
        $deleted = Kernel::post('/admin/pages/home', [
            '_token' => $token,
            'op' => 'delete-section',
            'section' => (string) $second['id'],
        ]);

        self::assertSame(302, $deleted['status']);
        self::assertStringContainsString('?notice=section_deleted', $deleted['headers']['location']);

        $home = Kernel::get('/');

        self::assertStringNotContainsString('Вторая секция', $home['body'], 'удалённая секция ушла с публичной страницы');
    }

    public function testСохранениеСекцииПечатаетНовыйТекст(): void
    {
        $page = createPage([
            'title' => 'Правим',
            'slug' => 'pravim',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $section = createSection((string) $page['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Старое название',
            'lead_text' => 'Старый текст.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $saved = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'save-section',
            'section' => (string) $section['id'],
            'kind' => 'prose',
            'anchor' => 'novoe-nazvanie',
            'badge' => 'Новое',
            'title' => 'Новое название',
            'lead_text' => 'Новый текст.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'visible' => '1',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertStringContainsString('?notice=section_saved', $saved['headers']['location']);

        $public = Kernel::get('/pravim');

        self::assertStringContainsString('Новое название', $public['body'], 'новый заголовок печатается на странице');
        self::assertStringContainsString('Новый текст.', $public['body']);
        self::assertStringContainsString('id="novoe-nazvanie"', $public['body'], 'якорь секции печатается');
    }

    public function testУдалениеСтраницыУноситАдрес(): void
    {
        $page = createPage([
            'title' => 'Ушла',
            'slug' => 'ushla',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $page['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Секция ушедшей',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $deleted = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'delete-page',
        ]);

        self::assertSame(302, $deleted['status']);
        self::assertSame('/admin/pages?notice=page_deleted', $deleted['headers']['location']);

        self::assertSame(404, Kernel::get('/ushla')['status'], 'удалённая страница отвечает как несуществующая');

        $list = Kernel::get('/admin/pages');

        self::assertStringNotContainsString('Ушла', $list['body'], 'удалённая страница ушла из списка');
    }

    public function testБлокиСворачиваютсяИПринимаютФайл(): void
    {
        $page = createPage([
            'title' => 'Сверка блоков',
            'slug' => 'sverka-blokov',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $page['id'], [
            'kind' => 'hero',
            'anchor' => '',
            'badge' => '',
            'title' => 'Сворачиваемый блок',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $screen = Kernel::get('/admin/pages/' . (string) $page['id']);

        self::assertSame(200, $screen['status']);
        self::assertMatchesRegularExpression(
            '#<details class="rounded-lg border border-ink/10 bg-white" data-section-id="[0-9a-hjkmnp-tv-z]{26}" open>#',
            $screen['body'],
            'блок — сворачиваемая карточка с идентификатором для сохранения состояния'
        );
        self::assertStringContainsString('class="section-summary', $screen['body'], 'заголовок карточки — ручка сворачивания');
        self::assertStringContainsString('class="section-chevron', $screen['body'], 'у карточки есть стрелка');
        self::assertStringContainsString('enctype="multipart/form-data"', $screen['body'], 'без enctype файл не дойдёт');
        self::assertStringContainsString('name="image_file"', $screen['body'], 'поле выбора картинки');
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $screen['body'], 'формат файла ограничен');
        self::assertStringContainsString('data-section-form', $screen['body'], 'форма секции участвует в общем сохранении');
        self::assertStringContainsString('data-save-button', $screen['body'], 'плавающая кнопка сохранения');
        self::assertStringContainsString('src="/assets/js/admin-sections.js"', $screen['body'], 'скрипт подключён внешним файлом');
    }

    public function testУКарточекНетОбщихПолейПримечанияИКартинки(): void
    {
        $page = createPage([
            'title' => 'Карточки без общих полей',
            'slug' => 'kartochki-bez-obshchikh-poley',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        createSection((string) $page['id'], [
            'kind' => 'cards',
            'anchor' => '',
            'badge' => '',
            'title' => 'Карточки',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        createSection((string) $page['id'], [
            'kind' => 'hero',
            'anchor' => '',
            'badge' => '',
            'title' => 'Первый экран',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $screen = Kernel::get('/admin/pages/' . (string) $page['id']);
        $body = $screen['body'];

        self::assertSame(200, $screen['status']);
        self::assertSame(1, substr_count($body, 'name="note"'), 'общее примечание осталось только у блока с картинкой');
        self::assertSame(1, substr_count($body, 'name="image"'), 'общая картинка осталась только у блока с картинкой');
        self::assertSame(1, substr_count($body, 'name="image_alt"'), 'описание картинки осталось только у блока с картинкой');
        self::assertSame(1, substr_count($body, 'name="image_file"'), 'выбор файла остался только у блока с картинкой');
        self::assertStringContainsString('name="items[0][image]"', $body, 'у карточки своя картинка в строке');
        self::assertStringContainsString('name="items[0][note]"', $body, 'у карточки своя подпись в строке');
    }

    public function testКаждыйВидБлокаПоказываетТолькоСвоиПоля(): void
    {
        // Ожидания записаны здесь явно, а не выведены из PAGES_KIND_FIELDS: тест обязан
        // поймать и ошибку в перечне полей, и лишнее поле, нарисованное шаблоном.
        $expected = [
            'hero' => ['media' => ['note', 'image', 'image_alt', 'image_file'], 'buttons' => true, 'items' => []],
            'band' => ['media' => ['note'], 'buttons' => false, 'items' => []],
            'cards' => ['media' => [], 'buttons' => false, 'items' => ['image', 'title', 'text', 'href', 'note']],
            'steps' => ['media' => [], 'buttons' => false, 'items' => ['title', 'text']],
            'prose' => ['media' => ['note'], 'buttons' => false, 'items' => []],
            'split' => ['media' => ['note', 'image', 'image_alt', 'image_file'], 'buttons' => true, 'items' => ['title', 'text']],
            'quote' => ['media' => [], 'buttons' => false, 'items' => ['text', 'title', 'note', 'image']],
            'pricing' => ['media' => ['note'], 'buttons' => false, 'items' => ['title', 'text', 'note', 'href']],
            'toc' => ['media' => [], 'buttons' => false, 'items' => []],
            'gallery' => ['media' => ['note'], 'buttons' => false, 'items' => ['image', 'title', 'note']],
            'related' => ['media' => [], 'buttons' => false, 'items' => ['href', 'title', 'text']],
            'table' => ['media' => ['note'], 'buttons' => false, 'items' => ['title', 'text', 'note']],
            'tabs' => ['media' => [], 'buttons' => false, 'items' => ['title', 'text']],
            'cta' => ['media' => [], 'buttons' => true, 'items' => []],
            'faq' => ['media' => [], 'buttons' => false, 'items' => ['title', 'text']],
        ];

        $page = createPage([
            'title' => 'Все виды блоков',
            'slug' => 'vse-vidy-blokov',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        foreach (array_keys($expected) as $kind) {
            createSection((string) $page['id'], [
                'kind' => $kind,
                'anchor' => '',
                'badge' => '',
                'title' => 'Блок ' . $kind,
                'lead_text' => '',
                'note' => '',
                'image' => '',
                'image_alt' => '',
                'buttons' => [],
                'items' => [],
                'settings' => [],
                'visible' => true,
            ], nowUtc());
        }

        $this->login();
        $body = Kernel::get('/admin/pages/' . (string) $page['id'])['body'];

        preg_match_all(
            '#<details class="[^"]*" data-section-id="([^"]+)" open>(.*?)</details>#s',
            $body,
            $matches,
            PREG_SET_ORDER
        );

        $forms = [];
        foreach ($matches as $match) {
            $forms[$match[1]] = $match[2];
        }

        $sections = pageSections((string) $page['id']);
        self::assertCount(count($expected), $sections, 'на странице по одному блоку каждого вида');
        self::assertCount(count($expected), $forms, 'каждый блок получил свою форму');

        foreach ($sections as $section) {
            $kind = (string) $section['kind'];
            $form = $forms[(string) $section['id']] ?? '';

            self::assertNotSame('', $form, 'форма блока ' . $kind . ' найдена');

            preg_match_all('#name="((?:anchor|badge|title|lead_text|note|image_file|image_alt|image|buttons\[\d+\]\[[a-z]+\]|items\[\d+\]\[[a-z]+\]))"#', $form, $names);

            $actual = array_values(array_unique($names[1]));
            sort($actual);

            $fields = ['anchor', 'badge', 'title', 'lead_text'];
            $fields = array_merge($fields, $expected[$kind]['media']);

            if ($expected[$kind]['buttons']) {
                $fields = array_merge($fields, ['buttons[0][blank]', 'buttons[0][href]', 'buttons[0][label]']);
            }

            foreach ($expected[$kind]['items'] as $key) {
                $fields[] = 'items[0][' . $key . ']';
            }

            sort($fields);

            self::assertSame($fields, $actual, 'блок «' . $kind . '» показывает ровно свои поля');
        }
    }

    public function testСохранениеКарточекНеСтираетСкрытыеПоля(): void
    {
        $page = createPage([
            'title' => 'Карточки сохраняют скрытое',
            'slug' => 'kartochki-sokhranyayut-skrytoe',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $section = createSection((string) $page['id'], [
            'kind' => 'cards',
            'anchor' => '',
            'badge' => '',
            'title' => 'Карточки',
            'lead_text' => '',
            'note' => 'Прежнее примечание',
            'image' => '/uploads/images/prezhnyaya-kartinka.png',
            'image_alt' => 'Прежнее описание картинки',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $saved = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'save-section',
            'section' => (string) $section['id'],
            'kind' => 'cards',
            'anchor' => '',
            'badge' => '',
            'title' => 'Новые карточки',
            'lead_text' => '',
            'visible' => '1',
        ]);

        self::assertSame(302, $saved['status']);

        $stored = sectionById((string) $section['id']) ?? [];

        self::assertSame('Новые карточки', (string) ($stored['title'] ?? ''));
        self::assertSame('Прежнее примечание', (string) ($stored['note'] ?? ''), 'скрытое примечание не стёрлось');
        self::assertSame('/uploads/images/prezhnyaya-kartinka.png', (string) ($stored['image'] ?? ''), 'скрытая картинка не стёрлась');
        self::assertSame('Прежнее описание картинки', (string) ($stored['image_alt'] ?? ''), 'скрытое описание не стёрлось');
    }

    public function testСвёрнутостьБлоковХранитсяВСкрипте(): void
    {
        $script = Kernel::get('/assets/js/admin-sections.js');

        self::assertSame(200, $script['status']);
        self::assertStringContainsString('data-section-id', $script['body'], 'скрипт находит блоки по идентификатору');
        self::assertStringContainsString('localStorage', $script['body'], 'состояние хранится в браузере');
        self::assertStringContainsString("addEventListener('toggle'", $script['body'], 'скрипт слушает сворачивание блока');
        self::assertStringContainsString('cms-sections-collapsed:', $script['body'], 'ключ хранилища привязан к странице');
    }

    public function testКартинкуБлокаМожноЗагрузитьСКомпьютера(): void
    {
        $page = createPage([
            'title' => 'Блок с картинкой',
            'slug' => 'blok-s-kartinkoy',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $source = dirname(__DIR__, 2) . '/public/assets/img/brand/og-default.png';

        self::assertFileExists($source);

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $created = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'create-section',
            'kind' => 'hero',
            'anchor' => 's-kartinkoy',
            'badge' => '',
            'title' => 'С картинкой',
            'lead_text' => '',
            'note' => '',
            'image' => '',
            'image_alt' => 'Витрина школы',
            'visible' => '1',
            'image_file' => new \CURLFile($source, 'image/png', 'vitrina.png'),
        ]);

        self::assertSame(302, $created['status']);
        self::assertStringContainsString('?notice=section_created', $created['headers']['location']);

        $section = pageSections((string) $page['id'])[0] ?? [];
        $image = (string) ($section['image'] ?? '');

        self::assertMatchesRegularExpression('#^/uploads/images/[0-9a-hjkmnp-tv-z]{26}\.png$#', $image, 'сервер сам назвал файл');
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . $image, 'картинка легла в каталог загрузок');

        pageImageDelete($image);
        sectionDelete((string) ($section['id'] ?? ''));
    }

    public function testПлавающаяКнопкаСохраняетБезРедиректа(): void
    {
        $page = createPage([
            'title' => 'Сохранение без перезагрузки',
            'slug' => 'json-sokhranenie',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $section = createSection((string) $page['id'], [
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Старое название',
            'lead_text' => 'Старый текст.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $token = $this->token('/admin/pages/' . (string) $page['id']);

        $saved = Kernel::post('/admin/pages/' . (string) $page['id'], [
            '_token' => $token,
            'op' => 'save-section',
            'section' => (string) $section['id'],
            'kind' => 'prose',
            'anchor' => '',
            'badge' => '',
            'title' => 'Новое название',
            'lead_text' => 'Новый текст.',
            'note' => '',
            'image' => '',
            'image_alt' => '',
            'visible' => '1',
        ], ['headers' => ['X-CMS-Section-Save' => '1']]);

        self::assertSame(200, $saved['status'], 'fetch получает ответ, а не редирект');
        self::assertStringContainsString('application/json', (string) ($saved['headers']['content-type'] ?? ''));

        $data = (array) json_decode($saved['body'], true);

        self::assertTrue($data['ok'] ?? false);
        self::assertSame('section_saved', $data['notice'] ?? '');

        $stored = sectionById((string) $section['id']) ?? [];

        self::assertSame('Новое название', (string) ($stored['title'] ?? ''));
    }

    public function testКартинкаБлокаПоказываетсяПревьюНадПолем(): void
    {
        $page = createPage([
            'title' => 'Страница с превью',
            'slug' => 'stranica-s-preview',
            'description' => '',
            'status' => 'PUBLISHED',
            'noindex' => '0',
        ], nowUtc());

        $section = createSection((string) $page['id'], [
            'kind' => 'hero',
            'anchor' => '',
            'badge' => '',
            'title' => 'Блок с картинкой',
            'lead_text' => '',
            'note' => '',
            'image' => '/assets/img/brand/og-default.png',
            'image_alt' => 'Обложка школы',
            'buttons' => [],
            'items' => [],
            'settings' => [],
            'visible' => true,
        ], nowUtc());

        $this->login();
        $screen = Kernel::get('/admin/pages/' . (string) $page['id']);

        self::assertSame(200, $screen['status']);

        $preview = strpos($screen['body'], 'data-admin-image-preview');
        $field = strpos($screen['body'], 'id="section-image-' . (string) $section['id'] . '"');

        self::assertIsInt($preview, 'у картинки блока есть превью');
        self::assertIsInt($field, 'поле пути картинки на месте');
        self::assertLessThan($field, $preview, 'превью стоит над полем картинки');
        self::assertSame(1, substr_count($screen['body'], 'data-admin-image-preview'), 'превью печатается один раз');
        self::assertStringContainsString('class="flex flex-col gap-3 sm:grid sm:grid-cols-3"', $screen['body'], 'поля выровнены сеткой');
        self::assertStringContainsString('order-2 sm:col-start-2 sm:row-start-1', $screen['body'], 'превью стоит в средней колонке над полем');
        self::assertStringContainsString('sm:col-start-1 sm:row-start-2', $screen['body'], 'примечание выровнено с полями');
        self::assertStringContainsString('order-3 text-sm sm:col-start-2 sm:row-start-2', $screen['body'], 'поле картинки под превью');
        self::assertStringContainsString('order-4 block text-sm sm:col-start-3 sm:row-start-2', $screen['body'], 'описание картинки в третьей колонке');
        self::assertStringContainsString('src="/assets/img/brand/og-default.png"', $screen['body']);
        self::assertStringContainsString('alt="Обложка школы"', $screen['body']);
    }
}
