<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Учебный контур на живой базе: релиз приложения, закладка уровней и теста на уровень,
 * операции прогресса (попытки, XP, серия, закрытие урока, профиль и прогресс).
 */
final class LearnTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        self::dropLearnTables();

        $result = installApp('learn');

        if (($result['ok'] ?? false) !== true) {
            self::fail('Раздел «Курсы» не установился: ' . (string) ($result['error'] ?? ''));
        }
    }

    protected function setUp(): void
    {
        foreach ([
            'learn_attempt',
            'learn_profile',
            'learn_progress',
            'learn_statistics',
            'learn_streak',
            'learn_xp',
        ] as $table) {
            run('DELETE FROM ' . t($table));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropLearnTables();
        run('DELETE FROM ' . t('app_installation') . " WHERE id = 'learn'");
        run('DELETE FROM ' . t('app_migration') . " WHERE app = 'learn'");
    }

    public function testРелизСоздаётВсеТаблицыШколы(): void
    {
        $rows = all("SHOW TABLES LIKE 'tbl_learn_%'");
        $names = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $rows
        );
        sort($names);

        self::assertSame([
            'tbl_learn_attempt',
            'tbl_learn_course',
            'tbl_learn_exercise',
            'tbl_learn_grammar',
            'tbl_learn_lesson',
            'tbl_learn_level',
            'tbl_learn_module',
            'tbl_learn_objective',
            'tbl_learn_profile',
            'tbl_learn_progress',
            'tbl_learn_registration_attempt',
            'tbl_learn_statistics',
            'tbl_learn_streak',
            'tbl_learn_test',
            'tbl_learn_test_attempt',
            'tbl_learn_test_question',
            'tbl_learn_vocab',
            'tbl_learn_xp',
        ], $names);
    }

    public function testПланПослеУстановкиПуст(): void
    {
        // Релиз применён целиком: кнопка «Обновить» не должна находить работу.
        self::assertTrue(planApp('learn')->isEmpty());
    }

    public function testСидКладётУровниПоПорядку(): void
    {
        $rows = all('SELECT code, title, sort FROM ' . t('learn_level') . ' ORDER BY sort, id');

        self::assertSame(['Pre-A1', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'], array_column($rows, 'code'));
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_map(
            static fn (array $row): int => (int) $row['sort'],
            $rows
        ));

        foreach ($rows as $row) {
            self::assertNotSame('', (string) $row['title'], 'Уровень без названия: ' . (string) $row['code']);
        }
    }

    public function testСидКладётТестНаУровеньИзТридцатиВопросов(): void
    {
        $test = one(
            'SELECT id, kind, title, pass_score, status FROM ' . t('learn_test') . " WHERE kind = 'PLACEMENT'"
        );

        self::assertNotNull($test);
        self::assertSame('PUBLISHED', (string) $test['status']);
        self::assertSame(40, (int) $test['pass_score']);

        $questions = all(
            'SELECT skill, options, correct_option_index, sort FROM ' . t('learn_test_question')
                . ' WHERE test_id = ? ORDER BY sort',
            [(string) $test['id']]
        );

        self::assertCount(30, $questions);

        $counts = [];
        foreach ($questions as $question) {
            $skill = (string) $question['skill'];
            $counts[$skill] = ($counts[$skill] ?? 0) + 1;

            $options = json_decode((string) $question['options'], true);
            self::assertIsArray($options);

            $choices = array_key_exists('choices', $options) ? $options['choices'] : $options;
            self::assertIsArray($choices);
            self::assertGreaterThanOrEqual(2, count($choices));

            $index = (int) $question['correct_option_index'];
            self::assertGreaterThanOrEqual(0, $index);
            self::assertLessThan(count($choices), $index);
        }

        self::assertSame([
            'VOCABULARY' => 10,
            'GRAMMAR' => 10,
            'READING' => 5,
            'LISTENING' => 5,
        ], $counts);
    }

    public function testПовторнаяЗакладкаНеДублируетШколу(): void
    {
        $manifest = (array) require dirname(__DIR__, 2) . '/apps/learn/manifest.php';
        $levels = (int) value('SELECT COUNT(*) FROM ' . t('learn_level'));
        $tests = (int) value('SELECT COUNT(*) FROM ' . t('learn_test'));
        $questions = (int) value('SELECT COUNT(*) FROM ' . t('learn_test_question'));

        seedApp($manifest);
        seedApp($manifest);

        self::assertSame($levels, (int) value('SELECT COUNT(*) FROM ' . t('learn_level')));
        self::assertSame($tests, (int) value('SELECT COUNT(*) FROM ' . t('learn_test')));
        self::assertSame($questions, (int) value('SELECT COUNT(*) FROM ' . t('learn_test_question')));
    }

    public function testПерваяВернаяПопыткаНачисляетОпытАТожеВернаяИНевернаяНет(): void
    {
        $userId = self::seedStudent();
        $exerciseId = newId();

        $first = learnRecordExerciseAttempt([
            'user_id' => $userId,
            'exercise_id' => $exerciseId,
            'correct' => true,
            'answer' => ['choice' => 2],
            'xp_reward' => 7,
        ]);

        self::assertSame(7, $first['xp']);
        self::assertFalse($first['already_correct']);
        self::assertSame(7, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));

        $attempt = one(
            'SELECT is_correct, answer, xp_awarded FROM ' . t('learn_attempt')
                . ' WHERE user_id = ? AND exercise_id = ? ORDER BY created_at DESC LIMIT 1',
            [$userId, $exerciseId]
        );

        self::assertNotNull($attempt);
        self::assertSame(1, (int) $attempt['is_correct']);
        self::assertSame(7, (int) $attempt['xp_awarded']);
        self::assertSame(['choice' => 2], json_decode((string) $attempt['answer'], true));

        $stats = one(
            'SELECT exercises_done, correct_rate FROM ' . t('learn_statistics') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($stats);
        self::assertSame(1, (int) $stats['exercises_done']);
        self::assertEqualsWithDelta(1.0, (float) $stats['correct_rate'], 0.001);

        // Повторная верная попытка записывается, но опыт уже не начисляет.
        $repeat = learnRecordExerciseAttempt([
            'user_id' => $userId,
            'exercise_id' => $exerciseId,
            'correct' => true,
            'answer' => ['choice' => 2],
            'xp_reward' => 7,
        ]);

        self::assertSame(0, $repeat['xp']);
        self::assertTrue($repeat['already_correct']);
        self::assertSame(7, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));
        self::assertSame(2, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_attempt') . ' WHERE user_id = ? AND exercise_id = ?',
            [$userId, $exerciseId]
        ));
        self::assertSame(2, (int) value(
            'SELECT exercises_done FROM ' . t('learn_statistics') . ' WHERE user_id = ?',
            [$userId]
        ));

        // Неверная попытка не платит и снижает долю верных ответов.
        $wrong = learnRecordExerciseAttempt([
            'user_id' => $userId,
            'exercise_id' => newId(),
            'correct' => false,
            'answer' => 'неверно',
            'xp_reward' => 9,
        ]);

        self::assertSame(0, $wrong['xp']);
        self::assertFalse($wrong['already_correct']);
        self::assertSame(7, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));

        $stats = one(
            'SELECT exercises_done, correct_rate FROM ' . t('learn_statistics') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($stats);
        self::assertSame(3, (int) $stats['exercises_done']);
        self::assertEqualsWithDelta(2 / 3, (float) $stats['correct_rate'], 0.001);

        // Ошибка до верного ответа награду не отнимает: платит первая верная.
        $thirdExercise = newId();
        learnRecordExerciseAttempt([
            'user_id' => $userId,
            'exercise_id' => $thirdExercise,
            'correct' => false,
            'answer' => 0,
            'xp_reward' => 5,
        ]);
        $rightAfter = learnRecordExerciseAttempt([
            'user_id' => $userId,
            'exercise_id' => $thirdExercise,
            'correct' => true,
            'answer' => 1,
            'xp_reward' => 5,
        ]);

        self::assertSame(5, $rightAfter['xp']);
        self::assertFalse($rightAfter['already_correct']);
        self::assertSame(12, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));
    }

    public function testОпытКопитсяИНулевоеНачислениеНеСоздаётСтроку(): void
    {
        $userId = self::seedStudent();

        learnAddXp($userId, 0);
        self::assertSame(0, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_xp') . ' WHERE user_id = ?',
            [$userId]
        ));

        learnAddXp($userId, 25);
        learnAddXp($userId, 25);
        $total = (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]);
        self::assertSame(50, $total);

        learnAddXp($userId, -10);
        $total = (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]);
        self::assertSame(50, $total);
    }

    public function testСерияРастётПоВчерашнемуДнюИСбрасываетсяПослеПропуска(): void
    {
        $userId = self::seedStudent();
        $today = utcDayKey(nowUtc());
        $yesterday = utcDayKey(nowUtc()->modify('-1 day'));

        self::assertSame(1, learnTouchStreak($userId));

        $streak = one(
            'SELECT current_days, longest_days, last_study_at FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($streak);
        self::assertSame(1, (int) $streak['current_days']);
        self::assertSame(1, (int) $streak['longest_days']);
        self::assertSame($today, (string) $streak['last_study_at']);

        // Повторное занятие в тот же UTC-день серию не двигает.
        self::assertSame(1, learnTouchStreak($userId));
        self::assertSame(1, (int) value(
            'SELECT current_days FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        ));

        // Вчерашнее занятие продолжает серию, и longest догоняет её.
        run(
            'UPDATE ' . t('learn_streak')
                . ' SET current_days = 3, longest_days = 3, last_study_at = ? WHERE user_id = ?',
            [$yesterday, $userId]
        );

        self::assertSame(4, learnTouchStreak($userId));

        $streak = one(
            'SELECT current_days, longest_days, last_study_at FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($streak);
        self::assertSame(4, (int) $streak['current_days']);
        self::assertSame(4, (int) $streak['longest_days']);
        self::assertSame($today, (string) $streak['last_study_at']);

        // Рекорд не сбрасывается, когда текущая серия его не догнала.
        run(
            'UPDATE ' . t('learn_streak')
                . ' SET current_days = 4, longest_days = 9, last_study_at = ? WHERE user_id = ?',
            [$yesterday, $userId]
        );

        self::assertSame(5, learnTouchStreak($userId));
        self::assertSame(5, (int) value(
            'SELECT current_days FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        ));
        self::assertSame(9, (int) value(
            'SELECT longest_days FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        ));

        // Пропуск дня обнуляет текущую серию до одного занятия, рекорд остаётся.
        run(
            'UPDATE ' . t('learn_streak')
                . ' SET current_days = 5, longest_days = 9, last_study_at = ? WHERE user_id = ?',
            [utcDayKey(nowUtc()->modify('-3 days')), $userId]
        );

        self::assertSame(1, learnTouchStreak($userId));

        $streak = one(
            'SELECT current_days, longest_days, last_study_at FROM ' . t('learn_streak') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($streak);
        self::assertSame(1, (int) $streak['current_days']);
        self::assertSame(9, (int) $streak['longest_days']);
        self::assertSame($today, (string) $streak['last_study_at']);
    }

    public function testЗакрытиеУрокаНачисляетОпытИНеПовторяется(): void
    {
        $userId = self::seedStudent();
        $lessonId = newId();
        $moduleId = newId();

        $params = [
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'module_id' => $moduleId,
            'module_order' => 1,
            'lesson_order' => 2,
            'test_score' => 100,
            'next_lesson' => null,
            'module_completed' => false,
        ];

        $first = learnCompleteLesson($params);

        self::assertFalse($first['already_completed']);
        self::assertSame(60, $first['xp']);
        self::assertSame(0, $first['module_xp']);
        self::assertFalse($first['module_completed']);

        $progress = learnProgressRow($userId);
        $completed = json_decode((string) $progress['completed_lessons'], true);

        self::assertIsArray($completed);
        self::assertCount(1, $completed);
        self::assertSame($lessonId, (string) $completed[0]['lessonId']);
        self::assertSame(100, (int) $completed[0]['testScore']);
        self::assertNotSame('', (string) $completed[0]['completedAt']);
        self::assertSame(1, (int) $progress['current_module_order']);
        self::assertSame(3, (int) $progress['current_lesson_order']);

        self::assertSame(60, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));

        $stats = one(
            'SELECT lessons_completed, tests_taken FROM ' . t('learn_statistics') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($stats);
        self::assertSame(1, (int) $stats['lessons_completed']);
        self::assertSame(1, (int) $stats['tests_taken']);

        // Повторное закрытие того же урока ничего не начисляет и не плодит записи.
        $again = learnCompleteLesson($params);

        self::assertTrue($again['already_completed']);
        self::assertSame(0, $again['xp']);
        self::assertSame(0, $again['module_xp']);
        self::assertFalse($again['module_completed']);
        self::assertSame(60, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));

        $progress = learnProgressRow($userId);
        $completed = json_decode((string) $progress['completed_lessons'], true);

        self::assertIsArray($completed);
        self::assertCount(1, $completed);

        $stats = one(
            'SELECT lessons_completed, tests_taken FROM ' . t('learn_statistics') . ' WHERE user_id = ?',
            [$userId]
        );

        self::assertNotNull($stats);
        self::assertSame(1, (int) $stats['lessons_completed']);
        self::assertSame(1, (int) $stats['tests_taken']);
    }

    public function testЗакрытиеМодуляДаётБонусОдинРазИВедётКСледующемуУроку(): void
    {
        $userId = self::seedStudent();
        $moduleId = newId();

        $last = learnCompleteLesson([
            'user_id' => $userId,
            'lesson_id' => newId(),
            'module_id' => $moduleId,
            'module_order' => 2,
            'lesson_order' => 3,
            'test_score' => 80,
            'next_lesson' => null,
            'module_completed' => true,
        ]);

        self::assertSame(150, $last['xp']);
        self::assertSame(100, $last['module_xp']);
        self::assertTrue($last['module_completed']);

        $progress = learnProgressRow($userId);
        $modules = json_decode((string) $progress['completed_modules'], true);

        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertSame($moduleId, (string) $modules[0]['moduleId']);
        self::assertNotSame('', (string) $modules[0]['completedAt']);
        self::assertSame(2, (int) $progress['current_module_order']);
        self::assertSame(4, (int) $progress['current_lesson_order']);

        // Второй закрытый урок того же модуля бонуса не повторяет, но ведёт к следующему уроку.
        $nextModuleId = newId();
        $nextLessonId = newId();
        $next = learnCompleteLesson([
            'user_id' => $userId,
            'lesson_id' => newId(),
            'module_id' => $moduleId,
            'module_order' => 2,
            'lesson_order' => 4,
            'test_score' => 90,
            'next_lesson' => [
                'module_id' => $nextModuleId,
                'module_order' => 3,
                'lesson_id' => $nextLessonId,
                'lesson_order' => 1,
            ],
            'module_completed' => true,
        ]);

        self::assertSame(50, $next['xp']);
        self::assertSame(0, $next['module_xp']);
        self::assertFalse($next['module_completed']);
        self::assertSame(200, (int) value('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$userId]));

        $progress = learnProgressRow($userId);
        $modules = json_decode((string) $progress['completed_modules'], true);

        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertSame(3, (int) $progress['current_module_order']);
        self::assertSame(1, (int) $progress['current_lesson_order']);
    }

    public function testПрофильИПрогрессСоздаютсяИОбновляютсяБезДублей(): void
    {
        $userId = self::seedStudent();

        $profile = learnProfileRow($userId);

        self::assertSame(0, (int) $profile['onboarding_done']);
        self::assertSame(0, (int) $profile['placement_done']);
        self::assertSame('', (string) $profile['self_assessment']);
        self::assertSame('', (string) $profile['goal']);
        self::assertSame(0, (int) $profile['daily_minutes']);
        self::assertNull($profile['start_level_id']);
        self::assertNotSame('', (string) $profile['created_at']);

        learnSaveProfile($userId, [
            'self_assessment' => 'SCHOOL',
            'goal' => 'TRAVEL',
            'daily_minutes' => 20,
            'onboarding_done' => true,
        ]);

        $profile = learnProfileRow($userId);

        self::assertSame(1, (int) $profile['onboarding_done']);
        self::assertSame('SCHOOL', (string) $profile['self_assessment']);
        self::assertSame('TRAVEL', (string) $profile['goal']);
        self::assertSame(20, (int) $profile['daily_minutes']);

        $levelId = newId();
        learnSaveProfile($userId, ['placement_done' => true, 'start_level_id' => $levelId]);

        $profile = learnProfileRow($userId);

        self::assertSame(1, (int) $profile['onboarding_done']);
        self::assertSame(1, (int) $profile['placement_done']);
        self::assertSame($levelId, (string) $profile['start_level_id']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_profile') . ' WHERE user_id = ?',
            [$userId]
        ));

        $progress = learnProgressRow($userId);

        self::assertSame('[]', (string) $progress['completed_lessons']);
        self::assertSame('[]', (string) $progress['completed_modules']);
        self::assertNull($progress['current_level_id']);
        self::assertSame(1, (int) $progress['current_module_order']);
        self::assertSame(1, (int) $progress['current_lesson_order']);

        run(
            'UPDATE ' . t('learn_progress')
                . ' SET current_module_order = 4, current_lesson_order = 2 WHERE user_id = ?',
            [$userId]
        );

        $progress = learnProgressRow($userId);

        self::assertSame(4, (int) $progress['current_module_order']);
        self::assertSame(2, (int) $progress['current_lesson_order']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_progress') . ' WHERE user_id = ?',
            [$userId]
        ));
    }

    private static function seedStudent(): string
    {
        return TestDb::seedUser('learn-' . newId() . '@example.test', 'secret', 'student');
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
