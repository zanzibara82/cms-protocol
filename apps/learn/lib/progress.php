<?php

declare(strict_types=1);

// Прогресс ученика на живой базе — порт progress.ts: начисление опыта, серия занятий,
// попытка упражнения и закрытие урока. Время — UTC: серия считается по календарным дням
// сервера, поэтому «сегодня» и «вчера» сравниваются строками DATE.
//
// completed_lessons/completed_modules — JSON-списки формата портала
// ([{lessonId, completedAt, testScore}], [{moduleId, completedAt}]). Умолчания у колонок
// нет (MySQL не разрешает DEFAULT у MEDIUMTEXT), поэтому каждая запись кладёт '[]' явно.

/**
 * Список объектов из JSON-колонки. Испорченное значение читается как пустой список:
 * прогресс не должен падать из-за одной кривой строки, оставшейся от старой версии.
 *
 * @return list<array<string, mixed>>
 */
function learnJsonList(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    /** @var list<array<string, mixed>> $out */
    $out = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $row = [];
        foreach ($item as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }

        $out[] = $row;
    }

    return $out;
}

// Нулевое и отрицательное начисление строку не создаёт: в портале это ранний выход,
// и тест держит то же правило.
function learnAddXp(string $userId, int $amount): void
{
    if ($amount <= 0) {
        return;
    }

    run(
        'INSERT INTO ' . t('learn_xp') . ' (user_id, total) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE total = total + VALUES(total)',
        [$userId, $amount]
    );
}

/**
 * Отметка занятия и пересчёт серии. Сегодняшний день повторно ничего не меняет,
 * вчерашний продолжает серию, пропуск начинает её заново с единицы.
 *
 * @return int текущая серия в днях
 */
function learnTouchStreak(string $userId): int
{
    run(
        'INSERT INTO ' . t('learn_streak') . ' (user_id) VALUES (?)'
            . ' ON DUPLICATE KEY UPDATE user_id = user_id',
        [$userId]
    );

    $row = one(
        'SELECT current_days, longest_days, last_study_at FROM ' . t('learn_streak') . ' WHERE user_id = ?',
        [$userId]
    );

    if ($row === null) {
        return 0;
    }

    $now = nowUtc();
    $today = utcDayKey($now);
    $last = $row['last_study_at'] === null ? '' : (string) $row['last_study_at'];

    if ($last === $today) {
        return (int) $row['current_days'];
    }

    $current = 1;
    if ($last !== '' && $last === utcDayKey($now->modify('-1 day'))) {
        $current = (int) $row['current_days'] + 1;
    }

    $longest = max($current, (int) $row['longest_days']);

    run(
        'UPDATE ' . t('learn_streak')
            . ' SET current_days = ?, longest_days = ?, last_study_at = ? WHERE user_id = ?',
        [$current, $longest, $today, $userId]
    );

    return $current;
}

/**
 * Попытка упражнения: пишет строку всегда, опыт платит только за первую верную.
 * Статистика считает все попытки, а correct_rate — долю верных по всей истории.
 *
 * @param array{user_id: string, exercise_id: string, correct: bool, answer: mixed, xp_reward: int} $params
 * @return array{xp: int, already_correct: bool}
 */
function learnRecordExerciseAttempt(array $params): array
{
    $userId = (string) $params['user_id'];
    $exerciseId = (string) $params['exercise_id'];
    $correct = ($params['correct'] ?? false) === true;
    $xpReward = max(0, (int) ($params['xp_reward'] ?? 0));
    $answer = $params['answer'] ?? null;

    return tx(static function () use ($userId, $exerciseId, $correct, $xpReward, $answer): array {
        $alreadyCorrect = (int) value(
            'SELECT COUNT(*) FROM ' . t('learn_attempt')
                . ' WHERE user_id = ? AND exercise_id = ? AND is_correct = 1',
            [$userId, $exerciseId]
        ) > 0;

        $xp = $correct && !$alreadyCorrect ? $xpReward : 0;

        insert('learn_attempt', [
            'id' => newId(),
            'user_id' => $userId,
            'exercise_id' => $exerciseId,
            'is_correct' => $correct ? 1 : 0,
            'answer' => learnJsonText($answer),
            'xp_awarded' => $xp,
            'created_at' => utcStamp(nowUtc()),
        ]);

        learnAddXp($userId, $xp);
        learnTouchStreak($userId);

        run(
            'INSERT INTO ' . t('learn_statistics') . ' (user_id, exercises_done) VALUES (?, 1)'
                . ' ON DUPLICATE KEY UPDATE exercises_done = exercises_done + 1',
            [$userId]
        );

        $tally = one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(is_correct), 0) AS right_count FROM ' . t('learn_attempt')
                . ' WHERE user_id = ?',
            [$userId]
        );

        $total = $tally === null ? 0 : (int) $tally['total'];
        $right = $tally === null ? 0 : (int) $tally['right_count'];
        $rate = $total > 0 ? $right / $total : 0.0;

        run(
            'UPDATE ' . t('learn_statistics') . ' SET correct_rate = ? WHERE user_id = ?',
            [$rate, $userId]
        );

        return ['xp' => $xp, 'already_correct' => $alreadyCorrect];
    });
}

