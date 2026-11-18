<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Режим техобслуживания на живом сервере: флажок в «Настройках» включает заглушку для посетителя,
 * а вход и админка остаются открытыми — иначе режим нечем выключить.
 *
 * Проверка держит обе стороны, потому что дорога не только ошибка «закрыли всё»: заглушка, которая
 * не отдаёт 503 и не запрещает индексирование, попадёт в поиск как обычная страница, а публичная
 * лента и карта сайта остались бы живыми при закрытом сайте.
 */
final class MaintenanceModeHttpTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@school.dev';
    private const ADMIN_PASSWORD = 'верный-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::clearTable('option');
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

        // Флажок живёт в опциях и переживает тест: без снятия следующий тест класса получил бы
        // закрытый сайт и падал бы на чужом состоянии.
        deleteOption('maintenance_mode');
        deleteOption('maintenance_text');
        optionsReset();
    }

    protected function tearDown(): void
    {
        deleteOption('maintenance_mode');
        deleteOption('maintenance_text');
        optionsReset();
    }

    private function login(): void
    {
        $page = Kernel::get('/login');

        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
    }

    /** Включает режим через настоящую форму настроек: проверяется тот же путь, что у человека. */
    private function enableMaintenance(string $text = 'Идут технические работы. Вернитесь через час.'): void
    {
        $this->login();
        $token = Kernel::tokenFrom(Kernel::get('/admin/settings')['body']);

        $saved = Kernel::post('/admin/settings', [
            '_token' => $token,
            'header_logo' => '',
            'header_text' => '',
            'footer_logo' => '',
            'footer_text' => '',
            'footer_copyright' => '',
            'maintenance_mode' => '1',
            'maintenance_text' => $text,
        ]);

        self::assertSame(302, $saved['status'], 'настройки сохранились');
        self::assertSame('1', getOption('maintenance_mode'), 'флажок записан в опции');
    }

    public function testФлажокЕстьНаЭкранеНастроек(): void
    {
        $this->login();

        $screen = Kernel::get('/admin/settings');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('maintenance_mode', $screen['body'], 'имя поля флажка');
        self::assertStringContainsString('Техобслуживание', $screen['body'], 'подпись флажка');
        self::assertStringContainsString('name="maintenance_text"', $screen['body'], 'поле текста заглушки');
    }

    public function testПриВключённомРежимеПубличнаяЧастьОтдаётЗаглушку(): void
    {
        $this->enableMaintenance();

        // Включивший режим администратор видит сайт как обычно, поэтому гостем становится
        // запрос с чистой колбой — иначе эта проверка смотрела бы на сотрудника.
        Kernel::freshCookies();
        $home = Kernel::get('/', ['follow' => false]);

        self::assertSame(503, $home['status'], 'заглушка отвечает 503');
        self::assertStringContainsString('Сайт на техобслуживании', $home['body'], 'заголовок заглушки');
        self::assertStringContainsString('Идут технические работы. Вернитесь через час.', $home['body'], 'свой текст из настроек');
        self::assertSame('noindex, nofollow', $home['headers']['x-robots-tag'] ?? null, 'заглушка не индексируется');
        self::assertSame('no-store', $home['headers']['cache-control'] ?? null, 'заглушка не кэшируется');

        // Вход — часть двери: гость обязан увидеть форму, а не заглушку, иначе режим нечем снять.
        Kernel::freshCookies();
        $login = Kernel::get('/login', ['follow' => false]);

        self::assertSame(200, $login['status'], 'вход открыт при техобслуживании');
        self::assertStringContainsString('name="_token"', $login['body'], 'форма входа напечатана');
    }

    public function testНесуществующийАдресТожеОтдаётЗаглушку(): void
    {
        $this->enableMaintenance();
        Kernel::freshCookies();

        // Гейт стоит до разбора маршрута: закрытый сайт не должен выдавать 404 на любой путь —
        // иначе по коду ответа видно, какие адреса существуют.
        self::assertSame(503, Kernel::get('/net-takoy-stranicy', ['follow' => false])['status']);
    }

    public function testАдминкаОткрытаПриТехобслуживании(): void
    {
        $this->enableMaintenance();
        $this->login();

        $admin = Kernel::get('/admin/settings');

        self::assertSame(200, $admin['status'], 'админка открыта: режим снимается здесь же');
        self::assertStringContainsString('maintenance_mode', $admin['body'], 'флажок виден включённым');
    }

    public function testСнятиеФлажкаВозвращаетСайт(): void
    {
        $this->enableMaintenance();
        Kernel::freshCookies();

        self::assertSame(503, Kernel::get('/', ['follow' => false])['status'], 'сайт закрыт');

        $this->login();
        $token = Kernel::tokenFrom(Kernel::get('/admin/settings')['body']);

        // Отметку снимают, просто не прислав поле: флажок и тексты едут одной формой.
        $saved = Kernel::post('/admin/settings', [
            '_token' => $token,
            'header_logo' => '',
            'header_text' => '',
            'footer_logo' => '',
            'footer_text' => '',
            'footer_copyright' => '',
            'maintenance_text' => '',
        ]);

        self::assertSame(302, $saved['status']);
        // Кеш опций живёт в статике процесса PHPUnit: без сброса getOption() показал бы значение,
        // прочитанное до сохранения, — то есть проверка смотрела бы на собственный кеш, а не на базу.
        optionsReset();
        self::assertSame('', getOption('maintenance_mode'), 'флажок снят');

        self::assertSame(200, Kernel::get('/')['status'], 'сайт снова открыт');
    }

    public function testЛентаИКартаСайтаТожеЗакрыты(): void
    {
        $this->enableMaintenance();
        Kernel::freshCookies();

        // Лента и карта сайта — публичные адреса: при закрытом сайте они не выдавали бы материалы.
        foreach (['/feed.xml', '/sitemap.xml', '/robots.txt'] as $path) {
            self::assertSame(503, Kernel::get($path, ['follow' => false])['status'], 'закрыт: ' . $path);
        }
    }

    public function testЗалогиненныйАдминистраторВидитСайтКакОбычно(): void
    {
        $this->enableMaintenance();
        // enableMaintenance() уже вошла администратором; вход повторяем явно, потому что
        // проверка ниже смотрит именно на залогиненного человека, а не на порядок вызовов.
        $this->login();

        $home = Kernel::get('/', ['follow' => false]);

        // Сотрудник включил режим и обязан видеть, что именно он закрыл: заглушка вместо
        // собственных страниц не дала бы проверить вёрстку и содержимое перед открытием сайта.
        self::assertSame(200, $home['status'], 'администратор видит обычную главную');
        self::assertStringNotContainsString('Сайт на техобслуживании', $home['body'], 'заглушки нет');

        foreach (['/feed.xml', '/sitemap.xml', '/robots.txt'] as $path) {
            self::assertSame(200, Kernel::get($path, ['follow' => false])['status'], 'администратору открыт: ' . $path);
        }
    }

    public function testГостьБезКукиНеПолучаетДоступаКСайту(): void
    {
        $this->enableMaintenance();

        // Тот же адрес, но чистая колба: проверка отделяет «пускаем сотрудника» от
        // «пускаем всякого, кто прислал любую куку».
        Kernel::freshCookies();

        self::assertSame(503, Kernel::get('/', ['follow' => false])['status'], 'гость видит заглушку');
    }

    public function testПодделаннаяКукаНеПускаетНаСайт(): void
    {
        $this->enableMaintenance();
        Kernel::freshCookies();

        // Кука с несуществующим идентификатором: сессия поднимется, но пользователя в базе нет.
        Kernel::setCookie(SESSION_COOKIE_NAME, 'poddelka-1234567890');

        self::assertSame(503, Kernel::get('/', ['follow' => false])['status'], 'подделка остаётся гостем');
    }
}
