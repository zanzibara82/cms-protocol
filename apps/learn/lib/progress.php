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