/**
 * Идемпотентное закрытие урока: повторный вызов не начисляет опыт и не двигает прогресс.
 * За урок платится LESSON_COMPLETE + TEST_COMPLETE, идеальный тест получает бонус,
 * последний урок модуля — MODULE_COMPLETE один раз на модуль.
 *
 * @param array{user_id: string, lesson_id: string, module_id: string, module_order: int, lesson_order: int, test_score: int, next_lesson: array<string, mixed>|null, module_completed: bool} $params
 * @return array{already_completed: bool, xp: int, module_xp: int, module_completed: bool}
 */
function learnCompleteLesson(array $params): array
{
    $userId = (string) $params['user_id'];
    $lessonId = (string) $params['lesson_id'];
    $moduleId = (string) $params['module_id'];
    $moduleOrder = (int) $params['module_order'];
    $lessonOrder = (int) $params['lesson_order'];
    $testScore = max(0, min(100, (int) ($params['test_score'] ?? 0)));
    $nextLesson = is_array($params['next_lesson'] ?? null) ? $params['next_lesson'] : null;
    $moduleCompleted = ($params['module_completed'] ?? false) === true;

    return tx(static function () use (
        $userId,
        $lessonId,
        $moduleId,
        $moduleOrder,
        $lessonOrder,
        $testScore,
        $nextLesson,
        $moduleCompleted
    ): array {
        $progress = learnProgressRow($userId);
        $completed = learnJsonList((string) $progress['completed_lessons']);

        foreach ($completed as $entry) {
            if ((string) ($entry['lessonId'] ?? '') === $lessonId) {
                return ['already_completed' => true, 'xp' => 0, 'module_xp' => 0, 'module_completed' => false];
            }
        }

        $now = utcStamp(nowUtc());
        $completed[] = ['lessonId' => $lessonId, 'completedAt' => $now, 'testScore' => $testScore];

        $xp = LEARN_XP_RULES['LESSON_COMPLETE'] + LEARN_XP_RULES['TEST_COMPLETE'];
        if ($testScore === 100) {
            $xp += LEARN_XP_RULES['PERFECT_TEST_BONUS'];
        }

        $modules = learnJsonList((string) $progress['completed_modules']);
        $moduleXp = 0;
        $justCompleted = false;

        if ($moduleCompleted) {
            $alreadyModule = false;
            foreach ($modules as $entry) {
                if ((string) ($entry['moduleId'] ?? '') === $moduleId) {
                    $alreadyModule = true;
                    break;
                }
            }

            if (!$alreadyModule) {
                $modules[] = ['moduleId' => $moduleId, 'completedAt' => $now];
                $moduleXp = LEARN_XP_RULES['MODULE_COMPLETE'];
                $justCompleted = true;
            }
        }

        $nextModuleOrder = $nextLesson === null
            ? $moduleOrder
            : (int) ($nextLesson['module_order'] ?? $moduleOrder);
        $nextLessonOrder = $nextLesson === null
            ? $lessonOrder + 1
            : (int) ($nextLesson['lesson_order'] ?? ($lessonOrder + 1));

        run(
            'UPDATE ' . t('learn_progress')
                . ' SET completed_lessons = ?, completed_modules = ?, current_module_order = ?, current_lesson_order = ?'
                . ' WHERE user_id = ?',
            [learnJsonText($completed), learnJsonText($modules), $nextModuleOrder, $nextLessonOrder, $userId]
        );

        learnAddXp($userId, $xp + $moduleXp);
        learnTouchStreak($userId);

        run(
            'INSERT INTO ' . t('learn_statistics') . ' (user_id, lessons_completed, tests_taken) VALUES (?, 1, 1)'
                . ' ON DUPLICATE KEY UPDATE lessons_completed = lessons_completed + 1, tests_taken = tests_taken + 1',
            [$userId]
        );

        return [
            'already_completed' => false,
            'xp' => $xp + $moduleXp,
            'module_xp' => $moduleXp,
            'module_completed' => $justCompleted,
        ];
    });
}

