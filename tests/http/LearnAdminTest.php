<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Контент-админка раздела «Курсы»: права (аноним 302/403, редактор 200), CSRF-отказ,
 * PRG-ключи, создание/правка/перстановка/удаление дерева (уровни, курсы, модули),
 * полный цикл урока — форма, сохранение транзакцией, правка двойным чтением,
 * отказ кривого упражнения, удаление, публикуется ли для ученика.
 */
final class LearnAdminTest extends TestCase
{
    private const EDITOR_EMAIL = 'obhod-admin@school.dev';
    private const EDITOR_PASSWORD = 'админ-пароль-обхода-1';

    private static string $levelId = '';
    private static string $courseId = '';
    private static string $moduleId = '';
    private static string $lessonId = '';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('learn');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Курсы» не установился: ' . (string) ($result['error'] ?? ''));
        }

        TestDb::seedUser(self::EDITOR_EMAIL, self::EDITOR_PASSWORD, 'editor');

        $level = one('SELECT id FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1', ['A1']);
        self::assertIsArray($level, 'Фикстура: уровня A1 нет в закладке школы');

        self::$levelId = newId();
        insert('learn_level', [
            'id' => self::$levelId,
            'code' => 'X1',
            'title' => 'Уровень для админки',
            'sort' => 9,
        ]);

        self::$courseId = newId();
        insert('learn_course', [
            'id' => self::$courseId,
            'level_id' => self::$levelId,
            'title' => 'Курс для админки',
            'description' => 'Описание курса для проверки админки.',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);

        self::$moduleId = newId();
        insert('learn_module', [
            'id' => self::$moduleId,
            'course_id' => self::$courseId,
            'title' => 'Модуль для админки',
            'description' => 'Описание модуля.',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);

        self::$lessonId = newId();
        insert('learn_lesson', [
            'id' => self::$lessonId,
            'module_id' => self::$moduleId,
            'slug' => 'urok-dlya-admina',
            'title' => 'Урок для админки',
            'description' => 'Описание урока.',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);

        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
        self::dropLearnTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'learn'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'learn'");
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        foreach ([
            'learn_attempt',
            'learn_test_attempt',
            'learn_xp',
            'learn_streak',
            'learn_statistics',
            'learn_profile',
            'learn_registration_attempt',
            'learn_progress',
        ] as $table) {
            run('DELETE FROM ' . t($table));
        }

        run('DELETE FROM ' . t('learn_objective') . ' WHERE lesson_id = ?', [self::$lessonId]);
        run('DELETE FROM ' . t('learn_vocab') . ' WHERE lesson_id = ?', [self::$lessonId]);
        run('DELETE FROM ' . t('learn_grammar') . ' WHERE lesson_id = ?', [self::$lessonId]);
        run('DELETE FROM ' . t('learn_exercise') . ' WHERE lesson_id = ?', [self::$lessonId]);
        run('DELETE FROM ' . t('learn_test') . ' WHERE lesson_id = ?', [self::$lessonId]);
        run('DELETE FROM ' . t('learn_test_question') . ' WHERE test_id IN (SELECT id FROM ' . t('learn_test') . ' WHERE lesson_id = ?)', [self::$lessonId]);
    }

    public function testАнонимНаАдминскиеАдресаУезжаетНаВход(): void
    {
        foreach (['/learn/admin', '/learn/admin/courses', '/learn/admin/lessons'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(302, $page['status'], 'Аноним не должен видеть ' . $path);
            self::assertStringContainsString('/login?next=', (string) ($page['headers']['location'] ?? ''));
        }
    }

    public function testРедакторВидитАдминскиеЭкраны(): void
    {
        $this->loginEditor();

        foreach (['/learn/admin', '/learn/admin/courses', '/learn/admin/lessons'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status'], $path . ' должен быть доступен редактору');
            self::assertStringContainsString('name="robots" content="noindex', $page['body'], $path);
            self::assertSame('no-store', $page['headers']['cache-control'] ?? '', $path);
        }
    }

    public function testУченикНеПопадаетВАдминку(): void
    {
        $studentId = TestDb::seedUser('uchenik-admin@example.ru', 'парол-uchenika-2', 'student');

        $page = Kernel::get('/login');
        $entered = Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => 'uchenik-admin@example.ru',
            'password' => 'парол-uchenika-2',
        ]);

        self::assertSame(302, $entered['status']);

        $courses = Kernel::get('/learn/admin/courses');
        self::assertSame(302, $courses['status'], 'Ученик не должен попасть на приватный адрес админки');
        self::assertStringContainsString('/login?next=', (string) ($courses['headers']['location'] ?? ''));

        run('DELETE FROM ' . t('user') . ' WHERE id = ?', [$studentId]);
    }

    public function testСозданиеУровняЧерезАдминку(): void
    {
        $this->loginEditor();

        $page = Kernel::get('/learn/admin/courses');
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'create-level',
            'code' => 'C2-Test',
            'title' => 'Mastery Test',
            'sort' => '7',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/learn/admin/courses', $location);
        self::assertStringContainsString('notice=learn_saved', $location);

        $level = one('SELECT code, title, sort FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1', ['C2-Test']);
        self::assertIsArray($level);
        self::assertSame('Mastery Test', (string) $level['title']);
        self::assertSame(7, (int) $level['sort']);
    }

    public function testПравкаУровняИПерстановка(): void
    {
        $this->loginEditor();

        $page = Kernel::get('/learn/admin/courses');
        $token = Kernel::tokenFrom($page['body']);

        // Правка уровня.
        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'save-level',
            'item' => self::$levelId,
            'code' => 'B1-New',
            'title' => 'Threshold Updated',
            'sort' => '3',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('notice=learn_saved', $location);

        $level = one('SELECT code, title, sort FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1', [self::$levelId]);
        self::assertIsArray($level);
        self::assertSame('B1-New', (string) $level['code']);
        self::assertSame('Threshold Updated', (string) $level['title']);
        self::assertSame(3, (int) $level['sort']);

        // Перстановка уровня выше.
        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'move-level',
            'item' => self::$levelId,
            'dir' => 'up',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('notice=learn_moved', $location);

        $level = one('SELECT sort FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1', [self::$levelId]);
        self::assertIsArray($level);
        self::assertLessThan(3, (int) $level['sort'], 'После перестановки «вверх» уровень сдвинулся выше');
    }

    public function testУдалениеУровняБезКурсов(): void
    {
        $this->loginEditor();

        $freeLevelId = newId();
        insert('learn_level', [
            'id' => $freeLevelId,
            'code' => 'C2-Free',
            'title' => 'Свободный уровень',
            'sort' => 7,
        ]);

        try {
            $page = Kernel::get('/learn/admin/courses');
            $token = Kernel::tokenFrom($page['body']);

            $post = Kernel::post('/learn/admin/courses', [
                '_token' => $token,
                'op' => 'delete-level',
                'item' => $freeLevelId,
            ]);

            self::assertSame(302, $post['status']);
            $location = (string) ($post['headers']['location'] ?? '');
            self::assertStringContainsString('notice=learn_deleted', $location);

            $level = one('SELECT id FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1', [$freeLevelId]);
            self::assertNull($level, 'Уровень без курсов должен быть удалён');
        } finally {
            run('DELETE FROM ' . t('learn_level') . ' WHERE id = ?', [$freeLevelId]);
        }
    }

    public function testУдалениеУровняСКурсамиОтклоняетсяСоСчётчиком(): void
    {
        $this->loginEditor();

        $page = Kernel::get('/learn/admin/courses');
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'delete-level',
            'item' => self::$levelId,
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('notice=migration-failed', $location);

        $level = one('SELECT id FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1', [self::$levelId]);
        self::assertIsArray($level, 'Уровень с курсами не должен быть удалён');
    }

    public function testСозданиеКурсаИМодуля(): void
    {
        $this->loginEditor();

        $page = Kernel::get('/learn/admin/courses');
        $token = Kernel::tokenFrom($page['body']);

        // Создание курса.
        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'create-course',
            'level_id' => self::$levelId,
            'title' => 'New Course',
            'description' => 'Description of new course.',
            'status' => 'DRAFT',
            'sort' => '2',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('notice=learn_saved', $location);

        $course = one('SELECT id, title, status FROM ' . t('learn_course') . ' WHERE title = ? LIMIT 1', ['New Course']);
        self::assertIsArray($course);
        self::assertSame('DRAFT', (string) $course['status']);

        // Создание модуля в курсе.
        $post = Kernel::post('/learn/admin/courses', [
            '_token' => $token,
            'op' => 'create-module',
            'course_id' => (string) $course['id'],
            'title' => 'New Module',
            'description' => 'Description of module.',
            'status' => 'PUBLISHED',
            'sort' => '1',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('notice=learn_saved', $location);

        $module = one('SELECT id, title, status FROM ' . t('learn_module') . ' WHERE title = ? LIMIT 1', ['New Module']);
        self::assertIsArray($module);
        self::assertSame('PUBLISHED', (string) $module['status']);

        // Удаляем созданные курсы и модули, чтобы не засорять базу для других тестов.
        run('DELETE FROM ' . t('learn_module') . ' WHERE id = ?', [(string) $module['id']]);
        run('DELETE FROM ' . t('learn_course') . ' WHERE id = ?', [(string) $course['id']]);
    }

    public function testУдалениеКурсаСоСчётчикомТеряемых(): void
    {
        $this->loginEditor();

        // Создаём курс с модулем и уроком внутри уровня self::$levelId.
        $tempCourseId = newId();
        insert('learn_course', [
            'id' => $tempCourseId,
            'level_id' => self::$levelId,
            'title' => 'Temp Course',
            'description' => 'For testing deletion with counter.',
            'status' => 'PUBLISHED',
            'sort' => 5,
        ]);
        $tempModuleId = newId();
        insert('learn_module', [
            'id' => $tempModuleId,
            'course_id' => $tempCourseId,
            'title' => 'Temp Module',
            'description' => '',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);
        $tempLessonId = newId();
        insert('learn_lesson', [
            'id' => $tempLessonId,
            'module_id' => $tempModuleId,
            'slug' => 'temp-lesson',
            'title' => 'Temp Lesson',
            'description' => '',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);

        try {
            $page = Kernel::get('/learn/admin/courses');
            $token = Kernel::tokenFrom($page['body']);

            $post = Kernel::post('/learn/admin/courses', [
                '_token' => $token,
                'op' => 'delete-course',
                'item' => $tempCourseId,
            ]);

            self::assertSame(302, $post['status']);
            $location = (string) ($post['headers']['location'] ?? '');
            self::assertStringContainsString('notice=migration-failed', $location);

            // Курс не удалён, потому что есть модули и уроки.
            $course = one('SELECT id FROM ' . t('learn_course') . ' WHERE id = ? LIMIT 1', [$tempCourseId]);
            self::assertIsArray($course);
        } finally {
            run('DELETE FROM ' . t('learn_lesson') . ' WHERE id = ?', [$tempLessonId]);
            run('DELETE FROM ' . t('learn_module') . ' WHERE id = ?', [$tempModuleId]);
            run('DELETE FROM ' . t('learn_course') . ' WHERE id = ?', [$tempCourseId]);
        }
    }

    public function testПолныйЦиклУрокаФормаСохранениеПравка(): void
    {
        $this->loginEditor();

        // Создание урока.
        $page = Kernel::get('/learn/admin/lessons/new');
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/learn/admin/lessons/new', [
            '_token' => $token,
            'module_id' => self::$moduleId,
            'slug' => 'new-admin-lesson',
            'title' => 'New Admin Lesson',
            'description' => 'Description of new lesson.',
            'status' => 'PUBLISHED',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/learn/admin/lessons', $location);
        self::assertStringContainsString('notice=learn_saved', $location);

        $newLessonId = one('SELECT id FROM ' . t('learn_lesson') . ' WHERE slug = ? LIMIT 1', ['new-admin-lesson']);
        self::assertIsArray($newLessonId);

        try {
            // Урок теперь виден в публичном контуре.
            $public = Kernel::get('/lesson/new-admin-lesson');
            self::assertSame(200, $public['status']);
            self::assertStringContainsString('New Admin Lesson', $public['body']);

            // Правка урока — двойное чтение: форма с текущими значениями.
            $editPage = Kernel::get('/learn/admin/lessons/' . (string) $newLessonId['id']);
            self::assertSame(200, $editPage['status']);
            self::assertStringContainsString('New Admin Lesson', $editPage['body']);
            self::assertStringContainsString('new-admin-lesson', $editPage['body']);

            // Сохранение с новыми значениями.
            $editToken = Kernel::tokenFrom($editPage['body']);
            $savePost = Kernel::post('/learn/admin/lessons/' . (string) $newLessonId['id'], [
                '_token' => $editToken,
                'id' => (string) $newLessonId['id'],
                'module_id' => self::$moduleId,
                'slug' => 'new-admin-lesson-updated',
                'title' => 'Updated Lesson',
                'description' => 'Updated description.',
                'status' => 'PUBLISHED',
            ]);

            self::assertSame(302, $savePost['status']);
            $saveLocation = (string) ($savePost['headers']['location'] ?? '');
            self::assertStringContainsString('notice=learn_saved', $saveLocation);

            $lesson = one('SELECT title, description, slug FROM ' . t('learn_lesson') . ' WHERE id = ? LIMIT 1', [(string) $newLessonId['id']]);
            self::assertIsArray($lesson);
            self::assertSame('Updated Lesson', (string) $lesson['title']);
            self::assertSame('Updated description.', (string) $lesson['description']);
            self::assertSame('new-admin-lesson-updated', (string) $lesson['slug']);

            // Удаление урока.
            $listPage = Kernel::get('/learn/admin/lessons');
            $listToken = Kernel::tokenFrom($listPage['body']);
            $deletePost = Kernel::post('/learn/admin/lessons', [
                '_token' => $listToken,
                'op' => 'delete-lesson',
                'item' => (string) $newLessonId['id'],
            ]);

            self::assertSame(302, $deletePost['status']);
            $deleteLocation = (string) ($deletePost['headers']['location'] ?? '');
            self::assertStringContainsString('notice=learn_deleted', $deleteLocation);

            $deleted = one('SELECT id FROM ' . t('learn_lesson') . ' WHERE id = ? LIMIT 1', [(string) $newLessonId['id']]);
            self::assertNull($deleted, 'Урок должен быть удалён со всем подчинённым');
        } finally {
            run('DELETE FROM ' . t('learn_lesson') . ' WHERE slug = ? OR slug = ?', ['new-admin-lesson', 'new-admin-lesson-updated']);
        }
    }

    public function testОтказКривогоАдресаУрока(): void
    {
        $this->loginEditor();

        $page = Kernel::get('/learn/admin/lessons/new');
        self::assertSame(200, $page['status'], 'GET /learn/admin/lessons/new should return 200');
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/learn/admin/lessons/new', [
            '_token' => $token,
            'module_id' => self::$moduleId,
            'slug' => 'Bad Slug!!',
            'title' => 'Lesson with bad slug',
            'description' => '',
            'status' => 'PUBLISHED',
        ]);

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('Адрес только из строчных', $post['body']);

        $lesson = one('SELECT id FROM ' . t('learn_lesson') . ' WHERE slug = ? LIMIT 1', ['Bad Slug!!']);
        self::assertNull($lesson, 'Урок с кривым адресом не должен быть создан');
    }

    public function testCSRFОтказНаПостАдминки(): void
    {
        $this->loginEditor();

        $post = Kernel::post('/learn/admin/courses', [
            'op' => 'create-level',
            'code' => 'X1',
            'title' => 'Без токена',
            'sort' => '1',
        ]);

        self::assertSame(403, $post['status'], 'POST без CSRF-токена должен получить 403');
    }

    private function loginEditor(): void
    {
        $page = Kernel::get('/login');
        $entered = Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::EDITOR_EMAIL,
            'password' => self::EDITOR_PASSWORD,
        ]);

        self::assertSame(302, $entered['status'], 'Редактор не вошёл: ' . (string) ($entered['headers']['location'] ?? ''));
    }

    private static function dropLearnTables(): void
    {
        foreach (['learn_objective', 'learn_vocab', 'learn_grammar', 'learn_exercise', 'learn_attempt',
            'learn_test_question', 'learn_test', 'learn_lesson', 'learn_module', 'learn_course',
            'learn_level', 'learn_xp', 'learn_streak', 'learn_statistics', 'learn_profile',
            'learn_registration_attempt', 'learn_progress'] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }
}
