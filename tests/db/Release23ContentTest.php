<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.3 на живой базе: типы и поля, записи со связями, ревизии, статусы и глобальные блоки.
 * Проверяется именно доменный слой — административные экраны только вызывают его.
 */
final class Release23ContentTest extends TestCase
{
    private DateTimeImmutable $now;
    /** @var array<string, mixed> */
    private array $admin;

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC'));
        $_SESSION = [];
        startSession();
        $_SESSION['user_id'] = '';
        $_SESSION['session_version'] = 0;

        foreach (['content_entry_relation', 'content_entry_old_slug', 'content_entry', 'content_field', 'content_type_old_slug', 'content_type', 'global_block', 'content_revisions', 'content_heads', 'audit_log', 'user'] as $table) {
            TestDb::clearTable($table);
        }

        $id = TestDb::seedUser('admin23@example.test', 'пароль-для-теста', ROLES['admin']);
        $_SESSION['user_id'] = $id;
        $_SESSION['session_version'] = 0;
        $this->admin = currentUser() ?? [];

        // Раньше в прогоне могли сбросить реестр подписок (hooksReset), и ревизии переставали
        // знать про entry/block. Подписки ядра идемпотентны — возвращаем их на место.
        contentHooksRegister();
    }

    public function testТипИПоляСохраняютсяИАрхивируютсяОбратимо(): void
    {
        $raw = $this->fieldRows([
            ['label' => 'Заголовок', 'key' => 'title', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
            ['label' => 'Описание', 'key' => 'about', 'kind' => CONTENT_FIELD_TEXTAREA],
        ]);

        $created = contentTypeSave(null, ['name' => 'Статьи', 'slug' => 'statyi'], $raw, $this->now);
        self::assertTrue($created['ok'], (string) $created['error']);
        self::assertSame('statyi', $created['slug']);

        $typeId = (string) $created['id'];
        $fields = contentTypeFields($typeId);
        self::assertCount(2, $fields);
        self::assertSame('title', (string) $fields[0]['field_key']);
        self::assertSame(1, (int) $fields[0]['required']);

        // Новый ключ с тем же именем занят: служебные и чужие адреса тип не займёт.
        $taken = contentTypeSave(null, ['name' => 'Другие статьи', 'slug' => 'statyi'], [], $this->now);
        self::assertFalse($taken['ok']);

        $archived = contentTypeArchive($typeId, true, $this->now);
        self::assertTrue($archived['ok'], (string) $archived['error']);
        $archivedLookup = contentTypeBySlug('statyi');
        self::assertNull($archivedLookup);
        self::assertNotNull(contentTypeById($typeId), 'Архив не удаляет строки');

        $restored = contentTypeArchive($typeId, false, $this->now);
        self::assertTrue($restored['ok'], (string) $restored['error']);
        $restoredType = contentTypeBySlug('statyi');
        self::assertSame($typeId, $restoredType !== null ? (string) $restoredType['id'] : '');
    }

    public function testЗаписьСПолямиСвязямиИРевизиейМеняетСтатусБезПотери(): void
    {
        $authorType = contentTypeSave(null, ['name' => 'Авторы', 'slug' => 'avtory'], $this->fieldRows([
            ['label' => 'Имя', 'key' => 'name', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
        ]), $this->now);
        self::assertTrue($authorType['ok'], (string) $authorType['error']);

        $articleType = contentTypeSave(null, ['name' => 'Статьи', 'slug' => 'articles'], $this->fieldRows([
            ['label' => 'Тема', 'key' => 'topic', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
            ['label' => 'Автор', 'key' => 'author', 'kind' => CONTENT_FIELD_RELATION, 'types' => [(string) $authorType['id']]],
        ]), $this->now);
        self::assertTrue($articleType['ok'], (string) $articleType['error']);

        $author = contentEntryCreate((string) $authorType['id'], ['title' => 'Иван Петров'], [
            'fields' => ['name' => 'Иван Петров'],
        ], $this->now, $this->meta());
        self::assertTrue($author['ok'], (string) $author['error']);

        $post = ['fields' => [
            'topic' => 'Первая статья',
            'author' => [(string) $author['id']],
        ]];
        $entry = contentEntryCreate((string) $articleType['id'], [
            'title' => 'Первая статья',
            'slug' => 'pervaya-statya',
        ], $post, $this->now, $this->meta());
        self::assertTrue($entry['ok'], (string) $entry['error']);

        $entryId = (string) $entry['id'];
        self::assertSame('pervaya-statya', contentEntryById($entryId)['slug']);
        self::assertSame('Первая статья', contentEntryData(contentEntryById($entryId))['topic']);
        self::assertSame($author['id'], contentEntryRelations($entryId)['author'][0]['id']);
        self::assertGreaterThan(0, contentRevisionVersion('entry', $entryId));

        // Обязательное поле не даёт сохранить пустоту, а история остаётся прежней.
        $invalid = contentEntryUpdate($entryId, ['title' => 'Плохая статья'], [
            'fields' => ['topic' => '', 'author' => []],
        ], $this->now, $this->meta());
        self::assertFalse($invalid['ok']);
        self::assertSame('Первая статья', (string) contentEntryById($entryId)['title']);

        $version = contentRevisionVersion('entry', $entryId);
        $updated = contentEntryUpdate($entryId, ['title' => 'Обновлённая статья'], [
            'fields' => ['topic' => 'Новая тема', 'author' => [(string) $author['id']]],
        ], $this->now, $this->meta(['expected_version' => $version]));
        self::assertTrue($updated['ok'], (string) $updated['error']);

        $stale = contentEntryUpdate($entryId, ['title' => 'Устаревшая правка'], [
            'fields' => ['topic' => 'Старая тема', 'author' => []],
        ], $this->now, $this->meta(['expected_version' => $version]));
        self::assertFalse($stale['ok']);
        self::assertArrayHasKey('conflict', $stale);
        self::assertSame('Обновлённая статья', (string) contentEntryById($entryId)['title']);

        $published = contentEntryStatus($entryId, CONTENT_STATUS_PUBLISHED, $this->now, $this->meta());
        self::assertTrue($published['ok'], (string) $published['error']);
        self::assertSame(CONTENT_STATUS_PUBLISHED, (string) contentEntryById($entryId)['status']);
        self::assertNotNull(contentEntryById($entryId)['published_at']);

        $renamed = contentEntryUpdate($entryId, ['title' => 'Обновлённая статья', 'slug' => 'novaya-statya'], [
            'fields' => ['topic' => 'Новая тема', 'author' => [(string) $author['id']]],
        ], $this->now, $this->meta());
        self::assertTrue($renamed['ok'], (string) $renamed['error']);
        $old = contentEntryOldSlug((string) $articleType['id'], 'pervaya-statya');
        self::assertSame($entryId, is_array($old) ? (string) $old['id'] : '');

        $archived = contentEntryStatus($entryId, CONTENT_STATUS_ARCHIVED, $this->now, $this->meta());
        self::assertTrue($archived['ok'], (string) $archived['error']);
        self::assertSame(CONTENT_STATUS_ARCHIVED, (string) contentEntryById($entryId)['status']);
    }

    public function testГлобальныйБлокМеняетАктивностьИПечатаетРазметку(): void
    {
        $created = globalBlockSave(null, [
            'title' => 'Приветствие',
            'slug' => 'privetstvie',
            'text' => 'Текст **блока**',
            'layout' => 'banner',
        ], $this->now, $this->meta());
        self::assertTrue($created['ok'], (string) $created['error']);

        $blockId = (string) $created['id'];
        $block = globalBlockById($blockId);
        self::assertSame(1, (int) $block['active']);
        self::assertGreaterThan(0, contentRevisionVersion('block', $blockId));
        self::assertStringContainsString('Приветствие', globalBlockHtml($block));

        $archived = globalBlockSetActive($blockId, false, $this->now);
        self::assertTrue($archived['ok'], (string) $archived['error']);
        self::assertSame('', globalBlockHtml(globalBlockById($blockId)));

        $restored = globalBlockSetActive($blockId, true, $this->now);
        self::assertTrue($restored['ok'], (string) $restored['error']);
        self::assertStringContainsString('<strong>блока</strong>', globalBlockHtml(globalBlockById($blockId)));
    }

    /** @param list<array<string, mixed>> $rows
     *  @return array<string, mixed>
     */
    private function fieldRows(array $rows): array
    {
        $raw = [
            'field_label' => [], 'field_key' => [], 'field_original' => [], 'field_kind' => [],
            'field_hint' => [], 'field_options' => [], 'field_required' => [], 'field_types' => [],
        ];

        foreach ($rows as $index => $row) {
            $raw['field_label'][$index] = $row['label'];
            $raw['field_key'][$index] = $row['key'];
            $raw['field_kind'][$index] = $row['kind'];
            $raw['field_required'][$index] = ($row['required'] ?? false) ? '1' : '0';
            $raw['field_types'][$index] = $row['types'] ?? [];
        }

        return $raw;
    }

    /** @param array<string, mixed> $extra
     *  @return array<string, mixed>
     */
    private function meta(array $extra = []): array
    {
        return array_merge(['user' => $this->admin, 'comment' => 'Тест RELEASE 2.3'], $extra);
    }
}
