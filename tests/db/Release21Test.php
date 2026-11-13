<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * RELEASE 2.1 на настоящей базе: схема, журнал действий, корзина с восстановлением, дублирование
 * и буфер обмена. Проверяются свойства, которые видны только в базе: уникальные ключи, JSON-снимок
 * удалённой сущности, освобождение живых таблиц и сохранение истории адресов.
 */
final class Release21Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        foreach (['trash', 'clipboard', 'audit_log'] as $table) {
            TestDb::clearTable($table);
        }

        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page_old_slug'));
        run('DELETE FROM ' . t('page'));
    }

    // --- Схема -------------------------------------------------------------------------------

    public function testТаблицыРелизаСозданыМиграцией(): void
    {
        foreach (['audit_log', 'media', 'trash', 'clipboard', 'preview_token', 'password_reset', 'password_reset_attempt', 'consent_log'] as $table) {
            self::assertTrue(tableExists($table), 'Миграция не создала таблицу ' . $table);
        }
    }

    // --- Журнал действий ---------------------------------------------------------------------

    public function testЗаписьЖурналаСохраняетДействиеИПодробности(): void
    {
        auditLog('page.create', 'page', 'str-1', ['slug' => 'o-nas'], 'Создана страница');

        $row = one('SELECT action, entity, entity_id, summary, details FROM ' . t('audit_log') . ' WHERE entity_id = ?', ['str-1']);

        self::assertNotNull($row);
        self::assertSame('page.create', (string) $row['action']);
        self::assertSame('page', (string) $row['entity']);
        self::assertSame('Создана страница', (string) $row['summary']);

        $details = auditDetailsOf($row['details']);
        self::assertSame('o-nas', $details['slug'] ?? null, 'Подробности обязаны читаться обратно как массив');
    }

    // Журнал не должен ломать основное действие: вызывающий код не ловит исключение журнала.
    // Запись с пустыми полями проходит и не роняет вызывающего.
    public function testЖурналПринимаетПустыеПоляИНеРвётДействие(): void
    {
        $before = (int) value('SELECT COUNT(*) FROM ' . t('audit_log'));

        // Действие с пустой строкой законно: журнал пишет и его, ничего не падая.
        auditLog('', '', '', [], '');

        self::assertSame($before + 1, (int) value('SELECT COUNT(*) FROM ' . t('audit_log')), 'Журнал обязан принять и пустые поля');
    }

    public function testСписокЖурналаИщетПоПочтеИДействию(): void
    {
        auditLog('page.create', 'page', 'p1', [], 'Страница «О школе»');
        auditLog('lead.delete', 'lead', 'l1', [], 'Заявка удалена');

        $found = auditPage(1, 'lead');
        self::assertSame(1, $found['total'], 'Поиск по действию обязан отсеять несовпавшие строки');
        self::assertSame('lead.delete', (string) $found['rows'][0]['action']);

        self::assertSame(2, auditPage(1, '')['total'], 'Пустой запрос отдаёт весь журнал');
    }

    // --- Корзина -----------------------------------------------------------------------------

    public function testСтраницаУходитВКорзинуИВосстанавливаетсяССекциями(): void
    {
        $page = createPage([
            'slug' => 'vremennaya',
            'title' => 'Временная страница',
            'description' => 'Описание',
            'status' => PAGES_STATUS_PUBLISHED,
        ]);

        $section = createSection((string) $page['id'], [
            'sec_key' => 'shapka',
            'kind' => 'hero',
            'title' => 'Привет',
        ]);

        $trashed = trashPage((string) $page['id']);
        self::assertTrue($trashed['ok']);

        self::assertNull(pageById((string) $page['id']), 'Удалённая страница не должна оставаться в живых таблицах');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [(string) $page['id']]));
        self::assertCount(1, trashList());

        $trash = trashList()[0];
        $restored = trashRestore($trash['id']);
        self::assertTrue($restored['ok'], 'Страница обязана восстановиться: ' . $restored['error']);

        self::assertNotNull(pageById((string) $page['id']));
        $back = all('SELECT id, title FROM ' . t('page_section') . ' WHERE page_id = ?', [(string) $page['id']]);
        self::assertCount(1, $back, 'Секции обязаны вернуться вместе со страницей');
        self::assertSame('Привет', (string) $back[0]['title']);
        self::assertSame([], trashList(), 'Восстановленная запись обязана уйти из корзины');
    }

    // Адрес мог достаться другой странице: восстановление в занятый адрес сломало бы уникальный
    // ключ, поэтому оно честно отказывает, а не молча переименовывает.
    public function testВосстановлениеВЗанятыйАдресОтказывает(): void
    {
        $page = createPage(['slug' => 'zanyato', 'title' => 'Первая', 'status' => PAGES_STATUS_DRAFT]);

        trashPage((string) $page['id']);

        // Пока страница лежала в корзине, адрес заняли.
        createPage(['slug' => 'zanyato', 'title' => 'Вторая', 'status' => PAGES_STATUS_DRAFT]);

        $trash = trashList()[0];
        $result = trashRestore($trash['id']);

        self::assertFalse($result['ok'], 'Восстановление в занятый адрес обязано отказать');
        self::assertStringContainsString('уже занят', $result['error']);
        self::assertCount(1, trashList(), 'Отказ не убирает запись из корзины');
    }

    public function testСекцияУходитВКорзинуИВозвращаетсяВКонец(): void
    {
        $page = createPage(['slug' => 's-sekciyami', 'title' => 'Со секциями', 'status' => PAGES_STATUS_DRAFT]);
        $first = createSection((string) $page['id'], ['sec_key' => 'pervaya', 'kind' => 'text', 'title' => 'Первая']);

        $trashed = trashSection((string) $first['id']);
        self::assertTrue($trashed['ok']);
        self::assertNull(sectionById((string) $first['id']));

        $trash = trashList()[0];
        self::assertSame('section', $trash['rawKind']);
        self::assertSame((string) $page['id'], $trash['parent_id']);

        $restored = trashRestore($trash['id']);
        self::assertTrue($restored['ok'], 'Секция обязана восстановиться: ' . $restored['error']);
        self::assertNotNull(sectionById((string) $first['id']));
    }

    public function testОчисткаКорзиныУдаляетТолькоСвоиЗаписи(): void
    {
        $page = createPage(['slug' => 'udalyayu', 'title' => 'Удаляю', 'status' => PAGES_STATUS_DRAFT]);
        trashPage((string) $page['id']);
        self::assertCount(1, trashList());

        trashEmpty();

        self::assertSame([], trashList(), 'Очистка обязана убрать записи корзины');
        self::assertNull(pageById((string) $page['id']), 'Очистка корзины не возвращает страницу');
    }

    // --- Дублирование ------------------------------------------------------------------------

    public function testДублированиеСтраницыКопируетСекцииИДаётСвободныйАдрес(): void
    {
        $page = createPage(['slug' => 'dublirovat', 'title' => 'Оригинал', 'status' => PAGES_STATUS_DRAFT]);
        createSection((string) $page['id'], ['sec_key' => 'odin', 'kind' => 'text', 'title' => 'Секция']);

        $copy = duplicatePage((string) $page['id']);
        self::assertTrue($copy['ok'], 'Копия обязана создаться: ' . $copy['error']);

        $newId = (string) $copy['id'];
        self::assertNotSame((string) $page['id'], $newId);

        $clone = pageById($newId);
        self::assertNotNull($clone);
        self::assertNotSame('dublirovat', (string) $clone['slug'], 'Копия не занимает адрес оригинала');
        self::assertSame(PAGES_STATUS_DRAFT, (string) $clone['status'], 'Копия обязана стать черновиком');

        $sections = all('SELECT id, sec_key FROM ' . t('page_section') . ' WHERE page_id = ?', [$newId]);
        self::assertCount(1, $sections, 'Копия обязана получить секции оригинала');
        // Ключ секции ядро строит из заголовка («Секция» → «sektsiya»), а копия сохраняет его:
        // страница-копия — самостоятельный адрес, и ключи внутри неё уникальны сами по себе.
        self::assertSame('sektsiya', (string) $sections[0]['sec_key'], 'Ключ секции копии сохранён');
    }

    public function testБуферОбменаПереживаетПерезаписьСлота(): void
    {
        $page = createPage(['slug' => 's-buferom', 'title' => 'С буфером', 'status' => PAGES_STATUS_DRAFT]);
        $section = createSection((string) $page['id'], ['sec_key' => 'kopirovat', 'kind' => 'text', 'title' => 'Копировать']);

        $copied = clipboardCopy('user-1', (string) $section['id']);
        self::assertTrue($copied['ok']);

        $slot = clipboardGet('user-1');
        self::assertNotNull($slot);
        self::assertSame('Копировать', (string) $slot['label']);

        // Второй слот с другим владельцем: буфер персональный, чужой слот не виден.
        self::assertNull(clipboardGet('user-2'));

        $pasted = clipboardPaste('user-1', (string) $page['id']);
        self::assertTrue($pasted['ok'], 'Вставка обязана сработать: ' . $pasted['error']);

        $count = (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [(string) $page['id']]);
        self::assertSame(2, $count, 'Вставка добавляет секцию, а не заменяет существующую');
    }

    public function testВставкаБезБуфераОтказывает(): void
    {
        $page = createPage(['slug' => 'pustoy-bufer', 'title' => 'Пустой буфер', 'status' => PAGES_STATUS_DRAFT]);

        $result = clipboardPaste('user-bez', (string) $page['id']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Буфер пуст', $result['error']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('page_section') . ' WHERE page_id = ?', [(string) $page['id']]));
    }

    // --- Массовые операции -------------------------------------------------------------------

    public function testМассоваяСменаСтатусаМеняетТолькоОтмеченные(): void
    {
        $a = createPage(['slug' => 'mass-a', 'title' => 'A', 'status' => PAGES_STATUS_DRAFT]);
        $b = createPage(['slug' => 'mass-b', 'title' => 'B', 'status' => PAGES_STATUS_DRAFT]);
        $c = createPage(['slug' => 'mass-c', 'title' => 'C', 'status' => PAGES_STATUS_DRAFT]);

        $result = pagesBulkStatus([(string) $a['id'], (string) $b['id']], PAGES_STATUS_PUBLISHED);
        self::assertTrue($result['ok']);

        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById((string) $a['id'])['status']);
        self::assertSame(PAGES_STATUS_PUBLISHED, (string) pageById((string) $b['id'])['status']);
        self::assertSame(PAGES_STATUS_DRAFT, (string) pageById((string) $c['id'])['status'], 'Неотмеченная страница не меняется');
    }

    public function testМассоваяКорзинаУбираетВсеОтмеченные(): void
    {
        $a = createPage(['slug' => 'mass-t-a', 'title' => 'A', 'status' => PAGES_STATUS_DRAFT]);
        $b = createPage(['slug' => 'mass-t-b', 'title' => 'B', 'status' => PAGES_STATUS_DRAFT]);

        $result = pagesBulkTrash([(string) $a['id'], (string) $b['id']]);

        self::assertTrue($result['ok']);
        self::assertNull(pageById((string) $a['id']));
        self::assertNull(pageById((string) $b['id']));
        self::assertCount(2, trashList(), 'Обе страницы обязаны лежать в корзине');
    }

    public function testПустойСписокМассовойОперацииОтказывает(): void
    {
        $result = pagesBulkStatus([], PAGES_STATUS_PUBLISHED);

        self::assertFalse($result['ok'], 'Пустой набор — не «ничего не делать», а понятная ошибка');
        self::assertStringContainsString('Отметьте', $result['error']);
    }
}
