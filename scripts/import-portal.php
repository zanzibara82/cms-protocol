<?php

declare(strict_types=1);

// Перенос данных портала из PostgreSQL в MySQL (§17 итоговой спецификации,
// дизайн docs/superpowers/specs/2026-11-11-postgres-import-design.md).
//
// Скрипт — тонкая оболочка: читает выгрузку `dump/*.jsonl` (её делает scripts/portal-export.sh),
// применяет правила из core/import.php и печатает отчёт. Всё, что обязано быть под стражами
// ядра (роли, моменты, JSON, пути), живёт в core/import.php, потому что каталог scripts/
// стражи не обходят.
//
// Использование:
//   php scripts/import-portal.php --dump=<каталог> [--write] [--assets=<каталог портала>]
//
// Без --write скрипт ничего не пишет: перенос в живую базу по невнимательности необратим,
// поэтому проверка — режим по умолчанию.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

// Перечни школы (LEARN_EXERCISE_TYPES) живут в приложении, а не в ядре: перенос сверяет тип
// упражнения с ними, иначе в базу легла бы строка, которую движок не покажет.
$learnConstants = __DIR__ . '/../apps/learn/lib/constants.php';
if (is_file($learnConstants)) {
    require_once $learnConstants;
}

// Отказ в консоли — текстом и с кодом 1, а не фирменной HTML-страницей: обработчик исключений
// ядра печатает страницу 500 для браузера, и в терминале она читается как мусор.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Отказ: ' . $error->getMessage() . "\n");
    exit(1);
});

$options = getopt('', ['dump:', 'assets::', 'write']);

$dumpDir = is_string($options['dump'] ?? null) ? (string) $options['dump'] : '';
$assetsDir = is_string($options['assets'] ?? null) ? (string) $options['assets'] : '';
$write = array_key_exists('write', $options);

if ($dumpDir === '' || !is_dir($dumpDir)) {
    fwrite(STDERR, "Отказ: укажите каталог выгрузки: --dump=<каталог>\n");
    fwrite(STDERR, "Выгрузку делает scripts/portal-export.sh.\n");
    exit(1);
}

$mode = $write ? 'запись' : 'проверка (в базу ничего не пишется)';
echo "Перенос данных портала: режим — $mode.\n";

/**
 * Строки таблицы выгрузки: один объект JSON на строку файла.
 *
 * @return list<array<string, mixed>>
 */
