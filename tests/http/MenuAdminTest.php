<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Экран «Меню» /admin/menu проверяется настоящим запросом: гость уходит на вход или получает 403,
 * редактор входит и исполняет действия одним POST-обработчиком с op (образец — конструктор страниц).
 * Каждое действие — PRG-редирект с ключом уведомления из словаря ядра; видимость делит ключ
 * с сохранением; отказ (кривой адрес) уходит в flash-карте migration-failed, как у конструктора.
 */
final class MenuAdminTest extends TestCase
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

    public function testГостюЭкранМенюНедоступен(): void
    {
        $page = Kernel::get('/admin/menu', ['follow' => false]);

        self::assertSame(302, $page['status'], 'анонима ядро ведёт на вход, как на остальные приватные адреса');
        self::assertStringContainsString('/login?next=%2Fadmin%2Fmenu', $page['headers']['location']);

        // POST анонима не редиректится: редирект потерял бы тело запроса.
        $denied = Kernel::post('/admin/menu', ['op' => 'create-item', 'label' => 'Взлом', 'href' => '/vzlom', 'slot' => 'HEADER']);

        self::assertSame(403, $denied['status']);
        self::assertStringContainsString('Раздел открыт только редактору и администратору.', $denied['body']);
    }

    public function testЭкранОткрытРедакторуИПоказываетДвеКарты(): void
    {
        $this->login();

        $screen = Kernel::get('/admin/menu');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('<title>Меню — админка</title>', $screen['body']);
        self::assertStringContainsString('Шапка', $screen['body'], 'первая карта слота');
        self::assertStringContainsString('Подвал', $screen['body'], 'вторая карта слота');
        self::assertStringContainsString('name="op" value="create-item"', $screen['body'], 'добавление — форма с op, а не отдельный адрес');
        self::assertStringContainsString('Пунктов ещё нет', $screen['body'], 'пустой день-1 экран не ломается');
        self::assertStringContainsString('noindex, nofollow', $screen['body'], 'приватный экран поисковой машине закрыт');
    }

    public function testСозданиеПунктаНазываетКлючУведомления(): void
    {
        $this->login();
        $token = $this->token('/admin/menu');

        $created = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'create-item',
            'slot' => 'HEADER',
            'label' => 'О школе',
            'href' => '/o-shkole',
            'blank' => '1',
        ]);

        self::assertSame(302, $created['status']);
        self::assertSame('/admin/menu?notice=menu_created', $created['headers']['location']);

        $row = one('SELECT slot, section, label, blank FROM ' . t('menu_item') . ' WHERE href = ?', ['/o-shkole']);

        self::assertNotNull($row);
        self::assertSame('HEADER', (string) $row['slot']);
        self::assertSame('О школе', (string) $row['label']);
        self::assertSame('1', (string) $row['blank']);

        $screen = Kernel::get('/admin/menu');

        self::assertStringContainsString('О школе', $screen['body'], 'новый пункт в строках карты');
        self::assertStringContainsString('value="/o-shkole"', $screen['body'], 'форма правки заполнена адресом');
    }

    public function testОтказКривогоАдресаОбъясняетсяПоЧеловечески(): void
    {
        $this->login();
        $token = $this->token('/admin/menu');

        $refused = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'create-item',
            'slot' => 'FOOTER',
            'label' => 'Кривое',
            'href' => 'javascript:alert(1)',
        ]);

        self::assertSame(302, $refused['status']);
        self::assertStringContainsString('notice=migration-failed', $refused['headers']['location']);
        self::assertStringNotContainsString(
            'error=',
            $refused['headers']['location'],
            'причина не уезжает в адрес: он остаётся в истории браузера и access-логе'
        );

        $screen = Kernel::get('/admin/menu');

        self::assertStringContainsString('Адрес не разобрать', $screen['body'], 'человеку печатается причина из flash');
        self::assertStringNotContainsString('javascript', $screen['body'], 'кривой адрес не сохранён');
    }

    public function testПравкаПунктаНеПереезжаетСлотИМеняетПоля(): void
    {
        $id = createMenuItem('FOOTER', ['label' => 'Старое', 'href' => '/staryj', 'section' => 'Обучение'], nowUtc())['id'];

        $this->login();
        $token = $this->token('/admin/menu');

        $saved = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'save-item',
            'item' => (string) $id,
            // Чужой слот формы правки игнорируется: пункт не переезжает между картами сохранением.
            'slot' => 'HEADER',
            'label' => 'Переименованный',
            'href' => '/novyj',
            'section' => 'Программы',
        ]);

        self::assertSame(302, $saved['status']);
        self::assertStringContainsString('notice=menu_saved', $saved['headers']['location']);

        $row = one('SELECT slot, section, label, href FROM ' . t('menu_item') . ' WHERE id = ?', [$id]);

        self::assertNotNull($row);
        self::assertSame('FOOTER', (string) $row['slot'], 'пункт не переезжает между картами сохранением');
        self::assertSame('Программы', (string) $row['section']);
        self::assertSame('/novyj', (string) $row['href']);
    }

    public function testПерестановкаВидимостьИУдалениеИсполняются(): void
    {
        // Чистый слот: пункты прежних тестов не задают порядок этого.
        run('DELETE FROM ' . t('menu_item'));

        $first = createMenuItem('HEADER', ['label' => 'Первый', 'href' => '/pervyj'], nowUtc());
        $second = createMenuItem('HEADER', ['label' => 'Второй', 'href' => '/vtoroj'], nowUtc());

        $this->login();
        $token = $this->token('/admin/menu');

        // Перестановка: второй уходит вверх, порядок в базе меняется.
        $moved = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'move-item',
            'item' => (string) $second['id'],
            'dir' => 'up',
        ]);

        self::assertSame(302, $moved['status']);
        self::assertStringContainsString('notice=menu_moved', $moved['headers']['location']);

        $labels = [];
        foreach (all('SELECT label FROM ' . t('menu_item') . " WHERE slot = 'HEADER' ORDER BY sort ASC, label ASC LIMIT 100") as $row) {
            $labels[] = (string) $row['label'];
        }

        self::assertSame(['Второй', 'Первый'], $labels);

        // Скрытие: делит ключ с сохранением (отдельного ключа у видимости нет).
        $hidden = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'toggle-item',
            'item' => (string) $first['id'],
        ]);

        self::assertSame(302, $hidden['status']);
        self::assertStringContainsString('notice=menu_saved', $hidden['headers']['location']);
        self::assertSame('0', (string) value('SELECT visible FROM ' . t('menu_item') . ' WHERE id = ?', [$first['id']]));

        // Экран видит и скрытый пункт: конструктор правит видимость обратно.
        $screen = Kernel::get('/admin/menu');

        self::assertStringContainsString('Первый', $screen['body'], 'скрытый пункт остаётся на карте конструктора');
        self::assertStringContainsString('— скрыт', $screen['body'], 'состояние подписано на карте');

        // Удаление: строка уходит целиком, ключ menu_deleted.
        $deleted = Kernel::post('/admin/menu', [
            '_token' => $token,
            'op' => 'delete-item',
            'item' => (string) $second['id'],
        ]);

        self::assertSame(302, $deleted['status']);
        self::assertStringContainsString('notice=menu_deleted', $deleted['headers']['location']);
        self::assertNull(one('SELECT id FROM ' . t('menu_item') . ' WHERE id = ?', [$second['id']]));
    }

    public function testДействиеБезТокенаОтклоняется(): void
    {
        $this->login();

        $denied = Kernel::post('/admin/menu', [
            'op' => 'create-item',
            'slot' => 'HEADER',
            'label' => 'Без токена',
            'href' => '/ok',
        ]);

        self::assertSame(403, $denied['status']);
        self::assertStringContainsString('Проверка формы не прошла', $denied['body']);
        self::assertStringNotContainsString('Без токена', Kernel::get('/admin/menu')['body'], 'чужая форма не создала пункт');
    }
}