/**
 * Строка прогресса ученика, созданная при первом обращении. Пустые списки кладутся явно:
 * у MEDIUMTEXT нет умолчания, и без них INSERT отвергла бы база.
 *
 * @return array<string, mixed>
 */
function learnProgressRow(string $userId): array
{
    run(
        'INSERT INTO ' . t('learn_progress')
            . ' (user_id, completed_lessons, completed_modules) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = user_id',
        [$userId, '[]', '[]']
    );

    $row = one(
        'SELECT user_id, current_level_id, completed_lessons, completed_modules, total_study_seconds,'
            . ' current_module_order, current_lesson_order FROM ' . t('learn_progress') . ' WHERE user_id = ?',
        [$userId]
    );

    if ($row === null) {
        throw new RuntimeException('Строка прогресса не создалась: ' . $userId);
    }

    return $row;
}

/**
 * Строка учебного профиля, созданная при первом обращении: онбординг и placement
 * дописывают её поля, а кабинет читает готовность.
 *
 * @return array<string, mixed>
 */
function learnProfileRow(string $userId): array
{
    run(
        'INSERT INTO ' . t('learn_profile') . ' (user_id, created_at) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = user_id',
        [$userId, utcStamp(nowUtc())]
    );

    $row = one(
        'SELECT user_id, onboarding_done, placement_done, self_assessment, goal, daily_minutes,'
            . ' start_level_id, created_at FROM ' . t('learn_profile') . ' WHERE user_id = ?',
        [$userId]
    );

    if ($row === null) {
        throw new RuntimeException('Строка профиля не создалась: ' . $userId);
    }

    return $row;
}

/**
 * Upsert профиля: онбординг пишет три ответа, placement — готовность и стартовый уровень.
 * Обновляются только переданные поля, остальные сохраняются. Неизвестное поле — ошибка
 * вызывающего кода, а не молча потерянные данные.
 *
 * @param array<string, mixed> $fields
 */
function learnSaveProfile(string $userId, array $fields): void
{
    $allowed = ['onboarding_done', 'placement_done', 'self_assessment', 'goal', 'daily_minutes', 'start_level_id'];
    $values = [];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException('Неизвестное поле профиля: ' . $key);
        }

        $values[$key] = is_bool($value) ? (int) $value : $value;
    }

    $columns = ['user_id', 'created_at'];
    $args = [$userId, utcStamp(nowUtc())];
    $updates = [];

    foreach ($values as $key => $value) {
        $columns[] = $key;
        $args[] = $value;
        $updates[] = $key . ' = VALUES(' . $key . ')';
    }

    if ($updates === []) {
        $updates[] = 'user_id = user_id';
    }

    run(
        'INSERT INTO ' . t('learn_profile') . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . inPlaceholders(count($columns)) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
        $args
    );
}

/**
 * Строка профиля без записи в базу: чтение кабинета не должно создавать данные.
 * Возвращает null, если профиля ещё нет.
 *
 * @return array{user_id: string, onboarding_done: int, placement_done: int, self_assessment: string, goal: string, daily_minutes: int, start_level_id: string|null, created_at: string}|null
 */