function importRows(string $dir, string $table): array
{
    $file = $dir . '/' . $table . '.jsonl';

    if (!is_file($file)) {
        return [];
    }

    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

/** @return array{read: int, written: int, skipped: array<string, int>} */
function statNew(): array
{
    return ['read' => 0, 'written' => 0, 'skipped' => []];
}

/**
 * Копирование картинок портала в каталог ассетов CMS (раздел 4.3 дизайна). Отсутствующий файл
 * оставляет путь как есть и попадает в отчёт: молчаливая замена пути на пустоту стёрла бы картинку.
 *
 * @param list<string> $missing
 */
function importAssets(string $sourceDir, string $targetDir, array &$missing): int
{
    if ($sourceDir === '' || !is_dir($sourceDir)) {
        return 0;
    }

    $copied = 0;
    foreach (glob($sourceDir . '/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }

        $target = $targetDir . '/' . basename($file);
        if (is_file($target) && filemtime($target) >= filemtime($file)) {
            continue;
        }

        if (copy($file, $target)) {
            $copied++;
        } else {
            $missing[] = basename($file);
        }
    }

    return $copied;
}

$stamp = utcStamp(nowUtc());
$problemy = [];
$itogi = [];

// Порядок — родитель раньше ребёнка (раздел 4). Внешних ключей в CMS нет, но порядок нужен
// для сверки сирот и для читаемого отчёта.
$userStat = statNew();
$profileStat = statNew();

// Профили читаются заранее: имя пользователя живёт в профиле, а обязательная колонка CMS — в tbl_user.
$profiles = [];
foreach (importRows($dumpDir, 'UserProfile') as $profile) {
    $profiles[(string) ($profile['userId'] ?? '')] = $profile;
}

foreach (importRows($dumpDir, 'User') as $user) {
    $id = (string) ($user['id'] ?? '');
    $email = mb_strtolower(trim((string) ($user['email'] ?? '')));

    if ($email === '') {
        $userStat['read']++;
        $userStat['skipped']['без почты'] = ($userStat['skipped']['без почты'] ?? 0) + 1;
        $problemy[] = 'User ' . $id . ': нет адреса почты';

        continue;
    }

    $row = [
        'id' => $id,
        'email' => $email,
        'password_hash' => (string) ($user['passwordHash'] ?? ''),
        'name' => importUserName($profiles[$id]['name'] ?? '', $email),
        'role' => importRole($user['role'] ?? 'STUDENT'),
        'session_version' => (int) ($user['sessionVersion'] ?? 0),
        'blocked_at' => importStamp($user['blockedAt'] ?? null),
        'created_at' => importStamp($user['createdAt'] ?? null) ?? $stamp,
    ];

    if ($write) {
        importUpsert('user', $row, $userStat);
    } else {
        $userStat['read']++;
        $userStat['written']++;
    }
}

foreach (importRows($dumpDir, 'UserProfile') as $profile) {
    $profileStat['read']++;

    $row = [
        'user_id' => (string) ($profile['userId'] ?? ''),
        'onboarding_done' => !empty($profile['onboardingDone']) ? 1 : 0,
        'placement_done' => !empty($profile['placementDone']) ? 1 : 0,
        'self_assessment' => (string) ($profile['selfAssessment'] ?? ''),
        'goal' => (string) ($profile['goal'] ?? ''),
        'daily_minutes' => (int) ($profile['dailyMinutes'] ?? 0),
        'start_level_id' => $profile['startLevelId'] ?? null,
        'created_at' => $stamp,
    ];

    if ($write) {
        importUpsert('learn_profile', $row, $profileStat);
    } else {
        $profileStat['written']++;
    }
}

/**
 * Простой перенос «поле → колонка»: таблица источника, таблица CMS, карта колонок и значения
 * по умолчанию. Такие таблицы не нуждаются в разборе JSON — только в переименовании колонок.
 *
 * `$nullable` перечисляет колонки CMS, у которых пусто в источнике значит NULL, а не ''.
 * Это не косметика: у теста `lesson_id` и `module_id` объявлены NULL с уникальными ключами,
 * и пустая строка вместо NULL столкнула бы все тесты модулей на одном значении ''.
 *
 * @param array<string, string> $columns портал → CMS
 * @param array<string, mixed> $defaults значения колонок CMS, которых нет в источнике
 * @param array<string, array<string, true>> $parents «колонка источника → набор id родителя из источника»
 * @param list<string> $ints колонки CMS, приводимые к целому
 * @param list<string> $bools колонки CMS, приводимые к TINYINT(1)
 * @param list<string> $problemy накопитель находок «Требуют внимания человека»
 * @param list<string> $nullable колонки CMS, где пустое значение становится NULL
 * @return array{read: int, written: int, skipped: array<string, int>}
 */
function importPlain(
    bool $write,
    string $dumpDir,
    string $sourceTable,
    string $targetTable,
    array $columns,
    array $defaults,
    array $parents,
    array $ints,
    array $bools,
    array &$problemy,
    array $nullable = []
): array {
    $stat = statNew();

    foreach (importRows($dumpDir, $sourceTable) as $source) {
        $id = (string) ($source['id'] ?? '');
        $ok = true;

        foreach ($parents as $fromColumn => $knownIds) {
            $parentId = trim((string) ($source[$fromColumn] ?? ''));

            if ($parentId !== '' && importOrphans($knownIds, $parentId, $stat) === false) {
                $problemy[] = $sourceTable . ' ' . $id . ': родитель не найден (' . $fromColumn . ')';
                $ok = false;
            }
        }

        if (!$ok) {
            $stat['read']++;
            continue;
        }

        $row = ['id' => $id];

        foreach ($columns as $from => $to) {
            $value = $source[$from] ?? null;

            if (in_array($to, $nullable, true) && (is_scalar($value) === false || trim((string) $value) === '')) {
                $row[$to] = null;
            } elseif (in_array($to, $ints, true)) {
                $row[$to] = (int) (is_numeric($value) ? $value : 0);
            } elseif (in_array($to, $bools, true)) {
                $row[$to] = in_array($value, [true, 1, '1', 't', 'true'], true) ? 1 : 0;
            } else {
                $row[$to] = is_scalar($value) ? (string) $value : '';
            }
        }

        foreach ($defaults as $column => $value) {
            $row[$column] = $value;
        }

        if ($write) {
            importUpsert($targetTable, $row, $stat);
        } else {
            $stat['read']++;
            $stat['written']++;
        }
    }

    return $stat;
}

// ── Учебный контур (раздел 4.2) ────────────────────────────────────────────────
// Наборы идентификаторов источника: по ним importOrphans() судит сирот, не заглядывая в базу,
// поэтому режим проверки показывает ту же картину, что и запись.
$levelIds = importIds(importRows($dumpDir, 'Level'));
$courseIds = importIds(importRows($dumpDir, 'Course'));
$moduleIds = importIds(importRows($dumpDir, 'Module'));
$lessonIds = importIds(importRows($dumpDir, 'Lesson'));
$testIds = importIds(importRows($dumpDir, 'Test'));

$itogi['Level'] = importPlain(
    $write,
    $dumpDir,
    'Level',
    'learn_level',
    ['code' => 'code', 'title' => 'title', 'order' => 'sort'],
    [],
    [],
    ['sort'],
    [],
    $problemy
);

$itogi['Course'] = importPlain(
    $write,
    $dumpDir,
    'Course',
    'learn_course',
    ['levelId' => 'level_id', 'title' => 'title', 'description' => 'description', 'status' => 'status', 'order' => 'sort'],
    ['description' => ''],
    ['levelId' => $levelIds],
    ['sort'],
    [],
    $problemy
);

$itogi['Module'] = importPlain(
    $write,
    $dumpDir,
    'Module',
    'learn_module',
    ['courseId' => 'course_id', 'title' => 'title', 'description' => 'description', 'status' => 'status', 'order' => 'sort'],
    ['description' => ''],
    ['courseId' => $courseIds],
    ['sort'],
    [],
    $problemy
);

$itogi['Lesson'] = importPlain(
    $write,
    $dumpDir,
    'Lesson',
    'learn_lesson',
    ['moduleId' => 'module_id', 'slug' => 'slug', 'title' => 'title', 'description' => 'description', 'status' => 'status', 'order' => 'sort'],
    ['description' => ''],
    ['moduleId' => $moduleIds],
    ['sort'],
    [],
    $problemy
);

$itogi['LearningObjective'] = importPlain(
    $write,
    $dumpDir,
    'LearningObjective',
    'learn_objective',
    ['lessonId' => 'lesson_id', 'text' => 'text', 'order' => 'sort'],
    [],
    ['lessonId' => $lessonIds],
    ['sort'],
    [],
    $problemy
);

$itogi['VocabItem'] = importPlain(
    $write,
    $dumpDir,
    'VocabItem',
    'learn_vocab',
    ['lessonId' => 'lesson_id', 'word' => 'word', 'translation' => 'translation', 'ipa' => 'ipa', 'exampleEn' => 'example_en', 'exampleRu' => 'example_ru', 'order' => 'sort'],
    [],
    ['lessonId' => $lessonIds],
    ['sort'],
    [],
    $problemy
);

$grammarStat = statNew();
foreach (importRows($dumpDir, 'GrammarTopic') as $topic) {
    if (!importOrphans($lessonIds, $topic['lessonId'] ?? null, $grammarStat)) {
        $grammarStat['read']++;
        $problemy[] = 'GrammarTopic ' . (string) ($topic['id'] ?? '') . ': урок не найден';
        continue;
    }

    $row = [
        'id' => (string) ($topic['id'] ?? ''),
        'lesson_id' => (string) ($topic['lessonId'] ?? ''),
        'title' => (string) ($topic['title'] ?? ''),
        'summary' => (string) ($topic['summary'] ?? ''),
        'details' => is_scalar($topic['details'] ?? null) ? (string) $topic['details'] : null,
        'examples' => is_scalar($topic['examples'] ?? null) ? (string) $topic['examples'] : null,
        'sort' => (int) ($topic['order'] ?? 0),
    ];

    if ($write) {
        importUpsert('learn_grammar', $row, $grammarStat);
    } else {
        $grammarStat['read']++;
        $grammarStat['written']++;
    }
}
$itogi['GrammarTopic'] = $grammarStat;

// Тип упражнения обязан быть из перечня ядра: иначе движок не покажет задание, и лучше
// остановиться на отчёте, чем сложить в базу неиграбельную строку.
$exerciseStat = statNew();
foreach (importRows($dumpDir, 'Exercise') as $exercise) {
    $type = (string) ($exercise['type'] ?? '');

    if (!in_array($type, LEARN_EXERCISE_TYPES, true)) {
        $exerciseStat['read']++;
        $exerciseStat['skipped']['неизвестный тип'] = ($exerciseStat['skipped']['неизвестный тип'] ?? 0) + 1;
        $problemy[] = 'Exercise ' . (string) ($exercise['id'] ?? '') . ': неизвестный тип ' . $type;
        continue;
    }

    if (!importOrphans($lessonIds, $exercise['lessonId'] ?? null, $exerciseStat)) {
        $exerciseStat['read']++;
        $problemy[] = 'Exercise ' . (string) ($exercise['id'] ?? '') . ': урок не найден';
        continue;
    }

    $row = [
        'id' => (string) ($exercise['id'] ?? ''),
        'lesson_id' => (string) ($exercise['lessonId'] ?? ''),
        'type' => $type,
        'question' => is_scalar($exercise['question'] ?? null) ? (string) $exercise['question'] : '',
        'options' => is_scalar($exercise['options'] ?? null) ? (string) $exercise['options'] : null,
        'correct_answer' => is_scalar($exercise['correctAnswer'] ?? null) ? (string) $exercise['correctAnswer'] : '',
        'explanation' => (string) ($exercise['explanation'] ?? ''),
        'difficulty' => (int) ($exercise['difficulty'] ?? 1),
        'skill' => is_scalar($exercise['skill'] ?? null) ? (string) $exercise['skill'] : null,
        'xp_reward' => (int) ($exercise['xpReward'] ?? 5),
        'audio_url' => is_scalar($exercise['audioUrl'] ?? null) ? (string) $exercise['audioUrl'] : null,
        'sort' => (int) ($exercise['order'] ?? 0),
    ];

    if ($write) {
        importUpsert('learn_exercise', $row, $exerciseStat);
    } else {
        $exerciseStat['read']++;
        $exerciseStat['written']++;
    }
}
$itogi['Exercise'] = $exerciseStat;

$attemptStat = statNew();
foreach (importRows($dumpDir, 'ExerciseAttempt') as $attempt) {
    $row = [
        'id' => (string) ($attempt['id'] ?? ''),
        'user_id' => (string) ($attempt['userId'] ?? ''),
        'exercise_id' => (string) ($attempt['exerciseId'] ?? ''),
        'is_correct' => in_array($attempt['isCorrect'] ?? false, [true, 1, '1', 't'], true) ? 1 : 0,
        'answer' => is_scalar($attempt['answer'] ?? null) ? (string) $attempt['answer'] : '',
        'xp_awarded' => (int) ($attempt['xpAwarded'] ?? 0),
        'created_at' => importStamp($attempt['createdAt'] ?? null) ?? $stamp,
    ];

    if ($write) {
        importUpsert('learn_attempt', $row, $attemptStat);
    } else {
        $attemptStat['read']++;
        $attemptStat['written']++;
    }
}
$itogi['ExerciseAttempt'] = $attemptStat;

$itogi['Test'] = importPlain(
    $write,
    $dumpDir,
    'Test',
    'learn_test',
    ['kind' => 'kind', 'lessonId' => 'lesson_id', 'moduleId' => 'module_id', 'title' => 'title', 'passScore' => 'pass_score', 'status' => 'status'],
    [],
    [],
    ['pass_score'],
    [],
    $problemy,
    ['lesson_id', 'module_id']
);

$testQuestionStat = statNew();
foreach (importRows($dumpDir, 'TestQuestion') as $question) {
    if (!importOrphans($testIds, $question['testId'] ?? null, $testQuestionStat)) {
        $testQuestionStat['read']++;
        $problemy[] = 'TestQuestion ' . (string) ($question['id'] ?? '') . ': тест не найден';
        continue;
    }

    $row = [
        'id' => (string) ($question['id'] ?? ''),
        'test_id' => (string) ($question['testId'] ?? ''),
        'skill' => (string) ($question['skill'] ?? ''),
        'text' => (string) ($question['text'] ?? ''),
        'options' => is_scalar($question['options'] ?? null) ? (string) $question['options'] : '[]',
        'correct_option_index' => (int) ($question['correctOptionIndex'] ?? 0),
        'explanation' => (string) ($question['explanation'] ?? ''),
        'audio_url' => is_scalar($question['audioUrl'] ?? null) ? (string) $question['audioUrl'] : null,
        'passage_ref' => is_scalar($question['passageRef'] ?? null) ? (string) $question['passageRef'] : null,
        'sort' => (int) ($question['order'] ?? 0),
    ];

    if ($write) {
        importUpsert('learn_test_question', $row, $testQuestionStat);
    } else {
        $testQuestionStat['read']++;
        $testQuestionStat['written']++;
    }
}
$itogi['TestQuestion'] = $testQuestionStat;

$testAttemptStat = statNew();
foreach (importRows($dumpDir, 'TestAttempt') as $attempt) {
    $row = [
        'id' => (string) ($attempt['id'] ?? ''),
        'user_id' => (string) ($attempt['userId'] ?? ''),
        'test_id' => (string) ($attempt['testId'] ?? ''),
        'score' => (int) ($attempt['score'] ?? 0),
        'vocabulary_score' => isset($attempt['vocabularyScore']) ? (int) $attempt['vocabularyScore'] : null,
        'grammar_score' => isset($attempt['grammarScore']) ? (int) $attempt['grammarScore'] : null,
        'reading_score' => isset($attempt['readingScore']) ? (int) $attempt['readingScore'] : null,
        'listening_score' => isset($attempt['listeningScore']) ? (int) $attempt['listeningScore'] : null,
        'passed' => in_array($attempt['passed'] ?? false, [true, 1, '1', 't'], true) ? 1 : 0,
        'recommended_level_code' => is_scalar($attempt['recommendedLevelCode'] ?? null) ? (string) $attempt['recommendedLevelCode'] : null,
        'recommended_start_module_id' => is_scalar($attempt['recommendedStartModuleId'] ?? null) ? (string) $attempt['recommendedStartModuleId'] : null,
        'answers' => is_scalar($attempt['answers'] ?? null) ? (string) $attempt['answers'] : '[]',
        'created_at' => importStamp($attempt['createdAt'] ?? null) ?? $stamp,
    ];

    if ($write) {
        importUpsert('learn_test_attempt', $row, $testAttemptStat);
    } else {
        $testAttemptStat['read']++;
        $testAttemptStat['written']++;
    }
}
$itogi['TestAttempt'] = $testAttemptStat;

$progressStat = statNew();
foreach (importRows($dumpDir, 'UserProgress') as $progress) {
    $row = [
        'user_id' => (string) ($progress['userId'] ?? ''),
        'current_level_id' => is_scalar($progress['currentLevelId'] ?? null) ? (string) $progress['currentLevelId'] : null,
        'completed_lessons' => is_scalar($progress['completedLessons'] ?? null) ? (string) $progress['completedLessons'] : '[]',
        'completed_modules' => is_scalar($progress['completedModules'] ?? null) ? (string) $progress['completedModules'] : '[]',
        'total_study_seconds' => (int) ($progress['totalStudySeconds'] ?? 0),
        'current_module_order' => (int) ($progress['currentModuleOrder'] ?? 1),
        'current_lesson_order' => (int) ($progress['currentLessonOrder'] ?? 1),
    ];

    if ($write) {
        // У прогресса первичный ключ — user_id, а не id: вставка идёт своим путём.
        $exists = one('SELECT user_id FROM ' . t('learn_progress') . ' WHERE user_id = ?', [$row['user_id']]);
        $progressStat['read']++;
        if ($exists === null) {
            insert('learn_progress', $row);
            $progressStat['written']++;
        } else {
            $progressStat['skipped']['уже есть'] = ($progressStat['skipped']['уже есть'] ?? 0) + 1;
        }
    } else {
        $progressStat['read']++;
        $progressStat['written']++;
    }
}
$itogi['UserProgress'] = $progressStat;

$xpStat = statNew();
foreach (importRows($dumpDir, 'UserXp') as $xp) {
    $row = ['user_id' => (string) ($xp['userId'] ?? ''), 'total' => (int) ($xp['total'] ?? 0)];
    $xpStat['read']++;

    if ($write) {
        $exists = one('SELECT user_id FROM ' . t('learn_xp') . ' WHERE user_id = ?', [$row['user_id']]);
        if ($exists === null) {
            insert('learn_xp', $row);
            $xpStat['written']++;
        } else {
            $xpStat['skipped']['уже есть'] = ($xpStat['skipped']['уже есть'] ?? 0) + 1;
        }
    } else {
        $xpStat['written']++;
    }
}
$itogi['UserXp'] = $xpStat;

$streakStat = statNew();
foreach (importRows($dumpDir, 'UserStreak') as $streak) {
    $row = [
        'user_id' => (string) ($streak['userId'] ?? ''),
        'current_days' => (int) ($streak['currentDays'] ?? 0),
        'longest_days' => (int) ($streak['longestDays'] ?? 0),
        'last_study_at' => importDay($streak['lastStudyAt'] ?? null),
    ];
    $streakStat['read']++;

    if ($write) {
        $exists = one('SELECT user_id FROM ' . t('learn_streak') . ' WHERE user_id = ?', [$row['user_id']]);
        if ($exists === null) {
            insert('learn_streak', $row);
            $streakStat['written']++;
        } else {
            $streakStat['skipped']['уже есть'] = ($streakStat['skipped']['уже есть'] ?? 0) + 1;
        }
    } else {
        $streakStat['written']++;
    }
}
$itogi['UserStreak'] = $streakStat;

$statsStat = statNew();
foreach (importRows($dumpDir, 'UserStatistics') as $statistics) {
    $row = [
        'user_id' => (string) ($statistics['userId'] ?? ''),
        'lessons_completed' => (int) ($statistics['lessonsCompleted'] ?? 0),
        'tests_taken' => (int) ($statistics['testsTaken'] ?? 0),
        'exercises_done' => (int) ($statistics['exercisesDone'] ?? 0),
        'correct_rate' => (float) ($statistics['correctRate'] ?? 0),
    ];
    $statsStat['read']++;

    if ($write) {
        $exists = one('SELECT user_id FROM ' . t('learn_statistics') . ' WHERE user_id = ?', [$row['user_id']]);
        if ($exists === null) {
            insert('learn_statistics', $row);
            $statsStat['written']++;
        } else {
            $statsStat['skipped']['уже есть'] = ($statsStat['skipped']['уже есть'] ?? 0) + 1;
        }
    } else {
        $statsStat['written']++;
    }
}
$itogi['UserStatistics'] = $statsStat;

// ── Сайт: меню, страницы, секции, заявки (раздел 4.3) ──────────────────────────
$menuStat = statNew();
foreach (importRows($dumpDir, 'MenuItem') as $item) {
    $row = [
        'id' => (string) ($item['id'] ?? ''),
        'slot' => (string) ($item['slot'] ?? 'HEADER'),
        'section' => (string) ($item['section'] ?? ''),
        'label' => (string) ($item['label'] ?? ''),
        'href' => (string) ($item['href'] ?? ''),
        'blank' => in_array($item['blank'] ?? false, [true, 1, '1', 't'], true) ? 1 : 0,
        'sort' => (int) ($item['order'] ?? 0),
        'visible' => in_array($item['visible'] ?? false, [true, 1, '1', 't'], true) ? 1 : 0,
        // У пункта меню портала момента правки нет, а подстановка момента переноса делала бы
        // пункт «изменённым» при каждом повторном прогоне. Пустая дата не годится (колонка
        // обязательна), поэтому берётся начало эпохи — стабильное значение, одинаковое всегда.
        'updated_at' => importStamp($item['updatedAt'] ?? null) ?? '1970-01-01 00:00:00',
    ];

    if ($write) {
        importUpsert('menu_item', $row, $menuStat);
    } else {
        $menuStat['read']++;
        $menuStat['written']++;
    }
}
$itogi['MenuItem'] = $menuStat;

$pageStat = statNew();
$oldSlugStat = statNew();
foreach (importRows($dumpDir, 'Page') as $page) {
    $row = [
        'id' => (string) ($page['id'] ?? ''),
        'slug' => (string) ($page['slug'] ?? ''),
        'title' => (string) ($page['title'] ?? ''),
        'description' => (string) ($page['description'] ?? ''),
        'status' => (string) ($page['status'] ?? 'DRAFT'),
        'noindex' => in_array($page['noindex'] ?? false, [true, 1, '1', 't'], true) ? 1 : 0,
        'created_at' => importStamp($page['createdAt'] ?? null) ?? $stamp,
        'updated_at' => importStamp($page['updatedAt'] ?? null) ?? $stamp,
    ];

    if ($write) {
        importUpsert('page', $row, $pageStat);
    } else {
        $pageStat['read']++;
        $pageStat['written']++;
    }

    // oldSlugs портала — массив, в CMS — строки tbl_page_old_slug: история адресов показывается списком.
    foreach (is_array($page['oldSlugs'] ?? null) ? $page['oldSlugs'] : [] as $oldSlug) {
        $slug = trim((string) $oldSlug);
        $oldSlugStat['read']++;

        if ($slug === '') {
            continue;
        }

        if ($write) {
            if (value('SELECT slug FROM ' . t('page_old_slug') . ' WHERE slug = ?', [$slug]) !== null) {
                $oldSlugStat['skipped']['уже есть'] = ($oldSlugStat['skipped']['уже есть'] ?? 0) + 1;
                continue;
            }

            insert('page_old_slug', [
                'slug' => $slug,
                'page_id' => $row['id'],
                'created_at' => $stamp,
            ]);
        }

        $oldSlugStat['written']++;
    }
}
$itogi['Page'] = $pageStat;
$itogi['Page.oldSlugs'] = $oldSlugStat;

$sectionStat = statNew();
foreach (importRows($dumpDir, 'LandingSection') as $section) {
    // Момент правки берётся из источника: подстановка момента переноса делала бы каждую секцию
    // «изменённой» при повторном прогоне и забивала отчёт ложными расхождениями.
    $sectionStamp = importStamp($section['updatedAt'] ?? null) ?? $stamp;
    $row = importSectionRow($section, (string) ($section['id'] ?? ''), $sectionStamp);

    if ($write) {
        importUpsert('page_section', $row, $sectionStat);
    } else {
        $sectionStat['read']++;
        $sectionStat['written']++;
    }

    // Виды, которых в ядре нет: печатать их нечем, и человек обязан об этом узнать.
    if (in_array($row['kind'], ['levels', 'demo'], true)) {
        $problemy[] = 'LandingSection ' . $row['id'] . ': вид «' . $row['kind'] . '» закрыт разделом «Курсы» — правьте вручную';
    }
}
$itogi['LandingSection'] = $sectionStat;

$leadStat = statNew();
foreach (importRows($dumpDir, 'Lead') as $lead) {
    $row = [
        'id' => (string) ($lead['id'] ?? ''),
        'name' => mb_substr((string) ($lead['name'] ?? ''), 0, 80),
        'contact' => mb_substr((string) ($lead['contact'] ?? ''), 0, 120),
        'message' => is_scalar($lead['message'] ?? null) ? (string) $lead['message'] : null,
        'source' => mb_substr((string) ($lead['source'] ?? ''), 0, 120),
        'status' => (string) ($lead['status'] ?? 'NEW'),
        'done_at' => importStamp($lead['doneAt'] ?? null),
        'confirm_sent_at' => importStamp($lead['confirmSentAt'] ?? null),
        'reply_text' => is_scalar($lead['replyText'] ?? null) ? (string) $lead['replyText'] : null,
        'reply_sent_at' => importStamp($lead['replySentAt'] ?? null),
        'created_at' => importStamp($lead['createdAt'] ?? null) ?? $stamp,
    ];

    if ($write) {
        importUpsert('lead', $row, $leadStat);
    } else {
        $leadStat['read']++;
        $leadStat['written']++;
    }
}
$itogi['Lead'] = $leadStat;

// ── Картинки ───────────────────────────────────────────────────────────────────
if ($assetsDir !== '') {
    $targetDir = __DIR__ . '/../public/assets/longread';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Отказ: не удалось создать каталог $targetDir\n");
        exit(1);
    }

    $missingAssets = [];
    $copied = importAssets($assetsDir, $targetDir, $missingAssets);
    echo "\nКартинки: скопировано $copied";
    echo $missingAssets === [] ? ".\n" : ', не удалось: ' . implode(', ', $missingAssets) . "\n";
}

// ── Отчёт ──────────────────────────────────────────────────────────────────────
echo "\n";
foreach ($itogi as $table => $stat) {
    echo importStatLine($table, $stat), "\n";
}

if ($problemy !== []) {
    // Отчёт читает человек, поэтому печатаются первые находки, а не тысячи строк: полный список
    // уходит в файл, который называет сам отчёт.
    $limit = 40;
    echo "\nТребуют внимания человека (" . count($problemy) . "):\n";
    foreach (array_slice($problemy, 0, $limit) as $line) {
        echo '  - ', $line, "\n";
    }

    if (count($problemy) > $limit) {
        $reportFile = $dumpDir . '/import-problems.txt';
        file_put_contents($reportFile, implode("\n", $problemy) . "\n");
        echo '  … и ещё ', count($problemy) - $limit, '; полный список: ', $reportFile, "\n";
    }
}

if (!$write) {
    echo "\nРежим проверки: в базу ничего не записано. Для записи добавьте --write.\n";
}

exit(0);
