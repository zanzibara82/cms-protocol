<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.1 настоящими запросами: корзина, медиатека, журнал действий, журнал согласий,
 * сквозной поиск, предпросмотр по ссылке, сброс пароля, ленты и индекс карты сайта.
 *
 * Проверяется то, чего не видит unit- и db-набор: статусы, редиректы, заголовки запрета
 * индексирования и хранения, права ролей и CSRF.
 */
final class Release21HttpTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'длинный-тестовый-пароль', 'admin');
        TestDb::seedUser('editor@school.dev', 'длинный-тестовый-пароль', 'editor');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['trash', 'clipboard', 'audit_log', 'consent_log', 'media', 'preview_token', 'password_reset', 'password_reset_attempt', 'login_attempt'] as $table) {
            TestDb::clearTable($table);
        }

        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page_old_slug'));
        run('DELETE FROM ' . t('page'));
    }

    private function enter(string $email = 'admin@school.dev'): void
    {
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => 'длинный-тестовый-пароль',
        ]);
    }

    /**
     * @param array<string, array<int|string, mixed>|string> $fields
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private function press(string $path, array $fields): array
    {
        $page = Kernel::get($path);

        return Kernel::post($path, array_merge(['_token' => Kernel::tokenFrom($page['body'])], $fields));
    }

    // --- Права и CSRF ------------------------------------------------------------------------

    // Корзина, медиатека и поиск — право login: редактор и администратор.
    public function testАнонимНеПопадаетВНовыеЭкраны(): void
    {
        foreach (['/admin/trash', '/admin/media', '/admin/search'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(302, $page['status'], 'Аноним получает редирект с ' . $path);
            self::assertStringContainsString('/login', $page['headers']['location']);
        }
    }

    // Журнал действий, согласия и выгрузка — право manage: только администратор.
    public function testРедакторНеПопадаетВЖурналы(): void
    {
        $this->enter('editor@school.dev');

        foreach (['/admin/audit', '/admin/consents', '/admin/consents/export'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(403, $page['status'], 'Редактор получает отказ с ' . $path);
        }
    }

    public function testАдминистраторВидитЖурналИСогласия(): void
    {
        $this->enter();

        self::assertSame(200, Kernel::get('/admin/audit')['status']);
        self::assertSame(200, Kernel::get('/admin/consents')['status']);
        self::assertSame(200, Kernel::get('/admin/trash')['status']);
        self::assertSame(200, Kernel::get('/admin/media')['status']);
        // Кириллица в адресе обязана быть закодирована: curl не кодирует её сам, и сервер
        // получил бы строку, которую не разбирает как параметр.
        self::assertSame(200, Kernel::get('/admin/search?q=' . rawurlencode('школа'))['status']);
    }

    public function testПостБезCsrfТокенаОтказывает(): void
    {
        $this->enter();

        // Журнал мог получить запись от самого входа: считаем только действия корзины.
        TestDb::clearTable('audit_log');

        $response = Kernel::post('/admin/trash', ['op' => 'empty']);

        // Отказ приходит страницей 403: csrfCheck() отвечает отказом, действие не выполняется.
        self::assertSame(403, $response['status'], 'POST без токена отвергается');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('audit_log')), 'Действие не выполнилось');
    }

    // --- Корзина -----------------------------------------------------------------------------

    public function testУдалениеСтраницыУводитЕёВКорзинуАВосстановлениеВозвращает(): void
    {
        $page = createPage(['slug' => 'v-korzinu', 'title' => 'В корзину', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();

        $deleted = $this->press('/admin/pages/' . (string) $page['id'], ['op' => 'delete-page']);
        self::assertSame(302, $deleted['status']);
        self::assertNull(pageById((string) $page['id']), 'Страница ушла из живых таблиц');

        $trashPage = Kernel::get('/admin/trash');
        self::assertSame(200, $trashPage['status']);
        self::assertStringContainsString('В корзину', $trashPage['body'], 'Корзина показывает удалённую страницу');

        $trash = trashList()[0];
        $restored = $this->press('/admin/trash', ['op' => 'restore', 'item' => $trash['id']]);
        self::assertSame(302, $restored['status']);
        self::assertNotNull(pageById((string) $page['id']), 'Страница вернулась');
    }

    public function testОчисткаКорзиныТребуетПодтвержденияИВыполняется(): void
    {
        $page = createPage(['slug' => 'na-vsegda', 'title' => 'Навсегда', 'status' => PAGES_STATUS_DRAFT]);
        trashPage((string) $page['id']);
        $this->enter();

        $cleared = $this->press('/admin/trash', ['op' => 'empty']);

        self::assertSame(302, $cleared['status']);
        self::assertSame([], trashList());
    }

    // --- Медиатека ---------------------------------------------------------------------------

    public function testЭкранМедиатекиПечатаетСписокИПодписи(): void
    {
        $moment = utcStamp(nowUtc());
        insert('media', [
            'id' => newId(),
            'path' => PAGE_IMAGE_PREFIX . 'probe-http.png',
            'title' => 'Пробный файл',
            'alt' => 'Описание для незрячих',
            'mime' => 'image/png',
            'width' => 2,
            'height' => 2,
            'bytes' => 100,
            'uploaded_by' => null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);
        $this->enter();

        $screen = Kernel::get('/admin/media');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Пробный файл', $screen['body']);
        self::assertStringContainsString('Описание для незрячих', $screen['body']);
        self::assertStringContainsString('min-h-[44px]', $screen['body'], 'Контролы экрана тапабельные');
    }

    // --- Журнал действий ---------------------------------------------------------------------

    public function testЖурналПеречисляетДействияРедакции(): void
    {
        auditLog('page.create', 'page', 'p1', [], 'Создана страница «О школе»');
        $this->enter();

        $screen = Kernel::get('/admin/audit');

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('page.create', $screen['body']);
        self::assertStringContainsString('Создана страница', $screen['body']);
    }

    // --- Журнал согласий ---------------------------------------------------------------------

    public function testСогласияВыгружаютсяФайломCsv(): void
    {
        consentRecord('maria@example.ru', 'lead', '/o-nas#zayavka');
        $this->enter();

        $export = Kernel::get('/admin/consents/export');

        self::assertSame(200, $export['status']);
        self::assertStringContainsString('text/csv', (string) ($export['headers']['content-type'] ?? ''));
        self::assertStringContainsString('attachment', (string) ($export['headers']['content-disposition'] ?? ''));
        self::assertStringContainsString('maria@example.ru', $export['body']);
    }

    // --- Сквозной поиск ----------------------------------------------------------------------

    public function testПоискПечатаетНайденныеСтраницы(): void
    {
        createPage(['slug' => 'poisk-probe', 'title' => 'Поисковая проба', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();

        $found = Kernel::get('/admin/search?q=' . rawurlencode('Поисковая'));

        self::assertSame(200, $found['status']);
        self::assertStringContainsString('Поисковая проба', $found['body']);
    }

    public function testПоискСПустымЗапросомПечатаетПодсказку(): void
    {
        $this->enter();

        $empty = Kernel::get('/admin/search');

        self::assertSame(200, $empty['status']);
        // Текст подсказки шаблона: «Введите хотя бы 2 символа…».
        self::assertStringContainsString('Введите хотя бы 2 символа', $empty['body'], 'Пустой запрос объясняет, что вводить');
    }

    // --- Предпросмотр по ссылке --------------------------------------------------------------

    // Главное свойство: ссылка открывает черновик анониму, но запрещает индексирование и хранение.
    public function testПредпросмотрОткрываетЧерновикИЗапрещаетИндексирование(): void
    {
        $page = createPage(['slug' => 'chernovik', 'title' => 'Черновик', 'status' => PAGES_STATUS_DRAFT]);

        $token = previewTokenCreate((string) $page['id'], '');
        self::assertTrue($token['ok'], 'Ссылка обязана создаться: ' . $token['error']);

        $url = (string) $token['url'];
        $path = (string) parse_url($url, PHP_URL_PATH);

        Kernel::freshCookies();
        $preview = Kernel::get($path);

        self::assertSame(200, $preview['status'], 'Аноним видит черновик по ссылке');
        self::assertStringContainsString('Черновик', $preview['body']);
        self::assertStringContainsString('noindex', (string) ($preview['headers']['x-robots-tag'] ?? ''), 'Поисковику вход закрыт');
        self::assertStringContainsString('no-store', (string) ($preview['headers']['cache-control'] ?? ''), 'Ответ не кэшируется');
    }

    public function testЧужаяСсылкаПредпросмотраНеОткрываетЧерновик(): void
    {
        $page = createPage(['slug' => 'zakrytyy', 'title' => 'Закрытый', 'status' => PAGES_STATUS_DRAFT]);
        previewTokenCreate((string) $page['id'], '');

        Kernel::freshCookies();
        $wrong = Kernel::get('/preview/' . str_repeat('a', 64));

        self::assertSame(404, $wrong['status'], 'Неверный токен не открывает ничего');
    }

    // --- Сброс пароля ------------------------------------------------------------------------

    public function testФормаСбросаОткрытаАнонимуИНеРаскрываетАдреса(): void
    {
        Kernel::freshCookies();
        $form = Kernel::get('/forgot-password');

        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Сброс пароля', $form['body']);
        self::assertStringContainsString('name="_token"', $form['body'], 'Форма несёт CSRF-токен');

        // Ответ одинаков для известного и неизвестного адреса.
        $known = Kernel::post('/forgot-password', [
            '_token' => Kernel::tokenFrom($form['body']),
            'email' => 'admin@school.dev',
        ]);
        $unknown = Kernel::post('/forgot-password', [
            '_token' => Kernel::tokenFrom(Kernel::get('/forgot-password')['body']),
            'email' => 'nikogo@school.dev',
        ]);

        self::assertSame(302, $known['status']);
        self::assertSame(302, $unknown['status']);
        self::assertStringContainsString('password-reset-sent', (string) ($known['headers']['location'] ?? ''));
        self::assertStringContainsString('password-reset-sent', (string) ($unknown['headers']['location'] ?? ''));
    }

    public function testКриваяСсылкаСбросаОтдаётЧетырестаЧетыре(): void
    {
        Kernel::freshCookies();

        self::assertSame(404, Kernel::get('/reset-password/' . str_repeat('b', 64))['status']);
    }

    // --- Ленты и карта сайта -----------------------------------------------------------------

    public function testЛентыОтдаютсяСПравильнымТипомСодержимого(): void
    {
        Kernel::freshCookies();

        $rss = Kernel::get('/feed.xml');
        self::assertSame(200, $rss['status']);
        self::assertStringContainsString('application/rss+xml', (string) ($rss['headers']['content-type'] ?? ''));
        self::assertStringContainsString('<rss version="2.0"', $rss['body']);

        $atom = Kernel::get('/feed.atom');
        self::assertSame(200, $atom['status']);
        self::assertStringContainsString('application/atom+xml', (string) ($atom['headers']['content-type'] ?? ''));
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $atom['body']);
    }

    public function testИндексКартыСайтаИЧастиОтдаются(): void
    {
        createPage(['slug' => 'karta-probe', 'title' => 'Карта', 'status' => PAGES_STATUS_PUBLISHED]);
        Kernel::freshCookies();

        $index = Kernel::get('/sitemap-index.xml');
        self::assertSame(200, $index['status']);
        self::assertStringContainsString('<sitemapindex', $index['body']);

        $part = Kernel::get('/sitemap/1');
        self::assertSame(200, $part['status']);
        self::assertStringContainsString('<urlset', $part['body']);
        self::assertStringContainsString('karta-probe', $part['body']);
    }

    public function testНесуществующаяЧастьКартыОтдаётЧетырестаЧетыре(): void
    {
        Kernel::freshCookies();

        self::assertSame(404, Kernel::get('/sitemap/9999')['status'], 'Части за пределом нет');
    }

    // --- Массовые операции -------------------------------------------------------------------

    public function testМассоваяПубликацияЧерезФорму(): void
    {
        $a = createPage(['slug' => 'mass-http-a', 'title' => 'A', 'status' => PAGES_STATUS_DRAFT]);
        $b = createPage(['slug' => 'mass-http-b', 'title' => 'B', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();

        $response = $this->press('/admin/pages', [
            'op' => 'bulk-publish',
            // HTML-форма шлёт ids[]=…; curl с ключом «ids» без скобок отправил бы скаляр,
            // и postList() получил бы пустой набор.
            'ids[]' => [(string) $a['id'], (string) $b['id']],
        ]);

        self::assertSame(302, $response['status']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById((string) $a['id'])['status']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById((string) $b['id'])['status']);
    }
    public function testКопияСтраницыОткрываетсяКакЧерновик(): void
    {
        $page = createPage(['slug' => 'kopiya-http', 'title' => 'Копия HTTP', 'status' => PAGES_STATUS_PUBLISHED]);
        $this->enter();

        $response = $this->press('/admin/pages/' . (string) $page['id'], ['op' => 'duplicate-page']);

        self::assertSame(302, $response['status']);
        $copies = all('SELECT id, status FROM ' . t('page') . ' WHERE slug LIKE ?', ['kopiya-http-copy%']);
        self::assertCount(1, $copies, 'Копия создана');
        self::assertSame(PAGES_STATUS_DRAFT, (string) $copies[0]['status'], 'Копия не публикуется сама');
    }

    // --- Буфер обмена: кнопка отмены ----------------------------------------------------------

    // У буфера есть только «вставить»: убрать скопированное можно было лишь копированием другой
    // секции. Отмена — отдельная кнопка рядом со вставкой.
    public function testЭкранСтраницыПечатаетКнопкуОтменыБуфера(): void
    {
        $page = createPage(['slug' => 'bufer-knopka', 'title' => 'Буфер', 'status' => PAGES_STATUS_DRAFT]);
        $section = createSection((string) $page['id'], ['sec_key' => 'kopiya', 'kind' => 'text', 'title' => 'Копия']);

        // Учётка из входа: слот буфера принадлежит ей, иначе блок на экране не появится.
        $user = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', ['admin@school.dev']);
        self::assertNotNull($user);
        clipboardCopy((string) $user['id'], (string) $section['id']);

        $this->enter();
        $screen = Kernel::get('/admin/pages/' . (string) $page['id']);

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Буфер обмена', $screen['body'], 'Блок буфера виден при заполненном слоте');
        self::assertStringContainsString('name="op" value="clear-clipboard"', $screen['body'], 'У буфера есть кнопка отмены');
        self::assertStringContainsString('Отменить копию', $screen['body'], 'Кнопка названа человеческим языком');
    }

    public function testОтменаКопииЧерезФормуОчищаетБуфер(): void
    {
        $page = createPage(['slug' => 'bufer-otmena', 'title' => 'Отмена', 'status' => PAGES_STATUS_DRAFT]);
        $section = createSection((string) $page['id'], ['sec_key' => 'ubrat', 'kind' => 'text', 'title' => 'Убрать']);
        $user = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', ['admin@school.dev']);
        self::assertNotNull($user);
        clipboardCopy((string) $user['id'], (string) $section['id']);

        $this->enter();
        $response = $this->press('/admin/pages/' . (string) $page['id'], ['op' => 'clear-clipboard']);

        self::assertSame(302, $response['status']);
        self::assertStringContainsString('clipboard_cleared', (string) ($response['headers']['location'] ?? ''), 'Успех уходит ключом уведомления');
        self::assertNull(clipboardGet((string) $user['id']), 'Буфер очищен');
    }

    // Кнопка есть только когда есть что отменять: пустой буфер не показывает ни вставку, ни отмену.
    public function testПустойБуферНеПечатаетБлокОтмены(): void
    {
        $page = createPage(['slug' => 'bufer-pust', 'title' => 'Пусто', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();

        $screen = Kernel::get('/admin/pages/' . (string) $page['id']);

        self::assertSame(200, $screen['status']);
        self::assertStringNotContainsString('clear-clipboard', $screen['body'], 'Пустой буфер не предлагает отмену');
    }
}
