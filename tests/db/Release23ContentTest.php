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

    public function testАрхивноеПолеИСвязьПереживаютПравкуЗаписи(): void
    {
        $targetType = contentTypeSave(null, ['name' => 'Справочник', 'slug' => 'spravochnik'], $this->fieldRows([
            ['label' => 'Название', 'key' => 'name', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
        ]), $this->now, $this->meta());
        $target = contentEntryCreate((string) $targetType['id'], ['title' => 'Цель связи'], [
            'fields' => ['name' => 'Цель связи'],
        ], $this->now, $this->meta());

        $type = contentTypeSave(null, ['name' => 'Карточки', 'slug' => 'kartochki'], $this->fieldRows([
            ['label' => 'Заголовок', 'key' => 'zagolovok', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
            ['label' => 'Биография', 'key' => 'bio', 'kind' => CONTENT_FIELD_TEXT],
            ['label' => 'Связь', 'key' => 'svyaz', 'kind' => CONTENT_FIELD_RELATION, 'types' => [(string) $targetType['id']]],
        ]), $this->now, $this->meta());
        $typeId = (string) $type['id'];

        $entry = contentEntryCreate($typeId, ['title' => 'Карточка'], ['fields' => [
            'zagolovok' => 'Карточка',
            'bio' => 'Биография сохраняется',
            'svyaz' => [(string) $target['id']],
        ]], $this->now, $this->meta());
        $entryId = (string) $entry['id'];

        // Убираем два поля через конструктор: они уходят в архив, но значения остаются в JSON.
        $kept = contentTypeSave($typeId, ['name' => 'Карточки', 'slug' => 'kartochki'], $this->fieldRows([
            ['label' => 'Заголовок', 'key' => 'zagolovok', 'kind' => CONTENT_FIELD_TEXT, 'required' => true, 'original' => 'zagolovok'],
        ]), $this->now, $this->meta());
        self::assertTrue($kept['ok'], (string) $kept['error']);

        $updated = contentEntryUpdate($entryId, ['title' => 'Карточка обновлена'], ['fields' => [
            'zagolovok' => 'Карточка обновлена',
        ]], $this->now, $this->meta());
        self::assertTrue($updated['ok'], (string) $updated['error']);

        self::assertSame('Биография сохраняется', contentEntryData(contentEntryById($entryId))['bio']);
        $relations = contentEntryRelations($entryId);
        self::assertSame($target['id'], $relations['svyaz'][0]['id']);
    }

    public function testПовторСозданияПоКлючуИдемпотентностиНеДублируетКонтент(): void
    {
        $type = contentTypeSave(null, ['name' => 'Заметки', 'slug' => 'zametki'], $this->fieldRows([
            ['label' => 'Текст', 'key' => 'text', 'kind' => CONTENT_FIELD_TEXT],
        ]), $this->now, $this->meta());
        $meta = $this->meta(['idempotency_key' => 'odin-klyuch']);
        $first = contentEntryCreate((string) $type['id'], ['title' => 'Заметка'], ['fields' => ['text' => 'Текст']], $this->now, $meta);
        $second = contentEntryCreate((string) $type['id'], ['title' => 'Заметка'], ['fields' => ['text' => 'Текст']], $this->now, $meta);

        self::assertTrue($first['ok'], (string) $first['error']);
        self::assertTrue($second['ok'], (string) $second['error']);
        self::assertTrue((bool) ($second['replayed'] ?? false));
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('content_entry') . ' WHERE type_id = ?', [(string) $type['id']]));

        $blockMeta = $this->meta(['idempotency_key' => 'blok-klyuch']);
        $blockFirst = globalBlockSave(null, ['title' => 'Блок', 'slug' => 'blok'], $this->now, $blockMeta);
        $blockSecond = globalBlockSave(null, ['title' => 'Блок', 'slug' => 'blok'], $this->now, $blockMeta);
        self::assertTrue((bool) ($blockSecond['replayed'] ?? false));
        self::assertSame($blockFirst['id'], $blockSecond['id']);
    }

    public function testВосстановлениеПубликацииТребуетПраваПубликации(): void
    {
        $type = contentTypeSave(null, ['name' => 'Публикации', 'slug' => 'publikacii'], $this->fieldRows([
            ['label' => 'Текст', 'key' => 'text', 'kind' => CONTENT_FIELD_TEXT],
        ]), $this->now, $this->meta());
        $entry = contentEntryCreate((string) $type['id'], ['title' => 'Материал'], ['fields' => ['text' => 'Текст']], $this->now, $this->meta());
        $entryId = (string) $entry['id'];

        $published = contentEntryStatus($entryId, CONTENT_STATUS_PUBLISHED, $this->now, $this->meta());
        self::assertTrue($published['ok'], (string) $published['error']);
        $revisionId = (string) contentRevisionLatest('entry', $entryId)['id'];

        $unpublished = contentEntryStatus($entryId, CONTENT_STATUS_DRAFT, $this->now, $this->meta());
        self::assertTrue($unpublished['ok'], (string) $unpublished['error']);

        $editorId = TestDb::seedUser('editor23@example.test', 'пароль-для-теста', ROLES['editor']);
        $_SESSION['user_id'] = $editorId;
        $editor = currentUser();
        $restored = contentRevisionRestore('entry', $entryId, $revisionId, ['author' => $editor], $this->now);
        self::assertFalse($restored['ok'], 'Редактор не должен снимать барьер публикации через восстановление');
        self::assertSame(CONTENT_STATUS_DRAFT, (string) contentEntryById($entryId)['status']);
    }

    public function testПрежнийСлагНеОтдаётсяПодНовыйРаздел(): void
    {
        $type = contentTypeSave(null, ['name' => 'Статьи', 'slug' => 'statyi'], [], $this->now, $this->meta());
        $renamed = contentTypeSave((string) $type['id'], ['name' => 'Статьи', 'slug' => 'articles'], [], $this->now, $this->meta());
        self::assertTrue($renamed['ok'], (string) $renamed['error']);

        $taken = contentTypeSave(null, ['name' => 'Другие статьи', 'slug' => 'statyi'], [], $this->now, $this->meta());
        self::assertFalse($taken['ok'], 'Старый адрес типа не должен доставаться другому типу');

        $page = createPage(['slug' => 'statyi', 'title' => 'Страница', 'status' => PAGES_STATUS_PUBLISHED], $this->now);
        self::assertFalse($page['ok'], 'Старый адрес типа не должен доставаться странице');
    }

    public function testСвязьОтклоняетАрхивнуюЗапись(): void
    {
        $type = contentTypeSave(null, ['name' => 'Записи', 'slug' => 'zapisi'], $this->fieldRows([
            ['label' => 'Название', 'key' => 'name', 'kind' => CONTENT_FIELD_TEXT, 'required' => true],
            ['label' => 'Связь', 'key' => 'svyaz', 'kind' => CONTENT_FIELD_RELATION],
        ]), $this->now, $this->meta());
        $first = contentEntryCreate((string) $type['id'], ['title' => 'Первая'], ['fields' => ['name' => 'Первая']], $this->now, $this->meta());
        $second = contentEntryCreate((string) $type['id'], ['title' => 'Вторая'], ['fields' => ['name' => 'Вторая']], $this->now, $this->meta());

        $archived = contentEntryStatus((string) $first['id'], CONTENT_STATUS_ARCHIVED, $this->now, $this->meta());
        self::assertTrue($archived['ok'], (string) $archived['error']);

        $updated = contentEntryUpdate((string) $second['id'], ['title' => 'Вторая'], ['fields' => [
            'name' => 'Вторая',
            'svyaz' => [(string) $first['id']],
        ]], $this->now, $this->meta());
        self::assertFalse($updated['ok']);
        self::assertArrayHasKey('svyaz', $updated['errors']);
    }

    public function testБлокиВРедакционномЦентреДоступныТолькоАдминистратору(): void
    {
        $created = globalBlockSave(null, [
            'title' => 'Общий блок',
            'slug' => 'obshchij-blok',
            'text' => 'Текст блока',
        ], $this->now, $this->meta());
        self::assertTrue($created['ok'], (string) $created['error']);
        $blockId = (string) $created['id'];
        $revisionId = (string) contentRevisionLatest('block', $blockId)['id'];

        // Администратор видит историю блока и его место в ленте редакции.
        self::assertTrue(editorialContentExists('block', $blockId));
        self::assertSame('Общий блок', editorialContentTitle('block', $blockId));
        self::assertSame('/admin/content/blocks/' . $blockId, editorialContentUrl('block', $blockId));
        $feed = array_filter(editorialRecentRevisions(10), static fn (array $row): bool => (string) $row['content_type'] === 'block');
        self::assertNotEmpty($feed, 'Лента администратора показывает блок');

        $updated = globalBlockSave($blockId, ['title' => 'Изменённый блок', 'text' => 'Текст блока'], $this->now, $this->meta());
        self::assertTrue($updated['ok'], (string) $updated['error']);

        $editorId = TestDb::seedUser('block-editor@example.test', 'пароль-для-теста', ROLES['editor']);
        $_SESSION['user_id'] = $editorId;
        self::assertFalse(editorialContentExists('block', $blockId), 'Редактор не открывает историю блоков');
        $feed = array_filter(editorialRecentRevisions(10), static fn (array $row): bool => (string) $row['content_type'] === 'block');
        self::assertSame([], array_values($feed), 'Лента редактора не показывает блоки');
        $denied = contentRevisionRestore('block', $blockId, $revisionId, ['author' => currentUser()], $this->now);
        self::assertFalse($denied['ok'], 'Прямой POST не обходит гейт управления блоками');
        self::assertSame('Изменённый блок', (string) globalBlockById($blockId)['title']);

        // Администратор возвращает прежнюю версию блока.
        $_SESSION['user_id'] = (string) $this->admin['id'];
        $restored = contentRevisionRestore('block', $blockId, $revisionId, ['author' => $this->admin], $this->now);
        self::assertTrue($restored['ok'], (string) $restored['error']);
        self::assertSame('Общий блок', (string) globalBlockById($blockId)['title']);
    }

    public function testВосстановлениеЗапланированнойВерсииОтклоняется(): void
    {
        $type = contentTypeSave(null, ['name' => 'Планы', 'slug' => 'plany'], $this->fieldRows([
            ['label' => 'Текст', 'key' => 'text', 'kind' => CONTENT_FIELD_TEXT],
        ]), $this->now, $this->meta());
        $entry = contentEntryCreate((string) $type['id'], ['title' => 'Будущий материал'], ['fields' => [
            'text' => 'Текст',
        ]], $this->now, $this->meta());
        $entryId = (string) $entry['id'];

        $scheduled = contentEntrySchedule($entryId, '2030-01-01T12:00', 'UTC', $this->admin, $this->now);
        self::assertTrue($scheduled['ok'], (string) $scheduled['error']);
        $revisionId = (string) contentRevisionLatest('entry', $entryId)['id'];

        $cancelled = contentEntryScheduleCancel((string) $scheduled['id'], $this->admin, $this->now);
        self::assertTrue($cancelled['ok'], (string) $cancelled['error']);

        $restored = contentRevisionRestore('entry', $entryId, $revisionId, ['author' => $this->admin], $this->now);
        self::assertFalse($restored['ok'], 'Статус «запланировано» без заявки не восстанавливается');
        self::assertStringContainsString('Запланировано', (string) $restored['error']);
        self::assertSame(CONTENT_STATUS_DRAFT, (string) contentEntryById($entryId)['status']);
    }

    public function testВосстановлениеАрхивнойВерсииТребуетПраваПубликации(): void
    {
        $type = contentTypeSave(null, ['name' => 'Архив', 'slug' => 'arhiv'], $this->fieldRows([
            ['label' => 'Текст', 'key' => 'text', 'kind' => CONTENT_FIELD_TEXT],
        ]), $this->now, $this->meta());
        $entry = contentEntryCreate((string) $type['id'], ['title' => 'Материал'], ['fields' => [
            'text' => 'Текст',
        ]], $this->now, $this->meta());
        $entryId = (string) $entry['id'];

        $archived = contentEntryStatus($entryId, CONTENT_STATUS_ARCHIVED, $this->now, $this->meta());
        self::assertTrue($archived['ok'], (string) $archived['error']);
        $revisionId = (string) contentRevisionLatest('entry', $entryId)['id'];

        $editorId = TestDb::seedUser('archive-editor@example.test', 'пароль-для-теста', ROLES['editor']);
        $_SESSION['user_id'] = $editorId;
        $restored = contentRevisionRestore('entry', $entryId, $revisionId, ['author' => currentUser()], $this->now);
        self::assertFalse($restored['ok'], 'Редактор не возвращает архивную версию');
        self::assertSame(CONTENT_STATUS_ARCHIVED, (string) contentEntryById($entryId)['status']);
    }

    public function testПрежнийСлагЗаписиНеОтдаётсяДругойЗаписи(): void
    {
        $type = contentTypeSave(null, ['name' => 'Материалы', 'slug' => 'materialy'], $this->fieldRows([
            ['label' => 'Текст', 'key' => 'text', 'kind' => CONTENT_FIELD_TEXT],
        ]), $this->now, $this->meta());
        $typeId = (string) $type['id'];

        $first = contentEntryCreate($typeId, ['title' => 'Первая', 'slug' => 'pervaya'], ['fields' => ['text' => 'Текст']], $this->now, $this->meta());
        self::assertTrue($first['ok'], (string) $first['error']);
        $renamed = contentEntryUpdate((string) $first['id'], ['title' => 'Первая', 'slug' => 'novaya'], ['fields' => ['text' => 'Текст']], $this->now, $this->meta());
        self::assertTrue($renamed['ok'], (string) $renamed['error']);

        // Старый адрес держит 301 на живую запись: второй материал его не получает.
        $renamedRow = contentEntryById((string) $first['id']);
        self::assertNotNull($renamedRow);
        self::assertSame('novaya', (string) $renamedRow['slug']);
        $second = contentEntryCreate($typeId, ['title' => 'Вторая', 'slug' => 'pervaya'], ['fields' => ['text' => 'Текст']], $this->now, $this->meta());
        self::assertFalse($second['ok']);

        // Владелец может вернуться на прежний адрес: свой прежний слаг ему не чужой.
        $back = contentEntryUpdate((string) $first['id'], ['title' => 'Первая', 'slug' => 'pervaya'], ['fields' => ['text' => 'Текст']], $this->now, $this->meta());
        self::assertTrue($back['ok'], (string) $back['error']);
        self::assertSame('pervaya', (string) contentEntryById((string) $first['id'])['slug']);
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
            $raw['field_original'][$index] = $row['original'] ?? '';
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
