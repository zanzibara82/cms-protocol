<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.2 на живой базе: права ролей, согласование, блокировки с heartbeat, присутствие,
 * комментарии с упоминаниями, уведомления, задачи и ограничение частоты.
 */
final class Release22EditorialTest extends TestCase
{
    private DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-05-01 10:00:00', new DateTimeZone('UTC'));

        foreach ([
            'content_revisions', 'content_heads', 'editorial_states', 'editor_locks',
            'editor_presence', 'editorial_comments', 'editorial_tasks', 'editorial_notifications',
            'scheduled_publications', 'editorial_rates', 'audit_log',
        ] as $table) {
            TestDb::clearTable($table);
        }

        run('DELETE FROM ' . t('page_old_slug'));
        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page'));
        run('DELETE FROM ' . t('user'));
    }

    // --- Права -------------------------------------------------------------------------------

    public function testПраваРолейПроверяютсяНаСервере(): void
    {
        $admin = $this->user('admin@example.test', ROLES['admin']);
        $editor = $this->user('editor@example.test', ROLES['editor']);

        self::assertSame(array_keys(EDITORIAL_RIGHT_LABELS), editorialRights($admin));
        self::assertTrue(editorialCan('publish', $admin));
        self::assertTrue(editorialCan('delete_revision', $admin));

        foreach (['view_history', 'view_diff', 'restore', 'autosave', 'edit', 'lock', 'unlock', 'submit', 'view_comments', 'comment', 'manage_tasks'] as $right) {
            self::assertTrue(editorialCan($right, $editor), 'Редактору обязано быть доступно право ' . $right);
        }

        foreach (['approve', 'publish', 'schedule', 'delete_revision'] as $right) {
            self::assertFalse(editorialCan($right, $editor), 'Редактору обязано быть запрещено право ' . $right);
        }

        $blocked = $editor;
        $blocked['blocked_at'] = '2026-01-01 00:00:00';
        self::assertSame([], editorialRights($blocked), 'Заблокированный пользователь не имеет прав');

        $stranger = $editor;
        $stranger['role'] = 'viewer';
        self::assertSame([], editorialRights($stranger), 'Неизвестная роль не получает редакционных прав');
    }

    // --- Согласование ------------------------------------------------------------------------

    public function testСогласованиеИдетПоСервернымПравилам(): void
    {
        $page = $this->page('soglasovanie');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);
        $editor = $this->user('editor@example.test', ROLES['editor']);
        $other = $this->user('other@example.test', ROLES['editor']);

        $submitted = editorialTransition('page', $id, 'submit', ['user' => $editor], $this->now);
        self::assertTrue($submitted['ok'], $submitted['error']);
        self::assertSame('review', editorialState('page', $id)['status']);
        self::assertSame($editor['id'], (string) editorialState('page', $id)['submitted_by']);
        self::assertSame('editorial.submit', (string) value(
            'SELECT action FROM ' . t('audit_log') . ' WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 1',
            ['page', $id]
        ));

        self::assertCount(1, editorialNotifications($admin['id'], 'review_requested'), 'Администратору приходит заявка на проверку');
        self::assertSame([], editorialNotifications($other['id'], 'review_requested'), 'Второй редактор не получает чужую заявку');

        $denied = editorialTransition('page', $id, 'approve', ['user' => $editor], $this->now);
        self::assertFalse($denied['ok']);
        self::assertStringContainsString('Недостаточно прав', $denied['error']);
        self::assertSame('review', editorialState('page', $id)['status'], 'Отказ не меняет состояние');

        $approved = editorialTransition('page', $id, 'approve', ['user' => $admin], $this->now);
        self::assertTrue($approved['ok'], $approved['error']);
        self::assertSame('approved', editorialState('page', $id)['status']);
        self::assertCount(1, editorialNotifications($editor['id'], 'approved'), 'Автору приходит решение');

        $denied = editorialTransition('page', $id, 'publish', ['user' => $editor], $this->now);
        self::assertFalse($denied['ok']);

        $published = editorialTransition('page', $id, 'publish', ['user' => $admin], $this->now);
        self::assertTrue($published['ok'], $published['error']);
        self::assertSame('published', editorialState('page', $id)['status']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById($id)['status'], 'Публикация меняет статус материала');
        self::assertSame(2, contentRevisionVersion('page', $id));
        self::assertSame('publish', (string) contentRevisionLatest('page', $id)['operation']);

        $unpublished = editorialTransition('page', $id, 'unpublish', ['user' => $admin], $this->now);
        self::assertTrue($unpublished['ok'], $unpublished['error']);
        self::assertSame('unpublished', editorialState('page', $id)['status']);
        self::assertSame(PAGES_STATUS_DRAFT, (string) pageById($id)['status']);
        self::assertSame(3, contentRevisionVersion('page', $id));

        $notified = editorialNotifications($other['id'], 'published');
        self::assertNotEmpty($notified, 'Команда получает уведомление о публикации');
    }

    public function testПубликацияСУстаревшейВерсиейВозвращаетКонфликт(): void
    {
        $page = $this->page('konflikt-versii');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);

        $result = editorialTransition('page', $id, 'publish', ['user' => $admin, 'expected_version' => 0], $this->now);

        self::assertFalse($result['ok']);
        self::assertArrayHasKey('conflict', $result);
        self::assertSame(0, $result['conflict']['expected']);
        self::assertSame(1, $result['conflict']['actual']);
        self::assertSame(PAGES_STATUS_DRAFT, (string) pageById($id)['status'], 'Отказ не публикует материал');
        self::assertSame('draft', editorialState('page', $id)['status']);
    }

    public function testНедопустимыйПереходОтказывает(): void
    {
        $page = $this->page('perehod');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);

        $result = editorialTransition('page', $id, 'approve', ['user' => $admin], $this->now);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('недоступно', $result['error']);

        self::assertFalse(editorialTransition('page', $id, 'unknown', ['user' => $admin], $this->now)['ok']);
    }

    // --- Блокировки --------------------------------------------------------------------------

    public function testБлокировкаПринадлежитПользователюИСессии(): void
    {
        $page = $this->page('blokirovka');
        $id = (string) $page['id'];
        $editor = $this->user('editor@example.test', ROLES['editor']);
        $other = $this->user('other@example.test', ROLES['editor']);

        $acquired = editorialLockAcquire('page', $id, 'session-a', $editor, false, $this->now);
        self::assertTrue($acquired['ok'], $acquired['error']);
        self::assertNotSame('', $acquired['token']);
        self::assertNotNull(editorialLockState('page', $id, $this->now));
        self::assertCount(1, editorialActiveLocks(50, $this->now));

        $busy = editorialLockAcquire('page', $id, 'session-b', $other, false, $this->now);
        self::assertFalse($busy['ok']);
        self::assertStringContainsString('редактирует', $busy['error']);
        self::assertSame($editor['id'], $busy['lock']['user_id']);

        self::assertSame('', editorialLockBlocks('page', $id, $editor, $this->now, 'session-a'), 'Своя сессия не блокируется');
        self::assertNotSame('', editorialLockBlocks('page', $id, $other, $this->now, 'session-b'), 'Чужой редактор блокируется');
        self::assertNotSame('', editorialLockBlocks('page', $id, $editor, $this->now, 'session-b'), 'Другая вкладка того же пользователя блокируется');

        $wrong = editorialLockHeartbeat('page', $id, 'wrong-token', 'session-a', $this->now->modify('+10 seconds'));
        self::assertFalse($wrong['ok']);

        $foreignTab = editorialLockHeartbeat('page', $id, $acquired['token'], 'session-b', $this->now->modify('+10 seconds'));
        self::assertFalse($foreignTab['ok']);
        self::assertStringContainsString('другой вкладке', $foreignTab['error']);

        $beat = editorialLockHeartbeat('page', $id, $acquired['token'], 'session-a', $this->now->modify('+10 seconds'));
        self::assertTrue($beat['ok'], $beat['error']);
        self::assertNotSame($acquired['expires_at'], $beat['expires_at']);

        $foreignRelease = editorialLockRelease('page', $id, $acquired['token'], $other, false, $this->now);
        self::assertFalse($foreignRelease['ok']);

        $wrongToken = editorialLockRelease('page', $id, 'wrong-token', $editor, false, $this->now);
        self::assertFalse($wrongToken['ok']);
        self::assertStringContainsString('другой вкладке', $wrongToken['error']);

        $released = editorialLockRelease('page', $id, $acquired['token'], $editor, false, $this->now);
        self::assertTrue($released['ok'], $released['error']);
        self::assertNull(editorialLockState('page', $id, $this->now));

        $second = editorialLockAcquire('page', $id, 'session-b', $other, false, $this->now);
        self::assertTrue($second['ok'], $second['error']);
        self::assertTrue(editorialLockRelease('page', $id, '', $editor, true, $this->now)['ok'], 'Администратор снимает блокировку силой');
        self::assertNull(editorialLockState('page', $id, $this->now));
    }

    public function testИстекшаяБлокировкаОсвобождаетМатериал(): void
    {
        $page = $this->page('istek');
        $id = (string) $page['id'];
        $editor = $this->user('editor@example.test', ROLES['editor']);
        $other = $this->user('other@example.test', ROLES['editor']);

        $acquired = editorialLockAcquire('page', $id, 'session-a', $editor, false, $this->now);
        self::assertTrue($acquired['ok']);

        $afterExpiry = $this->now->modify('+' . (EDITOR_LOCK_TTL_SECONDS + 1) . ' seconds');
        self::assertNull(editorialLockState('page', $id, $afterExpiry));

        $second = editorialLockAcquire('page', $id, 'session-b', $other, false, $afterExpiry);
        self::assertTrue($second['ok'], 'Просроченная блокировка не мешает взять новую');
    }

    // --- Присутствие -------------------------------------------------------------------------

    public function testПрисутствиеСводитВкладкиОдногоПользователя(): void
    {
        $page = $this->page('prisutstvie');
        $id = (string) $page['id'];
        $editor = $this->user('editor@example.test', ROLES['editor']);
        $other = $this->user('other@example.test', ROLES['editor']);

        self::assertTrue(editorialPresenceTouch('page', $id, $editor, 'session-a', $this->now)['ok']);
        self::assertTrue(editorialPresenceTouch('page', $id, $editor, 'session-b', $this->now->modify('+30 seconds'))['ok']);
        self::assertTrue(editorialPresenceTouch('page', $id, $other, 'session-c', $this->now->modify('+20 seconds'))['ok']);

        $active = editorialPresenceList('page', $id, $this->now->modify('+60 seconds'));
        self::assertCount(2, $active, 'Две вкладки одного редактора — одна строка присутствия');
        self::assertSame($editor['id'], (string) $active[0]['user_id']);
        self::assertSame(utcStamp($this->now), (string) $active[0]['entered_at']);

        self::assertSame([], editorialPresenceList('page', $id, $this->now->modify('+' . (30 + EDITOR_PRESENCE_TTL_SECONDS + 1) . ' seconds')));
    }

    // --- Комментарии и упоминания ------------------------------------------------------------

    public function testКомментарииУпоминанияИОтветы(): void
    {
        $page = $this->page('kommentarii');
        $id = (string) $page['id'];
        $author = $this->user('author@example.test', ROLES['editor']);
        $mentioned = $this->user('mentioned@example.test', ROLES['editor']);

        $comment = editorialCommentCreate(
            'page',
            $id,
            'Проверьте абзац, @' . $mentioned['email'] . '. Спасибо!',
            $author,
            '',
            '',
            $this->now
        );

        self::assertTrue($comment['ok'], $comment['error']);
        self::assertSame([$mentioned['id']], $comment['mentions']);
        self::assertCount(1, editorialNotifications($mentioned['id'], 'mention'));
        self::assertSame([], editorialNotifications($author['id'], 'mention'), 'Себя упоминать уведомлением не нужно');

        $reply = editorialCommentCreate('page', $id, 'Поправил.', $mentioned, (string) $comment['id'], '', $this->now->modify('+1 minute'));
        self::assertTrue($reply['ok'], $reply['error']);
        self::assertCount(1, editorialNotifications($author['id'], 'comment'), 'Ответ уведомляет автора ветки');
        self::assertCount(0, editorialNotifications($mentioned['id'], 'comment'), 'Свой ответ не уведомляет самого автора');

        $list = editorialCommentList('page', $id, true, 100);
        self::assertCount(2, $list);
        self::assertSame([$mentioned['id']], $list[0]['mentions'], 'Упоминания читаются обратно из JSON');

        self::assertTrue(editorialCommentResolve((string) $comment['id'], true, $author, $this->now)['ok']);
        self::assertCount(1, editorialCommentList('page', $id, false, 100), 'Закрытая ветка скрыта из активных');
        self::assertCount(2, editorialCommentList('page', $id, true, 100));

        self::assertTrue(editorialCommentResolve((string) $comment['id'], false, $author, $this->now)['ok']);
        self::assertCount(2, editorialCommentList('page', $id, false, 100));

        self::assertFalse(editorialCommentResolve('missing-comment', true, $author, $this->now)['ok']);
    }

    public function testКомментарийПроверяетТекстРодителяИСекцию(): void
    {
        $page = $this->page('proverka-kommentariya');
        $id = (string) $page['id'];
        $author = $this->user('author@example.test', ROLES['editor']);

        self::assertFalse(editorialCommentCreate('page', $id, '   ', $author, '', '', $this->now)['ok']);
        self::assertFalse(editorialCommentCreate('page', $id, str_repeat('а', EDITORIAL_COMMENT_MAX + 1), $author, '', '', $this->now)['ok']);

        $first = editorialCommentCreate('page', $id, 'Первый', $author, '', '', $this->now);
        self::assertTrue($first['ok']);

        $otherPage = $this->page('drugaya-stranitsa');
        $foreign = editorialCommentCreate('page', (string) $otherPage['id'], 'Чужой', $author, '', '', $this->now);
        self::assertTrue($foreign['ok']);

        $wrongParent = editorialCommentCreate('page', $id, 'Ответ', $author, (string) $foreign['id'], '', $this->now);
        self::assertFalse($wrongParent['ok']);
        self::assertStringContainsString('другого материала', $wrongParent['error']);

        $wrongSection = editorialCommentCreate('page', $id, 'К секции', $author, '', 'missing-section', $this->now);
        self::assertFalse($wrongSection['ok']);
        self::assertStringContainsString('уже удалена', $wrongSection['error']);

        $section = createSection($id, ['sec_key' => 'blok', 'kind' => 'text', 'title' => 'Блок'], $this->now);
        self::assertTrue($section['ok'], $section['error']);
        $attached = editorialCommentCreate('page', $id, 'К блоку', $author, '', (string) $section['id'], $this->now);
        self::assertTrue($attached['ok'], $attached['error']);
    }

    // --- Уведомления -------------------------------------------------------------------------

    public function testЦентрУведомленийЧитаетФильтруетИЗакрывает(): void
    {
        $user = $this->user('notify@example.test', ROLES['editor']);
        $other = $this->user('other@example.test', ROLES['editor']);
        $page = $this->page('uvedomleniya');
        $id = (string) $page['id'];

        $first = editorialNotify($user['id'], 'task', 'page', $id, 'Задача', 'Проверьте текст', '/admin/editorial?type=page&id=' . $id, $this->now);
        self::assertTrue($first['ok'], $first['error']);
        editorialNotify($user['id'], 'mention', 'page', $id, 'Упоминание', 'Вас упомянули', '/admin/notifications', $this->now->modify('+1 minute'));

        self::assertSame(2, editorialNotificationUnread($user['id']));
        self::assertCount(2, editorialNotifications($user['id']));
        self::assertCount(1, editorialNotifications($user['id'], 'mention'));

        self::assertFalse(editorialNotificationRead($other['id'], (string) $first['id']), 'Чужое уведомление не читается');
        self::assertTrue(editorialNotificationRead($user['id'], (string) $first['id'], $this->now));
        self::assertSame(1, editorialNotificationUnread($user['id']));
        self::assertCount(1, editorialNotifications($user['id'], '', true));

        self::assertSame(1, editorialNotificationReadAll($user['id'], $this->now));
        self::assertSame(0, editorialNotificationUnread($user['id']));

        $external = editorialNotify($user['id'], 'published', 'page', $id, 'Внешний', '', 'https://evil.example/x', $this->now);
        self::assertTrue($external['ok']);
        $row = one('SELECT url FROM ' . t('editorial_notifications') . ' WHERE id = ?', [(string) $external['id']]);
        self::assertNotNull($row);
        self::assertSame('', (string) $row['url'], 'Внешний адрес в уведомление не попадает');

        $protocolRelative = editorialNotify($user['id'], 'published', 'page', $id, 'Сетевой', '', '//evil.example/x', $this->now);
        $row = one('SELECT url FROM ' . t('editorial_notifications') . ' WHERE id = ?', [(string) $protocolRelative['id']]);
        self::assertNotNull($row);
        self::assertSame('', (string) $row['url']);
    }

    // --- Задачи ------------------------------------------------------------------------------

    public function testЗадачаНазначаетсяИЗакрывается(): void
    {
        $page = $this->page('zadachi');
        $id = (string) $page['id'];
        $author = $this->user('author@example.test', ROLES['editor']);
        $assignee = $this->user('assignee@example.test', ROLES['editor']);

        $task = editorialTaskCreate(
            'page',
            $id,
            'Проверить факты',
            $assignee['id'],
            $author,
            'Особенно второй абзац',
            $this->now->modify('+1 hour'),
            $this->now
        );

        self::assertTrue($task['ok'], $task['error']);
        self::assertCount(1, editorialNotifications($assignee['id'], 'task'));
        self::assertCount(1, editorialTasks('page', $id, true));
        self::assertSame('open', (string) editorialTasks('page', $id)[0]['status']);

        $status = editorialTaskStatus((string) $task['id'], 'done', $author, $this->now->modify('+2 hours'));
        self::assertTrue($status['ok'], $status['error']);
        self::assertCount(0, editorialTasks('page', $id, true), 'Закрытая задача не попадает в открытые');
        self::assertSame('done', (string) editorialTasks('page', $id)[0]['status']);
        self::assertNotSame('', (string) editorialTasks('page', $id)[0]['completed_at']);

        self::assertFalse(editorialTaskStatus((string) $task['id'], 'unknown', $author, $this->now)['ok']);
        self::assertFalse(editorialTaskCreate('page', $id, '', $assignee['id'], $author, '', null, $this->now)['ok']);
        self::assertFalse(editorialTaskCreate('page', $id, 'Без исполнителя', 'missing-user', $author, '', null, $this->now)['ok']);
    }

    // --- Очередь и частота -------------------------------------------------------------------

    public function testОчередьИЛентаПоказываютМатериалПослеПерехода(): void
    {
        $page = $this->page('ochered');
        $id = (string) $page['id'];
        $editor = $this->user('editor@example.test', ROLES['editor']);

        self::assertTrue(editorialTransition('page', $id, 'submit', ['user' => $editor], $this->now)['ok']);

        $queue = editorialQueue(10);
        self::assertNotEmpty($queue);
        self::assertSame($id, (string) $queue[0]['content_id']);
        self::assertSame('На проверке', (string) $queue[0]['state_label']);

        $recent = editorialRecentRevisions(10);
        self::assertNotEmpty($recent);
        self::assertSame($id, (string) $recent[0]['content_id']);
        self::assertStringContainsString('/admin/editorial?', (string) $recent[0]['editor_url']);
    }

    public function testОграничениеЧастотыСчитаетПоПользователюИОкну(): void
    {
        $user = $this->user('rate@example.test', ROLES['editor']);
        $other = $this->user('other-rate@example.test', ROLES['editor']);

        self::assertTrue(editorialRateAllow('comment', 2, 300, $user, $this->now));
        self::assertTrue(editorialRateAllow('comment', 2, 300, $user, $this->now));
        self::assertFalse(editorialRateAllow('comment', 2, 300, $user, $this->now), 'Третья попытка в окне запрещена');
        self::assertTrue(editorialRateAllow('comment', 2, 300, $other, $this->now), 'Лимит считается по пользователю');
        self::assertTrue(editorialRateAllow('comment', 2, 300, $user, $this->now->modify('+301 seconds')), 'Новое окно снова разрешает');
    }

    // --- Помощники ---------------------------------------------------------------------------

    /** @return array{id: string, email: string, name: string, role: string} */
    private function user(string $email, string $role): array
    {
        $id = TestDb::seedUser($email, 'test-password-only', $role);

        return ['id' => $id, 'email' => $email, 'name' => 'Тестовый редактор', 'role' => $role];
    }

    /** @return array<string, mixed> */
    private function page(string $slug): array
    {
        $page = createPage([
            'slug' => $slug,
            'title' => 'Материал ' . $slug,
            'description' => 'Описание',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now);

        self::assertTrue($page['ok'], $page['error']);

        return $page;
    }
}
