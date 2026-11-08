<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Ученический контур через настоящий HTTP: вход в раздел, дерево курса, модуль с замками,
 * урок шагами и его POST-жизнь (попытки упражнений, мини-тест и пересдача), регистрация,
 * онбординг, тест на уровень, кабинет и прогресс.
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

        // Закладка теперь приносит полную программу. Ученические тесты проверяют свою
        // фикстуру, поэтому временно убираем установленные курсы, но оставляем уровни и
        // placement-тест: иначе текущим курсом станет «English Basics» из закладки.
        run(
            'DELETE FROM ' . t('learn_test_question')
                . ' WHERE test_id IN (SELECT id FROM ' . t('learn_test') . " WHERE kind <> 'PLACEMENT')"
        );
        run('DELETE FROM ' . t('learn_test') . " WHERE kind <> 'PLACEMENT'");
        foreach ([
            'learn_exercise',
            'learn_vocab',
            'learn_grammar',
            'learn_objective',
            'learn_lesson',
            'learn_module',
            'learn_course',
        ] as $table) {
            run('DELETE FROM ' . t($table));
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

        foreach ([
            'learn_attempt',
            'learn_test_attempt',
            'learn_xp',
            'learn_streak',
            'learn_statistics',
            'learn_profile',
            'learn_registration_attempt',
        ] as $table) {
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

    public function testСтраницыОбученияПечатаютВыровненныеКрошки(): void
    {
        $this->login();

        $course = Kernel::get('/learn');

        self::assertSame(200, $course['status']);
        self::assertMatchesRegularExpression(
            '/<nav aria-label="Хлебные крошки" class="[^"]*flex flex-wrap items-center gap-2[^"]*">/',
            $course['body']
        );
        self::assertMatchesRegularExpression('/aria-current="page">Курсы<\/span>/', $course['body']);

        $module = Kernel::get('/learn/module/' . self::$moduleAId);

        self::assertSame(200, $module['status']);
        self::assertMatchesRegularExpression(
            '/aria-current="page">Модуль 1: Знакомство<\/span>/',
            $module['body'],
            'название модуля закрывает путь'
        );
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

    public function testРегистрацияСоздаётУченикаИВедётНаОнбординг(): void
    {
        $page = Kernel::get('/learn/register');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Регистрация ученика', $page['body']);
        $token = Kernel::tokenFrom($page['body']);
        self::assertNotSame('', $token, 'Форма регистрации не напечатала CSRF-токен');

        $post = Kernel::post('/learn/register', [
            '_token' => $token,
            'email' => 'Novyy@Example.ru',
            'name' => 'Новый ученик',
            'password' => 'parol-novogo-1',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/onboarding', $location);
        self::assertStringContainsString('notice=learn_registered', $location);

        $user = one(
            'SELECT id, email, name, role, password_hash FROM ' . t('user') . ' WHERE email = ? LIMIT 1',
            ['novyy@example.ru']
        );
        self::assertIsArray($user, 'Регистрация не создала строку ученика с нормализованной почтой');
        self::assertSame('student', (string) $user['role']);
        self::assertSame('Новый ученик', (string) $user['name']);
        self::assertTrue(verifyPassword('parol-novogo-1', (string) $user['password_hash']));

        // Регистрация сразу входит: следующий GET уже личный экран, а не редирект на вход.
        $onboarding = Kernel::get($location);
        self::assertSame(200, $onboarding['status']);
        self::assertStringContainsString('Аккаунт создан', $onboarding['body']);
        self::assertStringContainsString('Как вы учили английский?', $onboarding['body']);
    }

    public function testРегистрацияОтклоняетКривыеПоляИЗанятуюПочту(): void
    {
        $page = Kernel::get('/learn/register');
        $token = Kernel::tokenFrom($page['body']);

        $bad = Kernel::post('/learn/register', [
            '_token' => $token,
            'email' => '',
            'name' => '',
            'password' => '123',
        ]);

        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('Почта пустая', $bad['body']);
        self::assertStringContainsString('Напишите имя', $bad['body']);
        self::assertStringContainsString('Пароль короче 8', $bad['body']);

        $taken = Kernel::post('/learn/register', [
            '_token' => $token,
            'email' => self::STUDENT_EMAIL,
            'name' => 'Дубль',
            'password' => 'parol-dublya-1',
        ]);

        self::assertSame(200, $taken['status']);
        self::assertStringContainsString('уже зарегистрирована', $taken['body']);
    }

    public function testРегистрацияБлокируетсяПослеДесятиПопытокСАдреса(): void
    {
        for ($i = 0; $i < 10; $i++) {
            insert('learn_registration_attempt', [
                'id' => newId(),
                'ip' => '127.0.0.1',
                'email' => 'spam' . (string) $i . '@example.ru',
                'attempted_at' => utcStamp(nowUtc()),
            ]);
        }

        $page = Kernel::get('/learn/register');
        $token = Kernel::tokenFrom($page['body']);

        $post = Kernel::post('/learn/register', [
            '_token' => $token,
            'email' => 'blok@example.ru',
            'name' => 'Заблокированный',
            'password' => 'parol-blokirovki-1',
        ]);

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('Слишком много попыток регистрации', $post['body']);
        self::assertNull(
            one('SELECT id FROM ' . t('user') . ' WHERE email = ? LIMIT 1', ['blok@example.ru']),
            'Гард попыток обязан отказать до вставки ученика'
        );
    }

    public function testОнбордингПишетПрофильИВедётКТестуНаУровень(): void
    {
        $this->login();

        $page = Kernel::get('/onboarding');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Как вы учили английский?', $page['body']);
        self::assertStringContainsString('Никогда не изучал', $page['body']);
        self::assertStringContainsString('Путешествия', $page['body']);
        self::assertStringContainsString('20 минут', $page['body']);

        $post = Kernel::post('/onboarding', [
            '_token' => Kernel::tokenFrom($page['body']),
            'self_assessment' => 'SCHOOL',
            'goal' => 'TRAVEL',
            'daily_minutes' => '20',
        ]);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/placement-test', $location);
        self::assertStringContainsString('notice=learn_onboarded', $location);

        $profile = one(
            'SELECT onboarding_done, placement_done, self_assessment, goal, daily_minutes FROM ' . t('learn_profile')
                . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );

        self::assertIsArray($profile);
        self::assertSame(1, (int) $profile['onboarding_done']);
        self::assertSame(0, (int) $profile['placement_done']);
        self::assertSame('SCHOOL', (string) $profile['self_assessment']);
        self::assertSame('TRAVEL', (string) $profile['goal']);
        self::assertSame(20, (int) $profile['daily_minutes']);

        $follow = Kernel::get($location);

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Ответы сохранены', $follow['body']);
        self::assertStringContainsString('Тест на уровень', $follow['body']);
        self::assertStringContainsString('Что означает слово «apple»?', $follow['body']);
    }

    public function testОнбордингОтклоняетНеизвестныеОтветы(): void
    {
        $this->login();

        $page = Kernel::get('/onboarding');
        $post = Kernel::post('/onboarding', [
            '_token' => Kernel::tokenFrom($page['body']),
            'self_assessment' => 'НЕТ-ТАКОГО',
            'goal' => 'НЕТ-ТАКОГО',
            'daily_minutes' => '7',
        ]);

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('Выберите вариант из списка', $post['body']);
        self::assertStringContainsString('Выберите нагрузку из списка', $post['body']);
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_profile') . ' WHERE user_id = ?',
            [self::$studentId]
        ), 'Отказ онбординга не должен создавать профиль');
    }

    public function testТестНаУровеньПишетПопыткуИНазначаетКурс(): void
    {
        $this->login();
        $this->saveProfile(true, false);

        $page = Kernel::get('/placement-test');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Тест на уровень', $page['body']);
        self::assertStringContainsString('Что означает слово «apple»?', $page['body']);
        self::assertStringContainsString('type="radio"', $page['body']);

        $test = one(
            'SELECT id, pass_score FROM ' . t('learn_test') . ' WHERE kind = ? AND status = ? LIMIT 1',
            ['PLACEMENT', 'PUBLISHED']
        );
        self::assertIsArray($test, 'Фикстура: закладка школы не положила опубликованный тест на уровень');

        $questions = all(
            'SELECT id, correct_option_index FROM ' . t('learn_test_question')
                . ' WHERE test_id = ? ORDER BY sort, id LIMIT 100',
            [(string) $test['id']]
        );
        self::assertCount(30, $questions, 'Тест на уровень обязан состоять из тридцати вопросов закладки');

        $fields = ['_token' => Kernel::tokenFrom($page['body'])];
        foreach ($questions as $index => $question) {
            $correct = (int) $question['correct_option_index'];
            // Первые двенадцать ответов верны — 40%, ровно порог уровня A1.
            $selected = $index < 12 ? $correct : ($correct + 1) % 4;
            $fields['answer[' . (string) $question['id'] . ']'] = (string) $selected;
        }

        $post = Kernel::post('/placement-test', $fields);

        self::assertSame(302, $post['status']);
        $location = (string) ($post['headers']['location'] ?? '');
        self::assertStringContainsString('/placement-test', $location);
        self::assertStringContainsString('notice=learn_placed', $location);

        $follow = Kernel::get($location);

        self::assertSame(200, $follow['status']);
        self::assertStringContainsString('Тест пройден', $follow['body']);
        self::assertStringContainsString('Балл: 40%', $follow['body']);
        self::assertStringContainsString('A1', $follow['body']);
        self::assertStringContainsString('Начальный', $follow['body']);
        self::assertStringContainsString('Английский шаг за шагом', $follow['body']);
        self::assertStringContainsString('Лексика — 100%', $follow['body']);
        self::assertStringContainsString('Грамматика — 20%', $follow['body']);
        self::assertStringContainsString('apple переводится как яблоко', $follow['body']);
        self::assertStringContainsString('В кабинет', $follow['body']);

        $attempt = one(
            'SELECT score, passed, vocabulary_score, grammar_score, recommended_level_code,'
                . ' recommended_start_module_id FROM ' . t('learn_test_attempt')
                . ' WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [self::$studentId]
        );

        self::assertIsArray($attempt);
        self::assertSame(40, (int) $attempt['score']);
        self::assertSame(1, (int) $attempt['passed']);
        self::assertSame(100, (int) $attempt['vocabulary_score']);
        self::assertSame(20, (int) $attempt['grammar_score']);
        self::assertSame('A1', (string) $attempt['recommended_level_code']);
        self::assertSame(self::$moduleAId, (string) $attempt['recommended_start_module_id']);

        $profile = one(
            'SELECT placement_done, start_level_id FROM ' . t('learn_profile') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );
        $level = one('SELECT id FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1', ['A1']);

        self::assertIsArray($profile);
        self::assertIsArray($level);
        self::assertSame(1, (int) $profile['placement_done']);
        self::assertSame((string) $level['id'], (string) $profile['start_level_id']);

        $progress = one(
            'SELECT current_level_id FROM ' . t('learn_progress') . ' WHERE user_id = ? LIMIT 1',
            [self::$studentId]
        );

        self::assertIsArray($progress);
        self::assertSame((string) $level['id'], (string) $progress['current_level_id']);

        $dashboard = Kernel::get('/dashboard');

        self::assertSame(200, $dashboard['status']);
        self::assertStringContainsString('Английский шаг за шагом', $dashboard['body']);
    }

    public function testКабинетВедётНаОнбордингИТестПоГотовности(): void
    {
        $this->login();

        $dashboard = Kernel::get('/dashboard');
        self::assertSame(302, $dashboard['status']);
        self::assertSame('/onboarding', (string) ($dashboard['headers']['location'] ?? ''));

        $this->saveProfile(true, false);
        $dashboard = Kernel::get('/dashboard');
        self::assertSame(302, $dashboard['status']);
        self::assertSame('/placement-test', (string) ($dashboard['headers']['location'] ?? ''));

        $this->saveProfile(true, true);
        $dashboard = Kernel::get('/dashboard');

        self::assertSame(200, $dashboard['status']);
        self::assertMatchesRegularExpression('/Добр(ое утро|ый день|ый вечер|ая ночь)/u', $dashboard['body']);
        self::assertStringContainsString('Серии пока нет', $dashboard['body']);
        self::assertStringContainsString('Слабого места не видно', $dashboard['body']);
        self::assertStringContainsString('Модуль 1: Знакомство', $dashboard['body']);
        self::assertStringContainsString('/lesson/hello', $dashboard['body']);
        self::assertStringContainsString('Продолжить урок', $dashboard['body']);
        self::assertStringContainsString('0 XP', $dashboard['body']);
    }

    public function testПрогрессПоказываетНавыкиИИсториюТестов(): void
    {
        $this->login();

        insert('learn_xp', ['user_id' => self::$studentId, 'total' => 420]);
        insert('learn_streak', [
            'user_id' => self::$studentId,
            'current_days' => 3,
            'longest_days' => 5,
            'last_study_at' => utcDayKey(nowUtc()),
        ]);
        insert('learn_statistics', [
            'user_id' => self::$studentId,
            'lessons_completed' => 4,
            'tests_taken' => 12,
            'exercises_done' => 16,
            'correct_rate' => 0.875,
        ]);

        // Лексика: восемь попыток, шесть верных — 75%.
        for ($i = 0; $i < 8; $i++) {
            $this->addAttempt(self::$exerciseChoiceId, $i < 6);
        }

        // Грамматика: отдельное упражнение с восемью верными попытками — 100%.
        $grammarExerciseId = newId();
        insert('learn_exercise', [
            'id' => $grammarExerciseId,
            'lesson_id' => self::$lessonOneId,
            'type' => 'FILL_IN_THE_BLANK',
            'question' => 'Грамматическая проба',
            'options' => null,
            'correct_answer' => '["x"]',
            'explanation' => '',
            'difficulty' => 1,
            'skill' => 'GRAMMAR',
            'xp_reward' => 5,
            'audio_url' => null,
            'sort' => 10,
        ]);

        try {
            for ($i = 0; $i < 8; $i++) {
                $this->addAttempt($grammarExerciseId, true);
            }

            $test = one('SELECT id FROM ' . t('learn_test') . ' WHERE lesson_id = ? LIMIT 1', [self::$lessonOneId]);
            self::assertIsArray($test);

            // Двенадцать попыток истории: на экран обязаны попасть последние десять.
            for ($i = 0; $i < 12; $i++) {
                insert('learn_test_attempt', [
                    'id' => newId(),
                    'user_id' => self::$studentId,
                    'test_id' => (string) $test['id'],
                    'score' => 61 + $i,
                    'vocabulary_score' => 61 + $i,
                    'grammar_score' => null,
                    'reading_score' => null,
                    'listening_score' => null,
                    'passed' => 1,
                    'recommended_level_code' => null,
                    'recommended_start_module_id' => null,
                    'answers' => '[]',
                    'created_at' => utcStamp(shiftSeconds(nowUtc(), -60 * (12 - $i))),
                ]);
            }

            $page = Kernel::get('/progress');

            self::assertSame(200, $page['status']);
            self::assertStringContainsString('420 XP', $page['body']);
            self::assertStringContainsString('3 дня', $page['body']);
            self::assertStringContainsString('data-stat="lessons">4<', $page['body']);
            self::assertStringContainsString('data-stat="tests">12<', $page['body']);
            self::assertStringContainsString('data-stat="exercises">16<', $page['body']);
            self::assertStringContainsString('6 из 8 · 75%', $page['body']);
            self::assertStringContainsString('8 из 8 · 100%', $page['body']);
            self::assertStringContainsString('Чтение', $page['body']);
            self::assertStringContainsString('нет данных', $page['body']);
            self::assertStringContainsString('72%', $page['body']);
            self::assertStringContainsString('63%', $page['body']);
            self::assertStringNotContainsString('62%', $page['body']);
            self::assertStringNotContainsString('61%', $page['body']);
            self::assertSame(10, substr_count($page['body'], 'data-attempt='), 'История тестов обязана резаться на десяти строках');
        } finally {
            run('DELETE FROM ' . t('learn_exercise') . ' WHERE id = ?', [$grammarExerciseId]);
        }
    }

    public function testНовыеЭкраныЗапрещаютИндексациюИХранение(): void
    {
        $register = Kernel::get('/learn/register');

        self::assertSame(200, $register['status']);
        self::assertStringContainsString('name="robots" content="noindex', $register['body']);
        self::assertSame('no-store', $register['headers']['cache-control'] ?? '');

        $this->login();
        $this->saveProfile(true, true);

        foreach (['/onboarding', '/placement-test', '/dashboard', '/progress'] as $path) {
            $page = Kernel::get($path);

            self::assertSame(200, $page['status'], $path);
            self::assertStringContainsString('name="robots" content="noindex', $page['body'], $path);
            self::assertSame('no-store', $page['headers']['cache-control'] ?? '', $path);
        }
    }

    public function testВыключенноеПриложениеСнимаетАдресаИСсылкуШапки(): void
    {
        $this->login();
        self::assertTrue(setAppEnabled('learn', false)['ok']);

        try {
            self::assertSame(404, Kernel::get('/dashboard')['status']);
            self::assertSame(404, Kernel::get('/learn/register')['status']);
            self::assertStringNotContainsString('>Курсы</a>', Kernel::get('/')['body']);
        } finally {
            self::assertTrue(setAppEnabled('learn', true)['ok']);
        }
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

    private function saveProfile(bool $onboarding, bool $placement): void
    {
        run('DELETE FROM ' . t('learn_profile') . ' WHERE user_id = ?', [self::$studentId]);

        insert('learn_profile', [
            'user_id' => self::$studentId,
            'onboarding_done' => $onboarding ? 1 : 0,
            'placement_done' => $placement ? 1 : 0,
            'self_assessment' => 'SCHOOL',
            'goal' => 'TRAVEL',
            'daily_minutes' => 20,
            'start_level_id' => null,
            'created_at' => utcStamp(nowUtc()),
        ]);
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
