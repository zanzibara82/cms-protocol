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
}
