<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Учебный контур на живой базе: релиз приложения, закладка уровней и теста на уровень.
 * Операции прогресса (попытки, серия, закрытие урока) добавляются следующими задачами слоя.
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
