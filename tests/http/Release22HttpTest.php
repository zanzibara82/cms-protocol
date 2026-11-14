<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.2 настоящими запросами: редакционный центр и центр уведомлений.
 *
 * Проверяется то, чего не видит unit- и db-набор: статусы, редиректы, заголовки запрета
 * индексирования, права ролей и CSRF. Редакционные сценарии (версии, согласование, блокировки,
 * расписания) проверены в db-наборе; здесь — только вход и ответы сервера.
 */
final class Release22HttpTest extends TestCase
{
    private const PASSWORD = 'длинный-тестовый-пароль';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', self::PASSWORD, 'admin');
        TestDb::seedUser('editor@school.dev', self::PASSWORD, 'editor');
        // Публичный аккаунт без редакционных прав: у него редакционных прав нет вовсе.
        TestDb::seedUser('student@school.dev', self::PASSWORD, 'student');

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach (['editorial_rates', 'editorial_notifications', 'editorial_tasks', 'editorial_comments', 'editorial_states', 'content_heads', 'content_revisions', 'editor_locks', 'editor_presence', 'scheduled_publications', 'login_attempt', 'audit_log', 'option'] as $table) {
            TestDb::clearTable($table);
        }

        // Кеш опций живёт в статике процесса: без сброса следующий тест увидел бы значения,
        // записанные предыдущим, и проверка «значение не изменилось» стала бы бутафорской.
        optionsReset();

        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page_old_slug'));
        run('DELETE FROM ' . t('page'));
    }

    private function enter(string $email = 'admin@school.dev'): void
    {
        Kernel::freshCookies();
        $page = Kernel::get('/login');
        Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => $email,
            'password' => self::PASSWORD,
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

    // --- Вход и права ---------------------------------------------------------------------

    public function testАнонимНеПопадаетВРедакциюИУведомления(): void
    {
        foreach (['/admin/editorial', '/admin/notifications'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(302, $page['status'], 'Аноним получает редирект с ' . $path);
            self::assertStringContainsString('/login', $page['headers']['location']);
        }
    }

    public function testРедакторВидитИсториюНоНеМеняетНастройкиХранения(): void
    {
        $this->enter('editor@school.dev');

        $screen = Kernel::get('/admin/editorial');
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Очередь', $screen['body'], 'Обзор редакции печатается');

        // Настройки хранения — право administer: редактор не получает успеха, значение не меняется.
        // Ответ — редирект с отказом (finishAppAction), а не голая 403: форма редакции живёт
        // страницей, и отказ показывается плашкой на ней.
        $save = $this->press('/admin/editorial', [
            'op' => 'settings-save',
            'revision_max' => '50',
            'revision_days' => '30',
        ]);
        self::assertSame(302, $save['status'], 'Редактор получает редирект с отказом');
        self::assertSame('100', (string) getOption('REVISION_MAX_PER_CONTENT', '100'), 'Значение не изменилось');
    }

    public function testАдминистраторВидитРедакциюИУведомления(): void
    {
        $this->enter();

        self::assertSame(200, Kernel::get('/admin/editorial')['status']);
        self::assertSame(200, Kernel::get('/admin/notifications')['status']);
    }

    // Экраны редакции не имеют публичных адресов: ответ закрыт от индексирования и хранения.
    public function testЭкраныРедакцииЗапрещаютИндексированиеИХранение(): void
    {
        $this->enter();

        foreach (['/admin/editorial', '/admin/notifications'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status']);
            self::assertStringContainsString('noindex', (string) ($page['headers']['x-robots-tag'] ?? ''), 'Поисковику вход закрыт с ' . $path);
            self::assertStringContainsString('no-store', (string) ($page['headers']['cache-control'] ?? ''), 'Ответ не кэшируется: ' . $path);
        }
    }

    public function testПостБезCsrfТокенаОтказывает(): void
    {
        $this->enter();

        $response = Kernel::post('/admin/editorial', ['op' => 'version', 'type' => 'page', 'id' => 'x']);

        self::assertSame(403, $response['status'], 'POST без токена отвергается');
    }

    // --- Материал, история, версия ---------------------------------------------------------

    public function testИсторияМатериалаПечатаетВерсиюИСекции(): void
    {
        $page = createPage(['slug' => 'redakciya-probe', 'title' => 'Проба редакции', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], ['sec_key' => 'tekst', 'kind' => 'text', 'title' => 'Абзац']);
        $this->enter();

        $screen = Kernel::get('/admin/editorial?type=page&id=' . (string) $page['id']);

        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Проба редакции', $screen['body'], 'Заголовок материала виден в панели');
        self::assertStringContainsString('История версий', $screen['body'], 'История печатается');
        self::assertGreaterThanOrEqual(1, contentRevisionVersion('page', (string) $page['id']), 'Базовая ревизия создана при первом открытии');
    }

    public function testПроверкаВерсииОтдаётJsonИКонфликтПриРасхождении(): void
    {
        $page = createPage(['slug' => 'versiya-probe', 'title' => 'Версия', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();
        Kernel::get('/admin/editorial?type=page&id=' . (string) $page['id']);
        $version = contentRevisionVersion('page', (string) $page['id']);

        $same = $this->press('/admin/editorial', [
            'op' => 'version',
            'type' => 'page',
            'id' => (string) $page['id'],
            'expected_version' => (string) $version,
            'format' => 'json',
        ]);
        self::assertSame(200, $same['status'], 'Совпадающая версия принимается');
        self::assertStringContainsString('application/json', (string) ($same['headers']['content-type'] ?? ''));
        self::assertStringContainsString('"ok":true', str_replace(' ', '', $same['body']));

        $stale = $this->press('/admin/editorial', [
            'op' => 'version',
            'type' => 'page',
            'id' => (string) $page['id'],
            'expected_version' => (string) ($version - 1),
            'format' => 'json',
        ]);
        self::assertSame(409, $stale['status'], 'Расхождение версии даёт конфликт');
        self::assertStringContainsString('"ok":false', str_replace(' ', '', $stale['body']));
    }

    public function testНеизвестныйМатериалОтдаётЧетырестаЧетыре(): void
    {
        $this->enter();

        self::assertSame(404, Kernel::get('/admin/editorial?type=page&id=' . str_repeat('z', 26))['status']);
    }

    // --- Комментарии и задачи --------------------------------------------------------------

    public function testКомментарийПечатаетсяИНеВиденПублично(): void
    {
        $page = createPage([
            'slug' => 'kommentariy-probe',
            'title' => 'С комментарием',
            'status' => PAGES_STATUS_PUBLISHED,
        ]);
        $this->enter();

        $saved = $this->press('/admin/editorial', [
            'op' => 'comment',
            'type' => 'page',
            'id' => (string) $page['id'],
            'body' => 'Внутренняя заметка редакции',
        ]);
        self::assertSame(302, $saved['status'], 'Комментарий сохраняется');
        self::assertStringContainsString('editorial_comment_saved', (string) ($saved['headers']['location'] ?? ''));

        $screen = Kernel::get('/admin/editorial?type=page&id=' . (string) $page['id'] . '&view=comments');
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Внутренняя заметка редакции', $screen['body']);

        // Публичная страница комментарий не показывает: он живёт только в админке.
        Kernel::freshCookies();
        $public = Kernel::get('/kommentariy-probe');
        self::assertSame(200, $public['status']);
        self::assertStringNotContainsString('Внутренняя заметка редакции', $public['body'], 'Комментарий не виден посетителю');
    }

    // --- Уведомления -----------------------------------------------------------------------

    public function testЦентрУведомленийПечатаетИОтмечаетПрочитанным(): void
    {
        $this->enter();
        $user = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', ['admin@school.dev']);
        self::assertNotNull($user);

        $created = editorialNotify(
            (string) $user['id'],
            'comment',
            'page',
            'm1',
            'Новый комментарий',
            'Вас упомянули в комментарии.',
            '/admin/editorial?type=page&id=m1&view=comments'
        );
        self::assertTrue($created['ok'], 'Уведомление создаётся: ' . $created['error']);

        $screen = Kernel::get('/admin/notifications');
        self::assertSame(200, $screen['status']);
        self::assertStringContainsString('Новый комментарий', $screen['body']);
        self::assertSame(1, editorialNotificationUnread((string) $user['id']), 'Уведомление непрочитано');

        $read = $this->press('/admin/notifications', ['op' => 'read', 'notification' => $created['id']]);
        self::assertSame(302, $read['status']);
        self::assertSame(0, editorialNotificationUnread((string) $user['id']), 'Уведомление прочитано');
    }

    // Внешний адрес в уведомлении отбрасывается: переход возможен только на внутренний адрес.
    public function testВнешнийАдресВУведомленииОтбрасывается(): void
    {
        $this->enter();
        $user = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', ['admin@school.dev']);
        self::assertNotNull($user);

        $created = editorialNotify(
            (string) $user['id'],
            'system',
            'page',
            'm2',
            'Внешняя ссылка',
            'Проверка фильтра.',
            'https://example.com/evil'
        );
        self::assertTrue($created['ok']);

        $row = one('SELECT url FROM ' . t('editorial_notifications') . ' WHERE id = ?', [$created['id']]);
        self::assertNotNull($row);
        self::assertSame('', (string) $row['url'], 'Внешний адрес не сохраняется');
    }

    // --- Расписание ------------------------------------------------------------------------

    public function testРасписаниеПубликацииСоздаётсяИОтменяется(): void
    {
        $page = createPage(['slug' => 'raspisanie-probe', 'title' => 'По расписанию', 'status' => PAGES_STATUS_DRAFT]);
        $this->enter();
        Kernel::get('/admin/editorial?type=page&id=' . (string) $page['id']);

        // Поле расписания — местное время выбранной зоны, а не UTC: Москва = UTC+3 круглый год,
        // поэтому «через час по Москве» — это time() + 1 час + 3 часа смещения.
        $create = $this->press('/admin/editorial', [
            'op' => 'schedule-create',
            'type' => 'page',
            'id' => (string) $page['id'],
            'action' => 'publish',
            'publish_at' => gmdate('Y-m-d\TH:i', time() + 3600 + 3 * 3600),
            'timezone' => 'Europe/Moscow',
        ]);
        self::assertSame(302, $create['status']);
        $rows = scheduledPublicationFor('page', (string) $page['id']);
        self::assertNotEmpty($rows, 'Расписание создано');
        $scheduleId = (string) $rows[0]['id'];

        $cancel = $this->press('/admin/editorial', [
            'op' => 'schedule-cancel',
            'type' => 'page',
            'id' => (string) $page['id'],
            'schedule' => $scheduleId,
        ]);
        self::assertSame(302, $cancel['status']);
        $after = scheduledPublicationFor('page', (string) $page['id']);
        self::assertSame([], array_values(array_filter($after, static fn (array $row): bool => (string) $row['status'] === 'pending')), 'Ожидающее расписание отменено');
    }

    // --- Настройки хранения ----------------------------------------------------------------

    public function testАдминистраторМеняетНастройкиХранения(): void
    {
        $this->enter();

        $save = $this->press('/admin/editorial', [
            'op' => 'settings-save',
            'revision_max' => '50',
            'revision_days' => '30',
        ]);

        self::assertSame(302, $save['status']);
        self::assertSame('50', (string) getOption('REVISION_MAX_PER_CONTENT', '100'));
        self::assertSame('30', (string) getOption('REVISION_RETENTION_DAYS', '365'));
    }

    public function testНастройкиХраненияВнеПределовОтказывают(): void
    {
        $this->enter();

        $this->press('/admin/editorial', ['op' => 'settings-save', 'revision_max' => '5', 'revision_days' => '30']);

        self::assertSame('100', (string) getOption('REVISION_MAX_PER_CONTENT', '100'), 'Слишком малое значение не принято');
    }
}
