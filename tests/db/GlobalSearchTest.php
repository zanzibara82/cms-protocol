<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Сквозной поиск на настоящей базе: группы источников, экранирование шаблона, отсутствующие
 * таблицы приложений и личные данные заявок.
 *
 * Личные данные — главное свойство: экран поиска открыт редактору, но контакты людей ему
 * не показываются. Право manage проверяется в самой функции, а не только на маршруте.
 */
final class GlobalSearchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('lead');
        TestDb::clearTable('lead_attempt');
        TestDb::clearTable('media');
        run('DELETE FROM ' . t('page_section'));
        run('DELETE FROM ' . t('page'));
    }

    public function testКороткийЗапросНичегоНеИщет(): void
    {
        createPage(['slug' => 'o-shkole', 'title' => 'О школе', 'status' => PAGES_STATUS_PUBLISHED]);

        foreach (['', 'о', ' о '] as $query) {
            $result = globalSearch($query);

            self::assertSame(0, $result['total'], 'Запрос короче SEARCH_MIN не ищет: «' . $query . '»');
            self::assertSame([], $result['groups']);
        }
    }

    public function testСтраницаНаходитсяПоЗаголовкуИАдресу(): void
    {
        $page = createPage(['slug' => 'kursy-anglijskogo', 'title' => 'Курсы английского', 'status' => PAGES_STATUS_PUBLISHED]);

        $byTitle = globalSearch('английского');
        self::assertSame(1, $byTitle['total']);
        self::assertSame('Страницы', $byTitle['groups'][0]['title']);
        self::assertSame('/admin/pages/' . (string) $page['id'], $byTitle['groups'][0]['items'][0]['href']);

        $bySlug = globalSearch('kursy-anglijskogo');
        self::assertSame(1, $bySlug['total'], 'Поиск по адресу тоже работает');
    }

    // «100%» обязан искаться как текст: без ESCAPE знак процента стал бы шаблоном и нашёл бы всё.
    public function testЗнакПроцентаИщетсяКакТекст(): void
    {
        createPage(['slug' => 'skidka-100', 'title' => 'Скидка 100%', 'status' => PAGES_STATUS_PUBLISHED]);
        createPage(['slug' => 'skidka-50', 'title' => 'Скидка 50 процентов', 'status' => PAGES_STATUS_PUBLISHED]);

        $result = globalSearch('100%');

        self::assertSame(1, $result['total'], 'Процент — часть текста, а не шаблон «что угодно»');
        self::assertSame('Скидка 100%', $result['groups'][0]['items'][0]['title']);
    }

    public function testПодчёркиваниеИщетсяКакТекст(): void
    {
        createPage(['slug' => 'a-b', 'title' => 'Файл a_b', 'status' => PAGES_STATUS_PUBLISHED]);
        createPage(['slug' => 'a-x-b', 'title' => 'Файл axb', 'status' => PAGES_STATUS_DRAFT]);

        $result = globalSearch('a_b');

        self::assertSame(1, $result['total'], 'Подчёркивание не должно означать «любой символ»');
        self::assertSame('Файл a_b', $result['groups'][0]['items'][0]['title']);
    }

    // Голая установка: таблиц приложений нет, и это не ошибка. Иначе поиск отдавал бы 500
    // на сайте, где раздел ещё не установлен.
    public function testОтсутствующиеТаблицыПриложенийНеЛомаютПоиск(): void
    {
        createPage(['slug' => 'edinstvennaya', 'title' => 'Единственная страница', 'status' => PAGES_STATUS_PUBLISHED]);

        $result = globalSearch('Единственная');

        self::assertSame(1, $result['total']);
        self::assertSame(['Страницы'], array_column($result['groups'], 'title'), 'Группа только одна — приложений нет');
    }

    public function testМедиатекаПопадаетВГруппу(): void
    {
        $moment = utcStamp(nowUtc());
        insert('media', [
            'id' => newId(),
            'path' => PAGE_IMAGE_PREFIX . 'osen.png',
            'title' => 'Осенний фон',
            'alt' => 'Листва',
            'mime' => 'image/png',
            'width' => 10,
            'height' => 10,
            'bytes' => 100,
            'uploaded_by' => null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ]);

        $result = globalSearch('Осенний');

        self::assertSame(1, $result['total']);
        self::assertSame('Медиафайлы', $result['groups'][0]['title']);
        self::assertStringContainsString('/admin/media?q=', $result['groups'][0]['items'][0]['href']);
    }

    // Редактор не видит заявок: контакты людей — право manage.
    public function testРедакторНеВидитЗаявок(): void
    {
        leadCreate(['name' => 'Мария', 'contact' => '+7 900 555-44-33', 'message' => '', 'source' => '/'], nowUtc());

        $result = globalSearch('Мария');

        self::assertSame(0, $result['total'], 'Без права manage заявки в поиск не попадают');
    }

    // Администратор видит заявки отдельной группой.
    public function testАдминистраторВидитЗаявки(): void
    {
        $adminId = TestDb::seedUser('admin@test.ru', 'adminpass123', 'admin');
        $admin = one('SELECT id, email, name, role, session_version FROM ' . t('user') . ' WHERE id = ?', [$adminId]);
        self::assertNotNull($admin);
        self::assertTrue(roleGrantsManage($admin), 'Роль admin обязана давать право manage');

        leadCreate(['name' => 'Мария', 'contact' => '+7 900 555-44-33', 'message' => '', 'source' => '/'], nowUtc());

        // Сеанс поднимается тем же помощником, что и на входе: currentUser() читает cookie и базу,
        // подкладывать массив в $_SESSION бессмысленно.
        loginUser($admin);

        try {
            $result = globalSearch('Мария');
        } finally {
            logoutUser();
            TestDb::clearTable('user');
        }

        self::assertSame(1, $result['total']);
        self::assertSame('Заявки', $result['groups'][0]['title']);
        self::assertStringContainsString('/admin/leads?q=', $result['groups'][0]['items'][0]['href']);
    }

    // Один источник не заваливает выдачу: на источник отводится SEARCH_PER_SOURCE строк.
    public function testИсточникОтдаётНеБольшеПределаСтрок(): void
    {
        for ($n = 1; $n <= SEARCH_PER_SOURCE + 5; $n++) {
            createPage([
                'slug' => 'proba-' . $n,
                'title' => 'Общая проба ' . $n,
                'status' => PAGES_STATUS_DRAFT,
            ]);
        }

        $result = globalSearch('Общая проба');
        $pages = $result['groups'][0]['items'];

        self::assertCount(SEARCH_PER_SOURCE, $pages, 'На источник — не больше SEARCH_PER_SOURCE строк');
    }
}