function learnProfileRead(string $userId): ?array
{
    $row = one(
        'SELECT user_id, onboarding_done, placement_done, self_assessment, goal, daily_minutes,'
            . ' start_level_id, created_at FROM ' . t('learn_profile') . ' WHERE user_id = ? LIMIT 1',
        [$userId]
    );

    if ($row === null) {
        return null;
    }

    return [
        'user_id' => (string) $row['user_id'],
        'onboarding_done' => (int) $row['onboarding_done'],
        'placement_done' => (int) $row['placement_done'],
        'self_assessment' => (string) $row['self_assessment'],
        'goal' => (string) $row['goal'],
        'daily_minutes' => (int) $row['daily_minutes'],
        'start_level_id' => $row['start_level_id'] === null ? null : (string) $row['start_level_id'],
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * Статистика по четырём навыкам кабинета из попыток упражнений. Навык без попыток —
 * ноль попыток и ноль верных; шаблон показывает «нет данных».
 *
 * @return list<array{skill: string, label: string, right: int, total: int}>
 */
function learnSkillStats(string $userId): array
{
    $stats = [];
    foreach (LEARN_SKILL_TITLES as $skill) {
        $stats[] = [
            'skill' => $skill,
            'label' => LEARN_SKILL_LABELS[$skill],
            'right' => 0,
            'total' => 0,
        ];
    }

    $rows = all(
        'SELECT e.skill AS skill, COUNT(*) AS total, COALESCE(SUM(a.is_correct), 0) AS `right`'
            . ' FROM ' . t('learn_attempt') . ' AS a'
            . ' JOIN ' . t('learn_exercise') . ' AS e ON e.id = a.exercise_id'
            . ' WHERE a.user_id = ? GROUP BY e.skill',
        [$userId]
    );

    foreach ($rows as $row) {
        $skill = (string) ($row['skill'] ?? '');
        foreach ($stats as &$stat) {
            if ((string) $stat['skill'] === $skill) {
                $stat['right'] = (int) ($row['right'] ?? 0);
                $stat['total'] = (int) ($row['total'] ?? 0);
                break;
            }
        }
    }
    unset($stat);

    return $stats;
}

/**
 * История попыток тестов — последние десять по дате, параметром LIMIT.
 *
 * @return list<array<string, mixed>>
 */
function learnTestHistory(string $userId): array
{
    return all(
        'SELECT score, vocabulary_score, grammar_score, reading_score, listening_score, created_at'
            . ' FROM ' . t('learn_test_attempt') . ' WHERE user_id = ?'
            . ' ORDER BY created_at DESC, id DESC LIMIT 10',
        [$userId]
    );
}

/**
 * Сводка кабинета: приветствие, серия, статистика, слабый и сильный навык,
 * текущий модуль со следующим уроком. Чтение не пишет в базу.
 *
 * @return array{
 *     greeting: string,
 *     streak: array{days: int, today: bool, nudge: string},
 *     stats: array{xp: int, streak: int, lessons: int, tests: int, exercises: int},
 *     weak: array{label: string, right: int, percent: int, advice: string, total: int}|null,
 *     strong: array{skill: string, label: string, right: int, total: int}|null,
 *     currentModule: array<string, mixed>|null,
 *     modulePercent: int,
 *     nextLesson: array<string, mixed>|null,
 *     courseTitle: string
 * }
 */
function learnCabinetSummary(string $userId): array
{
    $now = nowUtc();

    $streakRow = one(
        'SELECT current_days, longest_days, last_study_at FROM ' . t('learn_streak') . ' WHERE user_id = ? LIMIT 1',
        [$userId]
    );

    $days = $streakRow === null ? 0 : (int) ($streakRow['current_days'] ?? 0);
    $today = $streakRow !== null
        && (string) ($streakRow['last_study_at'] ?? '') === utcDayKey($now);

    $xpRow = one('SELECT total FROM ' . t('learn_xp') . ' WHERE user_id = ? LIMIT 1', [$userId]);

    $xp = $xpRow === null ? 0 : (int) ($xpRow['total'] ?? 0);

    $statRow = one(
        'SELECT lessons_completed, tests_taken, exercises_done FROM ' . t('learn_statistics') . ' WHERE user_id = ? LIMIT 1',
        [$userId]
    );

    $lessons = $statRow === null ? 0 : (int) ($statRow['lessons_completed'] ?? 0);
    $tests = $statRow === null ? 0 : (int) ($statRow['tests_taken'] ?? 0);
    $exercises = $statRow === null ? 0 : (int) ($statRow['exercises_done'] ?? 0);

    $skillStats = learnSkillStats($userId);

    $weak = learnWeakSkill($skillStats, LEARN_SKILL_SAMPLE_MIN);

    $strong = $weak === null ? null : learnStrongSkill(
        $skillStats,
        (string) ($weak['skill'] ?? ''),
        LEARN_SKILL_SAMPLE_MIN
    );

    $tree = learnCourseTree($userId);
    $courseTitle = (string) (($tree['course'] ?? null)['title'] ?? '');
    $currentModule = null;
    $modulePercent = 0;
    $nextLesson = null;

    foreach ($tree['modules'] as $module) {
        if ($module['completed_count'] < $module['lesson_count']) {
            $currentModule = $module;
            $modulePercent = (int) ($module['percent'] ?? 0);
            foreach ($module['lessons'] as $lesson) {
                if (($lesson['unlocked'] ?? false) === true && ($lesson['completed'] ?? false) !== true) {
                    $nextLesson = $lesson;
                    break;
                }
            }
            break;
        }
    }

    return [
        'greeting' => learnDayGreeting($now),
        'streak' => [
            'days' => $days,
            'today' => $today,
            'nudge' => learnStreakNudge($days, $today),
        ],
        'stats' => [
            'xp' => $xp,
            'streak' => $days,
            'lessons' => $lessons,
            'tests' => $tests,
            'exercises' => $exercises,
        ],
        'weak' => $weak === null ? null : learnFocusOf($weak),
        'strong' => $strong,
        'currentModule' => $currentModule,
        'modulePercent' => $modulePercent,
        'nextLesson' => $nextLesson,
        'courseTitle' => $courseTitle,
    ];
}
