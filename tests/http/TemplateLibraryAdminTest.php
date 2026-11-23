<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Дизайн → Библиотека шаблонов» /admin/templates: каталог готовых наборов блоков и
 * применение к странице.
 *
 * Право — конструктора (login), поэтому редактор экран видит: шаблон ставит содержимое, а не
 * меняет оформление сайта. Проверяется главное: применение действительно создаёт блоки на
 * странице, замена убирает прежние в корзину, чужой ключ отклоняется, а фильтр категории не
 * показывает весь каталог.
 */
final class TemplateLibraryAdminTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'админ-пароль';
    private const EDITOR_EMAIL = 'redaktor@school.dev';
    private const EDITOR_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'admin');
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

        foreach (['trash', 'page_old_slug', 'page_section', 'page'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    private function login(string $email, string $password): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function testГостюБиблиотекаНедоступна(): void
    {
        $page = Kernel::get('/admin/templates', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход');
        self::assertStringContainsString('/login?next=%2Fadmin%2Ftemplates', $page['headers']['location']);

        $denied = Kernel::post('/admin/templates', ['op' => 'apply', 'page' => '', 'template' => 'contacts']);

        self::assertSame(403, $denied['status'], 'POST без CSRF-токена отклоняется');
    }

    public function testРедакторВидитКаталогШаблонов(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $screen = Kernel::get('/admin/templates');

        self::assertSame(200, $screen['status'], 'шаблон ставит содержимое — право конструктора');
        self::assertStringContainsString('<title>Библиотека шаблонов — админка</title>', $screen['body']);
        self::assertStringContainsString('Главная школы', $screen['body'], 'готовый шаблон в каталоге');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран закрыт от поиска');
        self::assertStringContainsString('href="/admin/templates?category=education"', $screen['body'], 'фильтр категорий');
        self::assertStringContainsString('Главная страница', $screen['body'], 'главная есть в списке страниц');
    }

    public function testФильтрКатегорииПоказываетТолькоСвои(): void
    {
        $this->login(self::EDITOR_EMAIL, self::EDITOR_PASSWORD);

        $filtered = Kernel::get('/admin/templates?category=faq');

        self::assertSame(200, $filtered['status']);
        self::assertStringContainsString('Вопросы и ответы', $filtered['body'], 'шаблон категории на месте');
        self::assertStringNotContainsString('Главная школы', $filtered['body'], 'чужая категория не печатается');

        // Неизвестная категория — пустой список, а не весь каталог: фильтр не должен «не найти» и
        // показать всё.
        $unknown = Kernel::get('/admin/templates?category=nesushchestvuyushchaya');

        self::assertSame(200, $unknown['status']);
        self::assertStringContainsString('В этой категории шаблонов нет', $unknown['body']);
        self::assertStringNotContainsString('Главная школы', $unknown['body']);
    }

    public function testПрименениеСоздаётБлокиИВедётВРедактор(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $created = createPage([
            'title' => 'Лендинг школы',
            'slug' => 'lending-shkoly',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);
        self::assertTrue($created['ok'], $created['error']);

        $token = Kernel::tokenFrom(Kernel::get('/admin/templates')['body']);

        $applied = Kernel::post('/admin/templates', [
            '_token' => $token,
            'op' => 'apply',
            'page' => (string) $created['id'],
            'template' => 'contacts',
        ]);

        self::assertSame(302, $applied['status']);
        self::assertSame(
            '/admin/pages/' . (string) $created['id'] . '?notice=template_applied',
            $applied['headers']['location'],
            'после применения человек попадает в редактор страницы'
        );

        $sections = pageSections((string) $created['id']);
        self::assertNotSame([], $sections, 'блоки шаблона легли на страницу');

        $kinds = array_map(static fn (array $row): string => (string) $row['kind'], $sections);
        self::assertSame(['hero', 'contacts', 'lead'], $kinds, 'состав блоков шаблона «Контакты»');

        // Экран редактора показывает применённые блоки: шаблон доехал до конструктора, а не
        // остался в базе.
        $editor = Kernel::get('/admin/pages/' . (string) $created['id']);
        self::assertSame(200, $editor['status']);
        self::assertStringContainsString('Как с нами связаться', $editor['body']);
    }

    public function testЗаменаУбираетПрежниеБлокиВКорзину(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $created = createPage([
            'title' => 'Старая страница',
            'slug' => 'staraya-stranitsa',
            'description' => '',
            'status' => 'DRAFT',
            'noindex' => '0',
        ]);
        self::assertTrue($created['ok'], $created['error']);
        $pageId = (string) $created['id'];

        $old = createSection($pageId, ['kind' => 'prose', 'title' => 'Старый блок', 'lead_text' => 'Был тут.']);
        self::assertTrue($old['ok'], $old['error']);

        $token = Kernel::tokenFrom(Kernel::get('/admin/templates')['body']);

        $applied = Kernel::post('/admin/templates', [
            '_token' => $token,
            'op' => 'apply',
            'page' => $pageId,
            'template' => 'thanks',
            'replace' => '1',
        ]);

        self::assertSame(302, $applied['status']);

        $sections = pageSections($pageId);
        $kinds = array_map(static fn (array $row): string => (string) $row['kind'], $sections);
        self::assertSame(['hero', 'steps'], $kinds, 'остались только блоки шаблона');

        $trashed = one('SELECT id FROM ' . t('trash') . ' WHERE entity_id = ?', [$old['id']]);
        self::assertNotNull($trashed, 'прежний блок в корзине — его можно вернуть');
    }

    public function testЧужойКлючШаблонаОтклоняется(): void
    {
        $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $token = Kernel::tokenFrom(Kernel::get('/admin/templates')['body']);

        $applied = Kernel::post('/admin/templates', [
            '_token' => $token,
            'op' => 'apply',
            'page' => '',
            'template' => 'nesushchestvuyushchiy',
        ]);

        self::assertSame(302, $applied['status']);
        // Отказ ведёт обратно в библиотеку: редактор страницы не должен открываться «пустым».
        self::assertStringContainsString('/admin/templates?notice=migration-failed', $applied['headers']['location']);
        self::assertSame([], pageSections(''), 'ни одного блока на главной');
    }
}
