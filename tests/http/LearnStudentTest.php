<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Ученический контур через настоящий HTTP: вход в раздел, дерево курса, модуль с замками,
 * урок шагами и его POST-жизнь (попытки упражнений, мини-тест и пересдача).
 */
final class LearnStudentTest extends TestCase
{
    private const STUDENT_EMAIL = 'uchenik@example.ru';
    private const STUDENT_PASSWORD = 'parol-uchenika-1';

    private static string $studentId = '';
    private static string $courseId = '';
    private static string $moduleAId = '';
    private static string $moduleBId = '';
    private static string $lessonOneId = '';
    private static string $lessonTwoId = '';
    private static string $exerciseChoiceId = '';
    private static string $exerciseFillId = '';
    private static string $exerciseMatchingId = '';
    private static string $exerciseBrainstormId = '';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        TestDb::clearTable('user');

        $result = installApp('learn');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Курсы» не установился: ' . (string) ($result['error'] ?? ''));
        }

        self::$studentId = TestDb::seedUser(self::STUDENT_EMAIL, self::STUDENT_PASSWORD, 'student');

        $level = one('SELECT id FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1', ['A1']);
        self::assertIsArray($level, 'Фикстура: уровня A1 нет в закладке школы');

        self::$courseId = newId();
        insert('learn_course', [
            'id' => self::$courseId,
            'level_id' => (string) $level['id'],
            'title' => 'Английский шаг за шагом',
            'description' => 'Курс для проверки ученического контура.',
            'status' => 'PUBLISHED',
            'sort' => 1,
        ]);

        self::$moduleAId = self::addModule('Знакомство', 'Первые фразы', 1);
        self::$moduleBId = self::addModule('Семья', 'Родные и близкие', 2);

        self::$lessonOneId = self::addLesson(self::$moduleAId, 'hello', 'Приветствие', 'Учимся здороваться.', 1);
        self::$lessonTwoId = self::addLesson(self::$moduleAId, 'how-are-you', 'Как дела', 'Спрашиваем о настроении.', 2);
        self::addLesson(self::$moduleBId, 'family', 'Семья', 'Рассказываем о родных.', 1);

        self::addLessonContent(self::$lessonOneId);
        self::addMiniTest(self::$lessonOneId);
        // Последнему уроку модуля тоже нужен тест: на нём проверяется бонус закрытия модуля.
        self::addMiniTest(self::$lessonTwoId);

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

        foreach (['learn_attempt', 'learn_test_attempt', 'learn_xp', 'learn_streak', 'learn_statistics'] as $table) {
            run('DELETE FROM ' . t($table));
        }

        run('DELETE FROM ' . t('learn_progress') . ' WHERE user_id = ?', [self::$studentId]);
    }

    public function testАнонимНаУченическиеАдресаУезжаетНаВходСГлубиннойСсылкой(): void
    {
        foreach ([
            '/learn',
            '/learn/module/' . self::$moduleAId,
            '/lesson/hello',
        ] as $path) {
            $page = Kernel::get($path);

            self::assertSame(302, $page['status'], 'Аноним не должен видеть ученический адрес ' . $path);
            self::assertStringContainsString('/login?next=', (string) ($page['headers']['location'] ?? ''));
            self::assertStringContainsString(rawurlencode($path), (string) ($page['headers']['location'] ?? ''));
        }
    }

    public function testБезОпубликованногоКурсаРазделГоворитЧтоКурсНеНазначен(): void
    {
        $this->login();

        run('UPDATE ' . t('learn_course') . ' SET status = ? WHERE id = ?', ['DRAFT', self::$courseId]);

        try {
            $page = Kernel::get('/learn');

            self::assertSame(200, $page['status']);
            self::assertStringContainsString('Курс пока не назначен', $page['body']);
        } finally {
            run('UPDATE ' . t('learn_course') . ' SET status = ? WHERE id = ?', ['PUBLISHED', self::$courseId]);
        }
    }

    public function testДеревоКурсаПоказываетМодулиСПроцентами(): void
    {
        $this->login();
        $this->markCompleted([self::$lessonOneId]);

        $page = Kernel::get('/learn');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Английский шаг за шагом', $page['body']);
        self::assertStringContainsString('A1', $page['body']);
        self::assertStringContainsString('Модуль 1: Знакомство', $page['body']);
        self::assertStringContainsString('Модуль 2: Семья', $page['body']);
        self::assertStringContainsString('1/2', $page['body']);
        self::assertStringContainsString('50%', $page['body']);
        self::assertStringContainsString('0/1', $page['body']);
        self::assertStringContainsString('0%', $page['body']);
        self::assertStringContainsString('/learn/module/' . self::$moduleAId, $page['body']);
        self::assertStringContainsString('/learn/module/' . self::$moduleBId, $page['body']);
    }

    public function testМодульПоказываетГалочкиИЗамкиУроков(): void
    {
        $this->login();

        $page = Kernel::get('/learn/module/' . self::$moduleAId);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Модуль 1: Знакомство', $page['body']);
        self::assertStringContainsString('Прогресс: 0 из 2 уроков', $page['body']);
        self::assertStringContainsString('Приветствие', $page['body']);
        self::assertStringContainsString('href="/lesson/hello"', $page['body']);
        self::assertStringContainsString('Как дела', $page['body']);
        self::assertStringNotContainsString('href="/lesson/how-are-you"', $page['body']);
        self::assertStringContainsString('Урок закрыт', $page['body']);

        $this->markCompleted([self::$lessonOneId]);

        $page = Kernel::get('/learn/module/' . self::$moduleAId);

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Прогресс: 1 из 2 уроков', $page['body']);
        self::assertStringContainsString('Пройден', $page['body']);
        self::assertStringContainsString('href="/lesson/how-are-you"', $page['body']);
    }

    public function testУрокОткрываетсяШагамиЦелейСловИГрамматики(): void
    {
        $this->login();

        $intro = Kernel::get('/lesson/hello?step=intro');
        self::assertSame(200, $intro['status']);
        self::assertStringContainsString('Урок 1. Приветствие', $intro['body']);
        self::assertStringContainsString('Модуль 1: Знакомство', $intro['body']);
        self::assertStringContainsString('Поздороваться по-английски', $intro['body']);
        self::assertStringContainsString('Представиться по имени', $intro['body']);

        $vocab = Kernel::get('/lesson/hello?step=vocab');
        self::assertSame(200, $vocab['status']);
        self::assertStringContainsString('hello', $vocab['body']);
        self::assertStringContainsString('привет', $vocab['body']);
        self::assertStringContainsString('/həˈləʊ/', $vocab['body']);
        self::assertStringContainsString('Hello, Anna!', $vocab['body']);

        $grammar = Kernel::get('/lesson/hello?step=grammar');
        self::assertSame(200, $grammar['status']);
        self::assertStringContainsString('Глагол to be', $grammar['body']);
        self::assertStringContainsString('I am, you are, he is', $grammar['body']);
        self::assertStringContainsString('I am Anna.', $grammar['body']);
    }

    public function testШагУпражненийПечатаетФормыПоТипу(): void
    {
        $this->login();

        $choice = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseChoiceId);
        self::assertSame(200, $choice['status']);
        self::assertStringContainsString('Как поздороваться?', $choice['body']);
        self::assertStringContainsString('type="radio"', $choice['body']);
        self::assertStringContainsString('Hello', $choice['body']);

        $fill = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseFillId);
        self::assertSame(200, $fill['status']);
        self::assertStringContainsString('Вставьте: My ___ is Tom.', $fill['body']);
        self::assertStringContainsString('name="answer"', $fill['body']);

        $matching = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseMatchingId);
        self::assertSame(200, $matching['status']);
        self::assertStringContainsString('Соедините слова и переводы.', $matching['body']);
        self::assertStringContainsString('<select', $matching['body']);
        self::assertStringContainsString('name="answer[hello]"', $matching['body']);

        $brainstorm = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseBrainstormId);
        self::assertSame(200, $brainstorm['status']);
        self::assertStringContainsString('Назовите три приветствия.', $brainstorm['body']);
        self::assertStringContainsString('<textarea', $brainstorm['body']);
    }

    public function testШагМиниТестаПечатаетВсеВопросы(): void
    {
        $this->login();

        $page = Kernel::get('/lesson/hello?step=test');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Мини-тест урока', $page['body']);
        self::assertStringContainsString('Выберите приветствие', $page['body']);
        self::assertStringContainsString('Hello', $page['body']);
        self::assertStringContainsString('Вставьте: I ___ Anna.', $page['body']);
        self::assertStringContainsString('name="answer[', $page['body']);
        self::assertStringContainsString('Проверить', $page['body']);
    }

    public function testПовторноеОткрытиеУрокаПродолжаетСМестаОстановки(): void
    {
        $this->login();
        $this->addAttempt(self::$exerciseChoiceId, true);

        $page = Kernel::get('/lesson/hello');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вставьте: My ___ is Tom.', $page['body']);
        self::assertStringContainsString('Упражнение 2 из 4', $page['body']);
        self::assertStringContainsString('К грамматике', $page['body']);
    }

    public function testУрокиСледующихМодулейЗакрытыДоЗавершенияПредыдущего(): void
    {
        $this->login();

        $locked = Kernel::get('/lesson/how-are-you');
        self::assertSame(200, $locked['status']);
        self::assertStringContainsString('Урок закрыт', $locked['body']);
        self::assertStringContainsString('Сначала завершите предыдущий урок модуля.', $locked['body']);
        self::assertStringContainsString('/learn/module/' . self::$moduleAId, $locked['body']);

        $nextModule = Kernel::get('/lesson/family');
        self::assertSame(200, $nextModule['status']);
        self::assertStringContainsString('Урок закрыт', $nextModule['body']);

        $this->markCompleted([self::$lessonOneId, self::$lessonTwoId]);

        $open = Kernel::get('/lesson/family');
        self::assertSame(200, $open['status']);
        self::assertStringContainsString('Урок 1. Семья', $open['body']);
        self::assertStringNotContainsString('Сначала завершите предыдущий урок модуля.', $open['body']);
    }

    public function testУченическиеЭкраныЗапрещаютИндексациюИХранение(): void
    {
        $this->login();

        foreach (['/learn', '/learn/module/' . self::$moduleAId, '/lesson/hello'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status'], $path);
            self::assertStringContainsString('name="robots" content="noindex', $page['body'], $path);
            self::assertSame('no-store', $page['headers']['cache-control'] ?? '', $path);
        }
    }

    public function testВернаяПопыткаУпражненияДаётПлашкуИОпытОдинРаз(): void
    {
        $this->login();

        $page = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseChoiceId);
        $token = Kernel::tokenFrom($page['body']);
        self::assertNotSame('', $token, 'Форма упражнения не напечатала CSRF-токен');

        $post = Kernel::post('/lesson/hello', [
            '_token' => $token,
            'op' => 'answer',
            'exercise' => self::$exerciseChoiceId,
            'answer' => '0',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/lesson/hello?step=exercises', $location);
        self::assertStringContainsString('exercise=' . self::$exerciseChoiceId, $location);

        $follow = Kernel::get($location);

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Верно', $follow['body']);
        self::assertStringContainsString('+7 XP', $follow['body']);
        self::assertStringContainsString('Дальше', $follow['body']);
        self::assertSame(7, $this->xpTotal());
        self::assertSame(1, $this->attemptCount(self::$exerciseChoiceId, true));

        // Повторная верная попытка проходит, но опыт не начисляет: плашки XP больше нет.
        $again = Kernel::post('/lesson/hello', [
            '_token' => $token,
            'op' => 'answer',
            'exercise' => self::$exerciseChoiceId,
            'answer' => '0',
        ]);

        self::assertSame(302, $again['status']);

        $againPage = Kernel::get((string) ($again['headers']['location'] ?? ''));

        self::assertSame(200, $againPage['status']);
        self::assertStringContainsString('Ответ засчитан', $againPage['body']);
        self::assertStringNotContainsString('+7 XP', $againPage['body']);
        self::assertSame(7, $this->xpTotal());
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_attempt') . ' WHERE user_id = ? AND exercise_id = ? AND xp_awarded > 0',
            [self::$studentId, self::$exerciseChoiceId]
        ));
    }

    public function testНевернаяПопыткаПоказываетПравильныйОтветИРазбор(): void
    {
        $this->login();

        $page = Kernel::get('/lesson/hello?step=exercises&exercise=' . self::$exerciseChoiceId);
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/lesson/hello', [
            '_token' => $token,
            'op' => 'answer',
            'exercise' => self::$exerciseChoiceId,
            'answer' => '1',
        ]);

        self::assertSame(302, $post['status']);

        $follow = Kernel::get((string) ($post['headers']['location'] ?? ''));

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Не верно', $follow['body']);
        self::assertStringContainsString('Правильный ответ', $follow['body']);
        self::assertStringContainsString('Hello', $follow['body']);
        self::assertStringContainsString('Разбор упражнения.', $follow['body']);
        self::assertSame(0, $this->xpTotal());
        self::assertSame(1, $this->attemptCount(self::$exerciseChoiceId, false));
    }

    public function testСдачаМиниТестаПоказываетИтогИРазборВопросов(): void
    {
        $this->login();

        $page = Kernel::get('/lesson/hello?step=test');
        $token = Kernel::tokenFrom($page['body']);
        self::assertNotSame('', $token, 'Форма мини-теста не напечатала CSRF-токен');

        $answers = [];
        foreach ($this->questionIds(self::$lessonOneId) as $questionId) {
            $answers['answer[' . $questionId . ']'] = '0';
        }

        $post = Kernel::post('/lesson/hello', array_merge([
            '_token' => $token,
            'op' => 'test',
        ], $answers));

        self::assertSame(302, $post['status']);
        self::assertStringContainsString('/lesson/hello?step=test', (string) ($post['headers']['location'] ?? ''));

        $follow = Kernel::get((string) ($post['headers']['location'] ?? ''));

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('100%', $follow['body']);
        self::assertStringContainsString('Тест сдан', $follow['body']);
        self::assertStringContainsString('+60 XP', $follow['body']);
        self::assertStringContainsString('Hello — приветствие.', $follow['body']);
        self::assertStringContainsString('С I глагол to be стоит в форме am.', $follow['body']);
        self::assertStringContainsString('К результату урока', $follow['body']);
        self::assertStringContainsString('Пройти заново', $follow['body']);

        self::assertSame(60, $this->xpTotal());
        self::assertSame(1, $this->completedLessonCount());

        $attempt = one(
            'SELECT score, passed, answers FROM ' . t('learn_test_attempt') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );

        self::assertNotNull($attempt);
        self::assertSame(100, (int) $attempt['score']);
        self::assertSame(1, (int) $attempt['passed']);
        $detailed = json_decode((string) $attempt['answers'], true);
        self::assertIsArray($detailed);
        self::assertCount(2, $detailed);
        self::assertTrue($detailed[0]['correct'] ?? false);

        $result = Kernel::get('/lesson/hello?step=result');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Урок засчитан.', $result['body']);
        self::assertStringContainsString('Бронзовый кубок', $result['body']);
        self::assertStringContainsString('Начислено за урок: +60 XP', $result['body']);
        self::assertStringContainsString('Мини-тест урока: 100%', $result['body']);
        self::assertStringContainsString('/lesson/how-are-you', $result['body']);
    }

    public function testПересдачаМиниТестаНеНачисляетОпытПовторно(): void
    {
        $this->login();

        $first = Kernel::get('/lesson/hello?step=test');
        $token = Kernel::tokenFrom($first['body']);
        $answers = [];
        foreach ($this->questionIds(self::$lessonOneId) as $questionId) {
            $answers['answer[' . $questionId . ']'] = '0';
        }

        $fields = array_merge([
            '_token' => $token,
            'op' => 'test',
        ], $answers);

        $first = Kernel::post('/lesson/hello', $fields);
        self::assertSame(302, $first['status']);
        $firstResult = Kernel::get((string) ($first['headers']['location'] ?? ''));
        self::assertStringContainsString('Тест сдан', $firstResult['body']);

        // Следующий GET уже без flash: форма снова открыта, токен тот же.
        $secondPage = Kernel::get('/lesson/hello?step=test');
        $secondToken = Kernel::tokenFrom($secondPage['body']);
        self::assertNotSame('', $secondToken);

        $second = Kernel::post('/lesson/hello', array_merge($fields, ['_token' => $secondToken]));

        self::assertSame(302, $second['status']);

        $follow = Kernel::get((string) ($second['headers']['location'] ?? ''));

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Повторная сдача урока опыт не начисляет.', $follow['body']);
        self::assertSame(60, $this->xpTotal());
        self::assertSame(1, $this->completedLessonCount());
        self::assertSame(2, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_test_attempt') . ' WHERE user_id = ?',
            [self::$studentId]
        ));
    }

    public function testПоследнийУрокМодуляЗакрываетМодульИДаётБонус(): void
    {
        $this->login();
        $this->markCompleted([self::$lessonOneId]);

        $page = Kernel::get('/lesson/how-are-you?step=test');
        self::assertSame(200, $page['status']);
        $token = Kernel::tokenFrom($page['body']);

        $answers = [];
        foreach ($this->questionIds(self::$lessonTwoId) as $questionId) {
            $answers['answer[' . $questionId . ']'] = '0';
        }

        $post = Kernel::post('/lesson/how-are-you', array_merge([
            '_token' => $token,
            'op' => 'test',
        ], $answers));

        self::assertSame(302, $post['status']);

        $follow = Kernel::get((string) ($post['headers']['location'] ?? ''));

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Тест сдан', $follow['body']);
        self::assertStringContainsString('+160 XP', $follow['body']);
        self::assertStringContainsString('Бонус модуля', $follow['body']);
        self::assertSame(160, $this->xpTotal());

        $progress = one(
            'SELECT completed_lessons, completed_modules FROM ' . t('learn_progress') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );

        self::assertNotNull($progress);
        $modules = json_decode((string) $progress['completed_modules'], true);
        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertSame(self::$moduleAId, (string) ($modules[0]['moduleId'] ?? ''));

        $result = Kernel::get('/lesson/how-are-you?step=result');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Модуль закрыт целиком', $result['body']);
    }

    public function testОтказПроверкиCsrfНаПостеУрока(): void
    {
        $this->login();

        $post = Kernel::post('/lesson/hello', [
            'op' => 'answer',
            'exercise' => self::$exerciseChoiceId,
            'answer' => '0',
        ]);

        self::assertSame(403, $post['status']);
        self::assertStringContainsString('Проверка формы не прошла', $post['body']);
        self::assertSame(0, $this->attemptCount(self::$exerciseChoiceId, true));
        self::assertSame(0, $this->xpTotal());
    }

    private function login(): void
    {
        $page = Kernel::get('/login');
        $entered = Kernel::post('/login', [
            '_token' => Kernel::tokenFrom($page['body']),
            'email' => self::STUDENT_EMAIL,
            'password' => self::STUDENT_PASSWORD,
            'next' => '/learn',
        ]);

        self::assertSame(302, $entered['status'], 'Ученик не вошёл: ' . (string) ($entered['headers']['location'] ?? ''));
    }

    /** @param list<string> $lessonIds */
    private function markCompleted(array $lessonIds): void
    {
        $completed = [];
        foreach ($lessonIds as $lessonId) {
            $completed[] = ['lessonId' => $lessonId, 'completedAt' => utcStamp(nowUtc()), 'testScore' => 100];
        }

        run('DELETE FROM ' . t('learn_progress') . ' WHERE user_id = ?', [self::$studentId]);
        insert('learn_progress', [
            'user_id' => self::$studentId,
            'current_level_id' => null,
            'completed_lessons' => (string) json_encode($completed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completed_modules' => '[]',
            'total_study_seconds' => 0,
            'current_module_order' => 1,
            'current_lesson_order' => 1,
        ]);
    }

    private function xpTotal(): int
    {
        return (int) value(
            'SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );
    }

    private function attemptCount(string $exerciseId, bool $correct): int
    {
        return (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_attempt') . ' WHERE user_id = ? AND exercise_id = ? AND is_correct = ?',
            [self::$studentId, $exerciseId, $correct ? 1 : 0]
        );
    }

    private function completedLessonCount(): int
    {
        $raw = value(
            'SELECT completed_lessons FROM ' . t('learn_progress') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );
        $completed = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($completed) ? count($completed) : 0;
    }

    /** @return list<string> */
    private function questionIds(string $lessonId): array
    {
        $rows = all(
            'SELECT question.id FROM ' . t('learn_test_question') . ' AS question'
                . ' JOIN ' . t('learn_test') . ' AS test ON test.id = question.test_id'
                . ' WHERE test.lesson_id = ? ORDER BY question.sort, question.id LIMIT 100',
            [$lessonId]
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }

    private function addAttempt(string $exerciseId, bool $correct): void
    {
        insert('learn_attempt', [
            'id' => newId(),
            'user_id' => self::$studentId,
            'exercise_id' => $exerciseId,
            'is_correct' => $correct ? 1 : 0,
            'answer' => '0',
            'xp_awarded' => $correct ? 7 : 0,
            'created_at' => utcStamp(nowUtc()),
        ]);
    }

    private static function addModule(string $title, string $description, int $sort): string
    {
        $id = newId();
        insert('learn_module', [
            'id' => $id,
            'course_id' => self::$courseId,
            'title' => $title,
            'description' => $description,
            'status' => 'PUBLISHED',
            'sort' => $sort,
        ]);

        return $id;
    }

    private static function addLesson(string $moduleId, string $slug, string $title, string $description, int $sort): string
    {
        $id = newId();
        insert('learn_lesson', [
            'id' => $id,
            'module_id' => $moduleId,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'status' => 'PUBLISHED',
            'sort' => $sort,
        ]);

        return $id;
    }

    private static function addLessonContent(string $lessonId): void
    {
        foreach ([
            ['Поздороваться по-английски', 1],
            ['Представиться по имени', 2],
            ['Спросить, как дела', 3],
        ] as $objective) {
            insert('learn_objective', [
                'id' => newId(),
                'lesson_id' => $lessonId,
                'text' => $objective[0],
                'sort' => $objective[1],
            ]);
        }

        foreach ([
            ['hello', 'привет', '/həˈləʊ/', 'Hello, Anna!', 'Привет, Анна!', 1],
            ['name', 'имя', '/neɪm/', 'My name is Tom.', 'Меня зовут Том.', 2],
        ] as $word) {
            insert('learn_vocab', [
                'id' => newId(),
                'lesson_id' => $lessonId,
                'word' => $word[0],
                'translation' => $word[1],
                'ipa' => $word[2],
                'example_en' => $word[3],
                'example_ru' => $word[4],
                'sort' => $word[5],
            ]);
        }

        insert('learn_grammar', [
            'id' => newId(),
            'lesson_id' => $lessonId,
            'title' => 'Глагол to be',
            'summary' => 'I am, you are, he is',
            'details' => 'В утверждении глагол стоит после подлежащего.',
            'examples' => (string) json_encode([
                ['en' => 'I am Anna.', 'ru' => 'Я Анна.'],
                ['en' => 'You are my friend.'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort' => 1,
        ]);

        self::$exerciseChoiceId = self::addExercise($lessonId, 'MULTIPLE_CHOICE', 'Как поздороваться?', [
            'Hello',
            'Goodbye',
            'Thanks',
        ], '0', 7, 1);
        self::$exerciseFillId = self::addExercise($lessonId, 'FILL_IN_THE_BLANK', 'Вставьте: My ___ is Tom.', null, '["name"]', 5, 2);
        self::$exerciseMatchingId = self::addExercise($lessonId, 'MATCHING', 'Соедините слова и переводы.', [
            'pairs' => [
                ['left' => 'hello', 'right' => 'привет'],
                ['left' => 'name', 'right' => 'имя'],
            ],
        ], '{"hello":"привет","name":"имя"}', 6, 3);
        self::$exerciseBrainstormId = self::addExercise($lessonId, 'BRAINSTORM', 'Назовите три приветствия.', [
            'minCount' => 2,
        ], '["hello","hi","hey"]', 5, 4);
    }

    /** @param mixed $options */
    private static function addExercise(
        string $lessonId,
        string $type,
        string $question,
        mixed $options,
        string $correct,
        int $xp,
        int $sort
    ): string {
        $id = newId();
        insert('learn_exercise', [
            'id' => $id,
            'lesson_id' => $lessonId,
            'type' => $type,
            'question' => $question,
            'options' => $options === null
                ? null
                : (string) json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'correct_answer' => $correct,
            'explanation' => 'Разбор упражнения.',
            'difficulty' => 1,
            'skill' => 'VOCABULARY',
            'xp_reward' => $xp,
            'audio_url' => null,
            'sort' => $sort,
        ]);

        return $id;
    }

    private static function addMiniTest(string $lessonId): string
    {
        $id = newId();
        insert('learn_test', [
            'id' => $id,
            'kind' => 'LESSON',
            'lesson_id' => $lessonId,
            'module_id' => null,
            'title' => 'Мини-тест урока',
            'pass_score' => 70,
            'status' => 'PUBLISHED',
        ]);

        foreach ([
            ['VOCABULARY', 'Выберите приветствие', ['Hello', 'Bye'], 0, 'Hello — приветствие.'],
            ['GRAMMAR', 'Вставьте: I ___ Anna.', ['am', 'is'], 0, 'С I глагол to be стоит в форме am.'],
        ] as $question) {
            insert('learn_test_question', [
                'id' => newId(),
                'test_id' => $id,
                'skill' => $question[0],
                'text' => $question[1],
                'options' => (string) json_encode($question[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'correct_option_index' => $question[3],
                'explanation' => $question[4],
                'audio_url' => null,
                'passage_ref' => null,
                'sort' => 1,
            ]);
        }

        return $id;
    }

    private static function dropLearnTables(): void
    {
        run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'learn_attempt',
            'learn_course',
            'learn_exercise',
            'learn_grammar',
            'learn_level',
            'learn_lesson',
            'learn_module',
            'learn_objective',
            'learn_profile',
            'learn_progress',
            'learn_registration_attempt',
            'learn_statistics',
            'learn_streak',
            'learn_test',
            'learn_test_attempt',
            'learn_test_question',
            'learn_vocab',
            'learn_xp',
        ] as $table) {
            run('DROP TABLE IF EXISTS ' . t($table));
        }
        run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
