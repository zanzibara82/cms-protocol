<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.2 на живой базе: отложенная публикация, повторные попытки, отмена расписания,
 * напоминания о задачах, ежедневная уборка и CLI-вход планировщика.
 */
final class Release22SchedulerTest extends TestCase
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
        deleteOption('REVISION_MAX_PER_CONTENT');
        deleteOption('REVISION_RETENTION_DAYS');
    }

    protected function tearDown(): void
    {
        deleteOption('REVISION_MAX_PER_CONTENT');
        deleteOption('REVISION_RETENTION_DAYS');
    }

    // --- Расписание --------------------------------------------------------------------------

    public function testРасписаниеСоздаётсяТолькоСПравомИЗаменяетПрежнее(): void
    {
        $page = $this->page('plan');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);
        $editor = $this->user('editor@example.test', ROLES['editor']);

        $denied = scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $editor, $this->now);
        self::assertFalse($denied['ok']);
        self::assertStringContainsString('Недостаточно прав', $denied['error']);

        $first = scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $admin, $this->now);
        self::assertTrue($first['ok'], $first['error']);
        self::assertNotSame('', $first['id']);
        self::assertSame('scheduled', editorialState('page', $id)['status']);
        self::assertSame($first['publish_at'], (string) editorialState('page', $id)['scheduled_for']);

        self::assertCount(1, scheduledPublicationList('pending'));
        self::assertCount(1, scheduledPublicationFor('page', $id));

        $second = scheduledPublicationCreate('page', $id, 'publish', $this->when('+2 hours'), 'UTC', $admin, $this->now->modify('+1 minute'));
        self::assertTrue($second['ok'], $second['error']);

        $pending = scheduledPublicationList('pending');
        self::assertCount(1, $pending, 'Одно действие — одна ожидающая задача');
        self::assertSame($second['id'], (string) $pending[0]['id']);
        self::assertCount(1, scheduledPublicationList('cancelled'), 'Прежнее расписание отменено');
        self::assertSame($second['publish_at'], (string) editorialState('page', $id)['scheduled_for']);
    }

    public function testОтменаРасписанияВозвращаетСогласование(): void
    {
        $page = $this->page('otmena');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);
        $editor = $this->user('editor@example.test', ROLES['editor']);

        $created = scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $admin, $this->now);
        self::assertTrue($created['ok'], $created['error']);

        $denied = scheduledPublicationCancel((string) $created['id'], $editor, $this->now);
        self::assertFalse($denied['ok']);

        $cancelled = scheduledPublicationCancel((string) $created['id'], $admin, $this->now->modify('+1 minute'));
        self::assertTrue($cancelled['ok'], $cancelled['error']);
        self::assertSame('approved', editorialState('page', $id)['status']);
        self::assertNull(editorialState('page', $id)['scheduled_for']);

        $again = scheduledPublicationCancel((string) $created['id'], $admin, $this->now);
        self::assertFalse($again['ok'], 'Повторная отмена уже отменённого расписания невозможна');
        self::assertFalse(scheduledPublicationCancel('missing-schedule', $admin, $this->now)['ok']);
    }

    public function testПланировщикПубликуетВСрокИПовторноНеСрабатывает(): void
    {
        $page = $this->page('publikaciya');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);

        $created = scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $admin, $this->now);
        self::assertTrue($created['ok'], $created['error']);

        $early = scheduledPublicationRun(50, $this->now->modify('+30 minutes'));
        self::assertSame(0, $early['processed'], 'До срока задача не выполняется');
        self::assertSame(PAGES_STATUS_DRAFT, (string) pageById($id)['status']);

        $onTime = scheduledPublicationRun(50, $this->now->modify('+1 hour'));
        self::assertSame(1, $onTime['processed']);
        self::assertSame(1, $onTime['published']);
        self::assertSame([], $onTime['errors']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById($id)['status']);
        self::assertSame('published', editorialState('page', $id)['status']);
        self::assertSame('done', (string) scheduledPublicationList('done')[0]['status']);
        self::assertSame('publish', (string) contentRevisionLatest('page', $id)['operation']);

        $again = scheduledPublicationRun(50, $this->now->modify('+2 hours'));
        self::assertSame(0, $again['processed'], 'Выполненная задача повторно не берётся');
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('scheduled_publications') . ' WHERE content_id = ? AND status = ?', [$id, 'done']));
    }

    public function testПробныйПрогонНичегоНеМеняет(): void
    {
        $page = $this->page('probnyy');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);

        self::assertTrue(scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $admin, $this->now)['ok']);

        $dry = scheduledPublicationRun(50, $this->now->modify('+1 hour'), true);
        self::assertSame(1, $dry['processed']);
        self::assertSame(1, $dry['published']);
        self::assertSame(PAGES_STATUS_DRAFT, (string) pageById($id)['status'], 'Пробный прогон не публикует');
        self::assertSame('pending', (string) scheduledPublicationList('pending')[0]['status']);
    }

    // --- Повторные попытки -------------------------------------------------------------------

    public function testОшибкаПубликацииПовторяетсяИФиксируетсяВЖурнале(): void
    {
        $page = $this->page('povtor');
        $id = (string) $page['id'];
        $admin = $this->user('admin@example.test', ROLES['admin']);

        $created = scheduledPublicationCreate('page', $id, 'publish', $this->when('+1 hour'), 'UTC', $admin, $this->now);
        self::assertTrue($created['ok'], $created['error']);

        deletePage($id);
        self::assertNull(pageById($id));

        $first = scheduledPublicationRun(50, $this->now->modify('+1 hour'));
        self::assertSame(1, $first['failed']);
        self::assertSame(1, $first['retried']);
        $row = one('SELECT status, attempts, next_attempt_at, last_error FROM ' . t('scheduled_publications') . ' WHERE id = ?', [(string) $created['id']]);
        self::assertNotNull($row);
        self::assertSame('pending', (string) $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('Материал не найден.', (string) $row['last_error']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertCount(1, editorialNotifications($admin['id'], 'published'), 'Первая ошибка уведомляет автора расписания');

        $second = scheduledPublicationRun(50, $this->now->modify('+1 hour + 301 seconds'));
        self::assertSame(1, $second['failed']);
        self::assertSame(1, $second['retried']);
        $row = one('SELECT status, attempts, next_attempt_at FROM ' . t('scheduled_publications') . ' WHERE id = ?', [(string) $created['id']]);
        self::assertNotNull($row);
        self::assertSame('pending', (string) $row['status']);
        self::assertSame(2, (int) $row['attempts']);

        $third = scheduledPublicationRun(50, $this->now->modify('+1 hour + 901 seconds'));
        self::assertSame(1, $third['failed']);
        self::assertSame(0, $third['retried'], 'Третья попытка — последняя');
        $row = one('SELECT status, attempts, next_attempt_at FROM ' . t('scheduled_publications') . ' WHERE id = ?', [(string) $created['id']]);
        self::assertNotNull($row);
        self::assertSame('failed', (string) $row['status']);
        self::assertSame(SCHEDULE_MAX_ATTEMPTS, (int) $row['attempts']);
        self::assertNull($row['next_attempt_at']);
        self::assertCount(2, editorialNotifications($admin['id'], 'published'), 'Финальная ошибка тоже уведомляет');

        $after = scheduledPublicationRun(50, $this->now->modify('+3 hours'));
        self::assertSame(0, $after['processed'], 'Упавшая задача больше не берётся');
    }

    // --- Напоминания и уборка ----------------------------------------------------------------

    public function testНапоминаниеОСрокеЗадачиПриходитРазВДень(): void
    {
        $page = $this->page('napominanie');
        $id = (string) $page['id'];
        $author = $this->user('author@example.test', ROLES['editor']);
        $assignee = $this->user('assignee@example.test', ROLES['editor']);

        $task = editorialTaskCreate(
            'page',
            $id,
            'Сдать текст',
            $assignee['id'],
            $author,
            '',
            $this->now->modify('+30 minutes'),
            $this->now
        );
        self::assertTrue($task['ok'], $task['error']);

        self::assertSame(1, scheduledTaskDueNotifications($this->now));
        self::assertSame(0, scheduledTaskDueNotifications($this->now->modify('+1 minute')), 'Второе напоминание в тот же день не уходит');
        self::assertCount(1, editorialNotifications($assignee['id'], 'task_due'));
        self::assertSame(1, scheduledTaskDueNotifications($this->now->modify('+25 hours')), 'На следующий день напоминание повторяется');
    }

    public function testУборкаСнимаетИстекшиеБлокировкиИСтарыеУведомления(): void
    {
        $page = $this->page('uborka');
        $id = (string) $page['id'];
        $editor = $this->user('editor@example.test', ROLES['editor']);

        self::assertTrue(editorialLockAcquire('page', $id, 'session-a', $editor, false, $this->now)['ok']);
        self::assertTrue(editorialPresenceTouch('page', $id, $editor, 'session-a', $this->now)['ok']);
        $note = editorialNotify($editor['id'], 'task', 'page', $id, 'Старое', '', '/admin/editorial', $this->now->modify('-100 days'));
        self::assertTrue(editorialNotificationRead($editor['id'], (string) $note['id'], $this->now->modify('-100 days')));

        $report = scheduledMaintenance($this->now->modify('+10 minutes'));

        self::assertSame(1, $report['locks'], 'Просроченная блокировка снимается');
        self::assertSame(0, $report['presence'], 'Свежее присутствие уборка не трогает');
        self::assertSame(1, $report['notifications'], 'Прочитанное старое уведомление удаляется');
        self::assertNull(editorialLockState('page', $id, $this->now));

        $presenceReport = scheduledMaintenance($this->now->modify('+1 day'));
        self::assertSame(1, $presenceReport['presence'], 'Давно не поднимавшееся присутствие удаляется');
    }

    // --- CLI ---------------------------------------------------------------------------------

    public function testПланировщикЗапускаетсяКакCLIКоманда(): void
    {
        $script = dirname(__DIR__, 2) . '/bin/cron.php';
        self::assertFileExists($script);

        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help 2>&1', $output, $code);

        self::assertSame(0, $code, 'Справка планировщика обязана завершаться успешно: ' . implode("\n", $output));
        self::assertStringContainsString('bin/cron.php', implode("\n", $output));

        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --schedules --dry-run 2>&1', $output, $code);

        self::assertSame(0, $code, 'Пробный прогон планировщика обязан завершаться успешно: ' . implode("\n", $output));
        $report = json_decode(implode("\n", $output), true);
        self::assertIsArray($report, 'Планировщик обязан печатать JSON: ' . implode("\n", $output));
        self::assertTrue((bool) ($report['ok'] ?? false));
        self::assertTrue((bool) ($report['dry_run'] ?? false));
    }

    // --- Помощники ---------------------------------------------------------------------------

    private function when(string $modifier): string
    {
        return $this->now->modify($modifier)->format('Y-m-d\TH:i');
    }

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
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now);

        self::assertTrue($page['ok'], $page['error']);

        return $page;
    }
}
