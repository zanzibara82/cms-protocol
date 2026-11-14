<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.2 на живой базе: ревизии, голова версии, идемпотентность, конфликты, восстановление,
 * удаление и уборка. Проверяются свойства, которые видны только в базе: уникальность версии,
 * родительская цепочка, нетронутые публикации и сохранение идентификаторов секций.
 */
final class Release22RevisionsTest extends TestCase
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
        deleteOption('REVISION_MAX_PER_CONTENT');
        deleteOption('REVISION_RETENTION_DAYS');
    }

    protected function tearDown(): void
    {
        deleteOption('REVISION_MAX_PER_CONTENT');
        deleteOption('REVISION_RETENTION_DAYS');
    }

    // --- Схема -------------------------------------------------------------------------------

    public function testТаблицыРевизийСозданыМиграцией(): void
    {
        foreach ([
            'content_revisions', 'content_heads', 'editor_locks', 'editor_presence',
            'editorial_states', 'editorial_comments', 'editorial_tasks', 'editorial_notifications',
            'scheduled_publications', 'editorial_rates',
        ] as $table) {
            self::assertTrue(tableExists($table), 'Миграция RELEASE 2.2 не создала таблицу ' . $table);
        }

        self::assertTrue(contentRevisionTablesReady());
    }

    // --- Создание и ручное сохранение ---------------------------------------------------------

    public function testСозданиеСтраницыПишетПервуюРевизию(): void
    {
        $page = createPage([
            'slug' => 'istoriya',
            'title' => 'История',
            'description' => 'Описание',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now);

        self::assertTrue($page['ok'], $page['error']);
        self::assertSame(1, contentRevisionVersion('page', (string) $page['id']));

        $latest = contentRevisionLatest('page', (string) $page['id']);
        self::assertNotNull($latest);
        self::assertSame('initial', (string) $latest['operation']);
        self::assertSame(1, (int) $latest['version']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('content_heads') . ' WHERE content_type = ? AND content_id = ?',
            ['page', (string) $page['id']]
        ), 'Голова обязана существовать ровно в одном экземпляре');
    }

    public function testРучноеСохранениеПоднимаетВерсиюИПишетСнимок(): void
    {
        $page = $this->page('redaktirovanie', 'Старый заголовок');

        $updated = updatePage((string) $page['id'], [
            'slug' => 'redaktirovanie',
            'title' => 'Новый заголовок',
            'description' => 'Новое описание',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => '1',
        ], $this->now->modify('+1 minute'), ['expected_version' => 1]);

        self::assertTrue($updated['ok'], $updated['error']);
        self::assertSame(2, contentRevisionVersion('page', (string) $page['id']));
        self::assertSame(2, contentRevisionCount('page', (string) $page['id']));

        $latest = contentRevisionGet((string) contentRevisionLatest('page', (string) $page['id'])['id']);
        self::assertNotNull($latest);
        self::assertSame('manual', (string) $latest['operation']);
        self::assertSame('Новый заголовок', (string) $latest['payload']['title']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) $latest['payload']['status']);
        self::assertSame(1, (int) $latest['payload']['noindex']);
        self::assertSame(hash('sha256', contentRevisionCanonicalJson($latest['payload'])), (string) $latest['checksum']);
    }

    public function testПовторноеСохранениеБезИзмененийНеПлодитРевизии(): void
    {
        $page = $this->page('bez-izmeneniy', 'Заголовок');
        $values = [
            'slug' => 'bez-izmeneniy',
            'title' => 'Заголовок',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ];

        $updated = updatePage((string) $page['id'], $values, $this->now->modify('+1 minute'), ['expected_version' => 1]);

        self::assertTrue($updated['ok'], $updated['error']);
        self::assertSame(1, contentRevisionVersion('page', (string) $page['id']), 'Одинаковый снимок не создаёт вторую версию');
        self::assertSame(1, contentRevisionCount('page', (string) $page['id']));
    }

    public function testНесовпадениеОжидаемойВерсииНеМеняетМатериал(): void
    {
        $page = $this->page('konflikt', 'Исходный');

        $conflict = updatePage((string) $page['id'], [
            'slug' => 'konflikt',
            'title' => 'Чужой вариант',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now->modify('+1 minute'), ['expected_version' => 0]);

        self::assertFalse($conflict['ok']);
        self::assertSame(1, $conflict['version']);
        self::assertSame(0, $conflict['conflict']['expected']);
        self::assertSame(1, $conflict['conflict']['actual']);
        self::assertSame(1, contentRevisionVersion('page', (string) $page['id']));
        self::assertSame('Исходный', (string) pageById((string) $page['id'])['title'], 'Отказ обязан откатить изменение');
    }

    public function testИдемпотентныйПовторВозвращаетТуЖеВерсию(): void
    {
        $page = $this->page('avtosohranenie', 'Первый');
        $id = (string) $page['id'];

        $first = updatePage($id, [
            'slug' => 'avtosohranenie',
            'title' => 'Автосохранённый',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now->modify('+1 minute'), ['expected_version' => 1, 'idempotency_key' => 'auto-1']);

        self::assertTrue($first['ok'], $first['error']);
        self::assertSame(2, contentRevisionVersion('page', $id));

        $replay = updatePage($id, [
            'slug' => 'avtosohranenie',
            'title' => 'Повтор не должен перезаписать',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now->modify('+2 minutes'), ['expected_version' => 1, 'idempotency_key' => 'auto-1']);

        self::assertTrue($replay['ok'], $replay['error']);
        self::assertTrue($replay['replayed'] ?? false);
        self::assertSame(2, (int) ($replay['version'] ?? 0));
        self::assertSame(2, contentRevisionVersion('page', $id));
        self::assertSame(2, contentRevisionCount('page', $id));
        self::assertSame('Автосохранённый', (string) pageById($id)['title']);
    }

    // --- Восстановление ----------------------------------------------------------------------

    public function testВосстановлениеВозвращаетПоляИСекцииИСоздаётНовуюРевизию(): void
    {
        $page = $this->page('vosstanovlenie', 'Версия один');
        $id = (string) $page['id'];

        $section = createSection($id, [
            'sec_key' => 'geroy',
            'kind' => 'hero',
            'title' => 'Первый заголовок',
            'lead_text' => 'Первый текст',
        ], $this->now->modify('+1 minute'));

        self::assertTrue($section['ok'], $section['error']);
        $sectionId = (string) $section['id'];

        $edited = updateSection($sectionId, [
            'sec_key' => 'geroy',
            'kind' => 'hero',
            'title' => 'Второй заголовок',
            'lead_text' => 'Второй текст',
        ], $this->now->modify('+2 minutes'));

        self::assertTrue($edited['ok'], $edited['error']);
        self::assertSame(3, contentRevisionVersion('page', $id));

        $target = one(
            'SELECT id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = ?',
            ['page', $id, 2]
        );
        self::assertNotNull($target, 'Ревизия с первой секцией обязана существовать');

        $restored = contentRevisionRestore('page', $id, (string) $target['id'], [
            'expected_version' => 3,
            'author' => ['id' => '', 'email' => 'editor@example.test'],
            'comment' => 'Возврат к первой секции',
        ], $this->now->modify('+3 minutes'));

        self::assertTrue($restored['ok'], $restored['error']);
        self::assertSame(4, $restored['version']);
        self::assertSame(4, contentRevisionVersion('page', $id));
        self::assertSame(4, contentRevisionCount('page', $id), 'История не удаляется: восстановление добавляет версию');

        $latest = contentRevisionGet((string) contentRevisionLatest('page', $id)['id']);
        self::assertNotNull($latest);
        self::assertSame('restore', (string) $latest['operation']);
        self::assertSame('Возврат к первой секции', (string) $latest['comment']);

        $back = sectionById($sectionId);
        self::assertNotNull($back, 'Секция обязана вернуться с прежним идентификатором');
        self::assertSame('Первый заголовок', (string) $back['title']);
        self::assertSame('Первый текст', (string) $back['lead_text']);
        self::assertSame('pervyy-zagolovok', (string) $back['sec_key']);
    }

    public function testВосстановлениеСУстаревшейВерсиейОтказывает(): void
    {
        $page = $this->page('ustarevshaya', 'Исходный');
        $id = (string) $page['id'];
        $first = one(
            'SELECT id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = 1',
            ['page', $id]
        );

        $restored = contentRevisionRestore('page', $id, (string) $first['id'], ['expected_version' => 0], $this->now);

        self::assertFalse($restored['ok']);
        self::assertStringContainsString('сравнить версии', $restored['error']);
        self::assertSame(1, contentRevisionVersion('page', $id));
    }

    public function testНельзяВосстановитьРевизиюДругогоМатериала(): void
    {
        $first = $this->page('pervyy', 'Первый');
        $second = $this->page('vtoroy', 'Второй');
        $revision = contentRevisionLatest('page', (string) $first['id']);

        self::assertNotNull($revision);

        $restored = contentRevisionRestore('page', (string) $second['id'], (string) $revision['id'], [], $this->now);

        self::assertFalse($restored['ok']);
        self::assertStringContainsString('другому материалу', $restored['error']);
    }

    public function testГлавнаяВерсионируетсяПодКлючомHome(): void
    {
        $first = createSection(PAGES_HOME_ID, [
            'sec_key' => 'pervyy',
            'kind' => 'hero',
            'title' => 'Первый блок',
        ], $this->now);

        self::assertTrue($first['ok'], $first['error']);
        self::assertSame(1, contentRevisionVersion('page', 'home'));

        $second = createSection(PAGES_HOME_ID, [
            'sec_key' => 'vtoroy',
            'kind' => 'text',
            'title' => 'Второй блок',
        ], $this->now->modify('+1 minute'));

        self::assertTrue($second['ok'], $second['error']);
        self::assertSame(2, contentRevisionVersion('page', 'home'));

        $target = one(
            'SELECT id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = 1',
            ['page', 'home']
        );
        $restored = contentRevisionRestore('page', 'home', (string) $target['id'], ['expected_version' => 2], $this->now->modify('+2 minutes'));

        self::assertTrue($restored['ok'], $restored['error']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [PAGES_HOME_ID]));
        self::assertSame('pervyy-blok', (string) value('SELECT sec_key FROM ' . t('page_section') . ' WHERE page_id = ?', [PAGES_HOME_ID]));
    }

    // --- Удаление ревизий --------------------------------------------------------------------

    public function testУдалениеРевизииЗащищаетАктуальнуюПубликациюИПоследнююОпубликованную(): void
    {
        $page = $this->page('udalenie', 'Версия 1');
        $id = (string) $page['id'];
        $values = static fn (string $title): array => [
            'slug' => 'udalenie',
            'title' => $title,
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ];

        self::assertTrue(updatePage($id, $values('Версия 2'), $this->now, ['expected_version' => 1])['ok']);
        self::assertTrue(updatePage($id, $values('Версия 3'), $this->now, ['expected_version' => 2])['ok']);

        $now = $this->now;
        tx(static function () use ($id, $now): void {
            contentRevisionGuard('page', $id, ['expected_version' => 3], $now);
            run(
                'UPDATE ' . t('page') . ' SET status = ?, updated_at = ? WHERE id = ?',
                [PAGES_STATUS_PUBLISHED, utcStamp($now), $id]
            );
            contentRevisionCommit('page', $id, ['operation' => 'publish', 'source' => 'system'], $now);
        });

        self::assertTrue(updatePage($id, $values('Версия 5'), $this->now, ['expected_version' => 4])['ok']);
        self::assertSame(5, contentRevisionVersion('page', $id));

        $versions = static fn (): array => array_map(
            static fn (array $row): int => (int) $row['version'],
            contentRevisionList('page', $id, [], 100)
        );
        $revisionAt = static fn (int $version): string => (string) value(
            'SELECT id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = ?',
            ['page', $id, $version]
        );

        $latest = contentRevisionDelete('page', $id, $revisionAt(5), ['id' => 'admin']);
        self::assertFalse($latest['ok'], 'Актуальную версию удалять нельзя');

        $published = contentRevisionDelete('page', $id, $revisionAt(4), ['id' => 'admin']);
        self::assertFalse($published['ok'], 'Опубликованную версию удалять нельзя');

        $initial = contentRevisionDelete('page', $id, $revisionAt(1), ['id' => 'admin']);
        self::assertTrue($initial['ok'], $initial['error']);
        self::assertSame([5, 4, 3, 2], $versions());

        $child = one(
            'SELECT parent_id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = ?',
            ['page', $id, 2]
        );
        self::assertNotNull($child);
        self::assertNull($child['parent_id'], 'Ребёнок удалённой первой ревизии переподвешивается к её родителю');

        $second = contentRevisionDelete('page', $id, $revisionAt(2), ['id' => 'admin']);
        self::assertTrue($second['ok'], $second['error']);
        self::assertSame([5, 4, 3], $versions());

        $child = one(
            'SELECT parent_id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = ?',
            ['page', $id, 3]
        );
        self::assertNotNull($child);
        self::assertNull($child['parent_id']);

        self::assertFalse(contentRevisionDelete('page', $id, 'missing-revision', ['id' => 'admin'])['ok']);
        self::assertFalse(contentRevisionDelete('page', $id, $revisionAt(4), ['id' => 'admin'])['ok'], 'Последняя опубликованная тоже защищена');
    }

    public function testНельзяУдалитьРевизиюЧужогоМатериала(): void
    {
        $first = $this->page('pervaya', 'Первая');
        $second = $this->page('vtoraya', 'Вторая');
        updatePage((string) $second['id'], [
            'slug' => 'vtoraya',
            'title' => 'Вторая новая',
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now, ['expected_version' => 1]);

        $foreign = one(
            'SELECT id FROM ' . t('content_revisions') . ' WHERE content_type = ? AND content_id = ? AND version = 1',
            ['page', (string) $first['id']]
        );

        $result = contentRevisionDelete('page', (string) $second['id'], (string) $foreign['id'], ['id' => 'admin']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('другому материалу', $result['error']);
    }

    // --- Уборка ------------------------------------------------------------------------------

    public function testУборкаНеТрогаетАктуальнуюПубликацииИПоследнююОпубликованную(): void
    {
        setOption('REVISION_MAX_PER_CONTENT', '2');
        setOption('REVISION_RETENTION_DAYS', '3650');

        $page = $this->page('uborka', 'Версия 1');
        $id = (string) $page['id'];
        $values = static fn (string $title): array => [
            'slug' => 'uborka',
            'title' => $title,
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ];

        self::assertTrue(updatePage($id, $values('Версия 2'), $this->now, ['expected_version' => 1])['ok']);
        self::assertTrue(updatePage($id, $values('Версия 3'), $this->now, ['expected_version' => 2])['ok']);

        $now = $this->now;
        tx(static function () use ($id, $now): void {
            contentRevisionGuard('page', $id, ['expected_version' => 3], $now);
            contentRevisionCommit('page', $id, ['operation' => 'publish', 'source' => 'system'], $now);
        });

        self::assertTrue(updatePage($id, $values('Версия 5'), $this->now, ['expected_version' => 4])['ok']);
        self::assertTrue(updatePage($id, $values('Версия 6'), $this->now, ['expected_version' => 5])['ok']);
        self::assertSame(6, contentRevisionVersion('page', $id));

        $dry = contentRevisionCleanup(true, $this->now);
        self::assertSame(3, $dry['deleted'], 'Уборка обязана удалить только старые черновые снимки');
        self::assertSame(6, contentRevisionCount('page', $id), 'Пробный прогон ничего не удаляет');

        $real = contentRevisionCleanup(false, $this->now);
        self::assertSame(3, $real['deleted']);

        $versions = array_map(
            static fn (array $row): int => (int) $row['version'],
            contentRevisionList('page', $id, [], 100)
        );
        self::assertSame([6, 5, 4], $versions, 'Остались актуальная, предыдущая и опубликованная версии');
        self::assertNotNull(contentRevisionLastPublished('page', $id));
    }

    public function testУборкаНеТрогаетМатериалНаСогласовании(): void
    {
        setOption('REVISION_MAX_PER_CONTENT', '1');
        setOption('REVISION_RETENTION_DAYS', '3650');

        $page = $this->page('soglasovanie', 'Версия 1');
        $id = (string) $page['id'];
        $values = static fn (string $title): array => [
            'slug' => 'soglasovanie',
            'title' => $title,
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ];

        self::assertTrue(updatePage($id, $values('Версия 2'), $this->now, ['expected_version' => 1])['ok']);
        self::assertTrue(updatePage($id, $values('Версия 3'), $this->now, ['expected_version' => 2])['ok']);
        editorialStateWrite('page', $id, 'review', null, [], $this->now);

        $report = contentRevisionCleanup(false, $this->now);

        self::assertSame(0, $report['deleted'], 'История материала на согласовании неприкосновенна');
        self::assertSame(1, $report['kept']);
        self::assertSame(3, contentRevisionCount('page', $id));
    }

    public function testУборкаУчитываетСрокХранения(): void
    {
        setOption('REVISION_MAX_PER_CONTENT', '100');
        setOption('REVISION_RETENTION_DAYS', '1');

        $page = $this->page('srok', 'Версия 1');
        $id = (string) $page['id'];
        $values = static fn (string $title): array => [
            'slug' => 'srok',
            'title' => $title,
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ];

        self::assertTrue(updatePage($id, $values('Версия 2'), $this->now, ['expected_version' => 1])['ok']);
        self::assertTrue(updatePage($id, $values('Версия 3'), $this->now, ['expected_version' => 2])['ok']);

        $old = utcStamp($this->now->modify('-3 days'));
        run(
            'UPDATE ' . t('content_revisions') . ' SET created_at = ? WHERE content_type = ? AND content_id = ? AND version < 3',
            [$old, 'page', $id]
        );

        $report = contentRevisionCleanup(false, $this->now);

        self::assertSame(2, $report['deleted']);
        self::assertSame(1, contentRevisionCount('page', $id));
        self::assertSame(3, contentRevisionVersion('page', $id));
    }

    // --- Базовая ревизия старого материала ---------------------------------------------------

    public function testБазоваяРевизияСтарогоМатериалаСоздаётсяОдинРаз(): void
    {
        $id = newId();
        $moment = utcStamp($this->now->modify('-30 days'));

        insert('page', [
            'id' => $id,
            'slug' => 'staraya',
            'title' => 'Старая страница',
            'description' => 'Жила до релиза',
            'status' => PAGES_STATUS_PUBLISHED,
            'noindex' => 0,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        contentRevisionEnsureBaseline('page', $id, $this->now);
        contentRevisionEnsureBaseline('page', $id, $this->now->modify('+1 minute'));

        self::assertSame(1, contentRevisionVersion('page', $id), 'Повторный вызов базовой ревизии ничего не делает');
        self::assertSame(1, contentRevisionCount('page', $id));

        $revision = contentRevisionGet((string) contentRevisionLatest('page', $id)['id']);
        self::assertNotNull($revision);
        self::assertSame('initial', (string) $revision['operation']);
        self::assertSame('import', (string) $revision['source']);
        self::assertSame('Состояние до RELEASE 2.2', (string) $revision['comment']);
        self::assertSame('Старая страница', (string) $revision['payload']['title']);
    }

    // --- Помощники ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function page(string $slug, string $title): array
    {
        $page = createPage([
            'slug' => $slug,
            'title' => $title,
            'description' => '',
            'status' => PAGES_STATUS_DRAFT,
            'noindex' => '0',
        ], $this->now);

        self::assertTrue($page['ok'], $page['error']);

        return $page;
    }
}
