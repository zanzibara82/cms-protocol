<?php

declare(strict_types=1);

// Контроллеры ученического контура: курс, модуль и урок шагами. GET-экраны только читают
// данные и не печатают правильных ответов; POST-жизнь урока проверяет попытку, пишет
// результат и отдаёт разбор через flash после редиректа.

/**
 * Дерево текущего курса: уровень, модули и проценты.
 *
 * @param array<string, string> $params
 */
function learnCoursePage(array $params = []): void
{
    $user = learnUser();
    $tree = learnCourseTree((string) $user['id']);

    render('learn/course', [
        'tree' => $tree,
    ], [
        'title' => 'Курсы',
        'description' => 'Текущий курс и модули школы.',
        'noindex' => true,
    ]);
}

/**
 * Экран модуля: список уроков с галочками и замками.
 *
 * @param array<string, string> $params
 */
function learnModulePage(array $params = []): void
{
    $user = learnUser();
    $moduleId = trim((string) ($params['id'] ?? ''));
    $payload = $moduleId === '' ? null : learnModulePayload((string) $user['id'], $moduleId);

    if ($payload === null) {
        notFound();
    }

    render('learn/module', [
        'payload' => $payload,
    ], [
        'title' => 'Модуль: ' . $payload['module']['title'],
        'description' => $payload['module']['description'],
        'noindex' => true,
    ]);
}

/**
 * Экран урока: шаг из адреса либо продолжение с места остановки. Закрытый урок
 * не показывает содержимого — только карточку с причиной и ссылкой на модуль.
 *
 * @param array<string, string> $params
 */
function learnLessonPage(array $params = []): void
{
    $user = learnUser();
    $slug = trim((string) ($params['slug'] ?? ''));
    $payload = $slug === '' ? null : learnLessonPayload((string) $user['id'], $slug);

    if ($payload === null) {
        notFound();
    }

    $lessonTitle = (string) $payload['lesson']['title'];
    $moduleTitle = (string) $payload['meta']['module']['title'];

    if (($payload['meta']['unlocked'] ?? false) !== true) {
        render('learn/locked', [
            'payload' => $payload,
        ], [
            'title' => 'Урок закрыт',
            'description' => 'Урок «' . $lessonTitle . '» откроется после предыдущего.',
            'noindex' => true,
        ]);

        return;
    }

    $steps = learnLessonSteps($payload);
    $requested = queryString('step', '');
    $requested = is_string($requested) ? $requested : '';
    $step = in_array($requested, $steps, true) ? $requested : learnResumeStep($payload);

    $exercise = null;
    $exerciseIndex = 0;
    $exerciseRows = $payload['lesson']['exercises'];
    $exerciseTotal = count($exerciseRows);

    if ($step === 'exercises') {
        $requestedExercise = queryString('exercise', '');
        $exercise = learnCurrentExercise(
            $payload,
            is_string($requestedExercise) ? $requestedExercise : null
        );

        if ($exercise !== null) {
            foreach ($exerciseRows as $index => $row) {
                if ((string) ($row['id'] ?? '') === (string) ($exercise['id'] ?? '')) {
                    $exerciseIndex = (int) $index + 1;
                    break;
                }
            }
        }
    }

    // Flash живёт ровно один показ: следующий GET печатает упражнение уже без плашки.
    $attempt = null;
    if ($step === 'exercises' && $exercise !== null) {
        $flashed = learnTakeFlash('learn_attempt');
        if ($flashed !== null && (string) ($flashed['exercise_id'] ?? '') === (string) ($exercise['id'] ?? '')) {
            $attempt = $flashed;
        }
    }

    $testResult = $step === 'test' ? learnTakeFlash('learn_test') : null;
    $nextExerciseUrl = '';
    $award = null;

    if ($step === 'exercises' && $exercise !== null) {
        $done = (int) ($payload['meta']['done_count'] ?? 0);
        $total = (int) ($payload['meta']['total_exercises'] ?? 0);
        $lessonUrl = '/lesson/' . rawurlencode((string) $payload['lesson']['slug']);
        $nextStep = $done >= $total
            ? (($payload['lesson']['test'] ?? null) === null ? 'result' : 'test')
            : 'exercises';
        $nextExerciseUrl = $lessonUrl . '?step=' . $nextStep;
    }

    if ($step === 'result' && ($payload['meta']['completed'] ?? false) === true) {
        $award = learnLessonAward((string) $user['id'], $payload);
    }

    render('learn/lesson', [
        'payload' => $payload,
        'step' => $step,
        'steps' => $steps,
        'exercise' => $exercise,
        'exerciseIndex' => $exerciseIndex,
        'exerciseTotal' => $exerciseTotal,
        'attempt' => $attempt,
        'testResult' => $testResult,
        'nextExerciseUrl' => $nextExerciseUrl,
        'award' => $award,
        'moduleUrl' => '/learn/module/' . rawurlencode((string) $payload['meta']['module']['id']),
    ], [
        'title' => 'Урок: ' . $lessonTitle,
        'description' => $moduleTitle . ' — ' . $lessonTitle,
        'noindex' => true,
    ]);
}

/**
 * POST-жизнь урока: один обработчик на адресе, действие выбирает `op`. Токен сверяется
 * первым: до него не читаются ни база, ни сессия пользователя.
 *
 * @param array<string, string> $params
 */
function learnLessonAction(array $params = []): void
{
    csrfCheck();

    $user = learnUser();
    $slug = trim((string) ($params['slug'] ?? ''));
    $payload = $slug === '' ? null : learnLessonPayload((string) $user['id'], $slug);

    if ($payload === null || ($payload['meta']['unlocked'] ?? false) !== true) {
        notFound();
    }

    $lessonUrl = '/lesson/' . rawurlencode((string) $payload['lesson']['slug']);
    $op = postString('op');

    if ($op === 'answer') {
        learnSubmitAnswer((string) $user['id'], $payload);
        redirect($lessonUrl . '?step=exercises&exercise=' . rawurlencode(postString('exercise')));
    }

    if ($op === 'test') {
        learnSubmitTest((string) $user['id'], $payload);
        redirect($lessonUrl . '?step=test');
    }

    redirect($lessonUrl);
}

/**
 * Попытка упражнения: проверка ответа, запись попытки и flash-карта для следующего GET.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 */
function learnSubmitAnswer(string $userId, array $payload): void
{
    $exerciseId = postString('exercise');
    $lessonId = (string) ($payload['lesson']['id'] ?? '');
    $row = $exerciseId === '' || $lessonId === '' ? null : one(
        'SELECT id, type, question, options, correct_answer, explanation, skill, xp_reward'
            . ' FROM ' . t('learn_exercise') . ' WHERE id = ? AND lesson_id = ? LIMIT 1',
        [$exerciseId, $lessonId]
    );

    if ($row === null) {
        notFound();
    }

    $posted = postAll();
    $answer = $posted['answer'] ?? null;
    $grade = learnGrade($row, $answer);
    $recorded = learnRecordExerciseAttempt([
        'user_id' => $userId,
        'exercise_id' => (string) $row['id'],
        'correct' => $grade['correct'],
        'answer' => $answer,
        'xp_reward' => (int) $row['xp_reward'],
    ]);

    startSession();
    $_SESSION['learn_attempt'] = [
        'exercise_id' => (string) $row['id'],
        'correct' => $grade['correct'],
        'revealed' => $grade['revealed'],
        'matched' => $grade['matched'] ?? [],
        'required' => $grade['required'] ?? null,
        'xp' => $recorded['xp'],
        'explanation' => (string) ($row['explanation'] ?? ''),
    ];
}

/**
 * Сдача мини-теста: оценка всех вопросов, запись попытки, закрытие урока при проходном
 * балле и flash-карта с разбором. Пересдача пишет новую попытку, но опыт не удваивает:
 * закрытие урока идемпотентно.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 */
function learnSubmitTest(string $userId, array $payload): void
{
    $test = $payload['lesson']['test'] ?? null;
    if (!is_array($test) || (string) ($test['id'] ?? '') === '') {
        notFound();
    }

    $testId = (string) $test['id'];
    $questions = all(
        'SELECT id, skill, text, options, correct_option_index, explanation FROM ' . t('learn_test_question')
            . ' WHERE test_id = ? ORDER BY sort, id LIMIT 100',
        [$testId]
    );

    if ($questions === []) {
        notFound();
    }

    $posted = postAll();
    $rawAnswers = $posted['answer'] ?? null;
    $answers = is_array($rawAnswers) ? $rawAnswers : [];

    /** @var list<array<string, mixed>> $details */
    $details = [];
    /** @var array<string, array{right: int, total: int}> $skillStats */
    $skillStats = [];
    $correctCount = 0;

    foreach ($questions as $question) {
        $questionId = (string) $question['id'];
        $skill = (string) ($question['skill'] ?? '');
        $correctIndex = (int) $question['correct_option_index'];
        $given = $answers[$questionId] ?? null;
        $selected = is_scalar($given) && is_numeric($given) ? (int) $given : -1;
        $correct = $selected === $correctIndex;

        if ($correct) {
            $correctCount++;
        }

        if ($skill !== '') {
            if (!isset($skillStats[$skill])) {
                $skillStats[$skill] = ['right' => 0, 'total' => 0];
            }

            $skillStats[$skill]['total']++;
            if ($correct) {
                $skillStats[$skill]['right']++;
            }
        }

        $details[] = [
            'question_id' => $questionId,
            'skill' => $skill,
            'question' => (string) $question['text'],
            'options' => learnChoiceList($question['options'] ?? null),
            'selected' => $selected,
            'correct_index' => $correctIndex,
            'correct' => $correct,
            'explanation' => (string) ($question['explanation'] ?? ''),
        ];
    }

    $total = count($questions);
    $score = $total > 0 ? (int) round($correctCount * 100 / $total) : 0;
    $passScore = (int) ($test['pass_score'] ?? 0);
    $passed = $score >= $passScore;

    $skillScores = [
        'vocabulary_score' => null,
        'grammar_score' => null,
        'reading_score' => null,
        'listening_score' => null,
    ];
    foreach ([
        'VOCABULARY' => 'vocabulary_score',
        'GRAMMAR' => 'grammar_score',
        'READING' => 'reading_score',
        'LISTENING' => 'listening_score',
    ] as $skill => $column) {
        if (isset($skillStats[$skill])) {
            $stat = $skillStats[$skill];
            $skillScores[$column] = (int) round($stat['right'] * 100 / $stat['total']);
        }
    }

    $storedAnswers = [];
    foreach ($details as $detail) {
        $storedAnswers[] = [
            'questionId' => (string) ($detail['question_id'] ?? ''),
            'optionIndex' => (int) ($detail['selected'] ?? -1),
            'correct' => ($detail['correct'] ?? false) === true,
            'correctIndex' => (int) ($detail['correct_index'] ?? -1),
        ];
    }

    insert('learn_test_attempt', [
        'id' => newId(),
        'user_id' => $userId,
        'test_id' => $testId,
        'score' => $score,
        'vocabulary_score' => $skillScores['vocabulary_score'],
        'grammar_score' => $skillScores['grammar_score'],
        'reading_score' => $skillScores['reading_score'],
        'listening_score' => $skillScores['listening_score'],
        'passed' => $passed ? 1 : 0,
        'recommended_level_code' => null,
        'recommended_start_module_id' => null,
        'answers' => learnJsonText($storedAnswers),
        'created_at' => utcStamp(nowUtc()),
    ]);

    $xp = 0;
    $moduleXp = 0;
    $alreadyCompleted = false;
    $moduleCompleted = false;

    if ($passed) {
        $lesson = $payload['lesson'];
        $meta = $payload['meta'];
        $module = $meta['module'];
        $next = $meta['next_lesson'];
        $nextLesson = null;

        if (is_array($next)) {
            $nextLesson = [
                'module_order' => (int) ($module['sort'] ?? 0),
                'lesson_order' => (int) ($next['sort'] ?? 0),
            ];
        }

        $completed = learnCompleteLesson([
            'user_id' => $userId,
            'lesson_id' => (string) $lesson['id'],
            'module_id' => (string) ($module['id'] ?? ''),
            'module_order' => (int) ($module['sort'] ?? 0),
            'lesson_order' => (int) ($lesson['sort'] ?? 0),
            'test_score' => $score,
            'next_lesson' => $nextLesson,
            'module_completed' => ($meta['module_all_done'] ?? false) === true,
        ]);
        $xp = $completed['xp'];
        $moduleXp = $completed['module_xp'];
        $alreadyCompleted = $completed['already_completed'];
        $moduleCompleted = $completed['module_completed'];
    }

    startSession();
    $_SESSION['learn_test'] = [
        'score' => $score,
        'passed' => $passed,
        'pass_score' => $passScore,
        'xp' => $xp,
        'module_xp' => $moduleXp,
        'already_completed' => $alreadyCompleted,
        'module_completed' => $moduleCompleted,
        'details' => $details,
    ];
}

/**
 * Сессионный flash читается один раз: следующий GET его уже не покажет.
 *
 * @return array<string, mixed>|null
 */
function learnTakeFlash(string $key): ?array
{
    startSession();
    $flash = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);

    if (!is_array($flash)) {
        return null;
    }

    $out = [];
    foreach ($flash as $name => $value) {
        if (is_string($name)) {
            $out[$name] = $value;
        }
    }

    return $out;
}

/**
 * Награда за пройденный урок: ответы упражнений и последней сдачи теста складываются по
 * навыкам, XP считается по правилам урока. Экран результата переиспользует тот же движок,
 * что и кабинет, поэтому цифры не расходятся.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 * @return array{trophy: string, title: string, xp: int, wins: list<string>, focus: array{label: string, right: int, total: int, hint: string}|null, strength: array{label: string, right: int, total: int}|null}
 */
function learnLessonAward(string $userId, array $payload): array
{
    $lessonId = (string) ($payload['lesson']['id'] ?? '');
    $meta = $payload['meta'];
    $answers = [];

    $exercises = $payload['lesson']['exercises'] ?? null;
    if (is_array($exercises)) {
        foreach ($exercises as $exercise) {
            if (!is_array($exercise) || ($exercise['done'] ?? false) !== true) {
                continue;
            }

            $skill = (string) ($exercise['skill'] ?? '');
            if ($skill !== '') {
                $answers[] = ['skill' => $skill, 'correct' => true];
            }
        }
    }

    $test = $payload['lesson']['test'] ?? null;
    if (is_array($test) && (string) ($test['id'] ?? '') !== '') {
        $testId = (string) $test['id'];
        $attempt = one(
            'SELECT answers FROM ' . t('learn_test_attempt')
                . ' WHERE user_id = ? AND test_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$userId, $testId]
        );

        if ($attempt !== null) {
            $decoded = json_decode((string) $attempt['answers'], true);
            $skills = [];
            foreach (all(
                'SELECT id, skill FROM ' . t('learn_test_question') . ' WHERE test_id = ? ORDER BY sort, id LIMIT 100',
                [$testId]
            ) as $question) {
                $skills[(string) $question['id']] = (string) $question['skill'];
            }

            if (is_array($decoded)) {
                foreach ($decoded as $answer) {
                    if (!is_array($answer)) {
                        continue;
                    }

                    $skill = $skills[(string) ($answer['questionId'] ?? '')] ?? '';
                    if ($skill !== '') {
                        $answers[] = ['skill' => $skill, 'correct' => ($answer['correct'] ?? false) === true];
                    }
                }
            }
        }
    }

    $testScore = $meta['completed_test_score'];
    $testScore = is_int($testScore) ? $testScore : null;
    $moduleCompleted = ($meta['module_completed'] ?? false) === true;

    $exerciseXp = (int) value(
        'SELECT COALESCE(SUM(attempt.xp_awarded), 0) FROM ' . t('learn_attempt') . ' AS attempt'
            . ' JOIN ' . t('learn_exercise') . ' AS exercise ON exercise.id = attempt.exercise_id'
            . ' WHERE attempt.user_id = ? AND exercise.lesson_id = ? LIMIT 1',
        [$userId, $lessonId]
    );

    $xp = LEARN_XP_RULES['LESSON_COMPLETE'] + LEARN_XP_RULES['TEST_COMPLETE'] + $exerciseXp;
    if ($testScore === 100) {
        $xp += LEARN_XP_RULES['PERFECT_TEST_BONUS'];
    }
    if ($moduleCompleted) {
        $xp += LEARN_XP_RULES['MODULE_COMPLETE'];
    }

    $streak = one(
        'SELECT current_days FROM ' . t('learn_streak') . ' WHERE user_id = ? LIMIT 1',
        [$userId]
    );

    return learnAward([
        'xp' => $xp,
        'exercise_right' => (int) ($meta['done_count'] ?? 0),
        'exercise_total' => (int) ($meta['total_exercises'] ?? 0),
        'test_score' => $testScore,
        'module_completed' => $moduleCompleted,
        'streak_days' => $streak === null ? 0 : (int) $streak['current_days'],
        'skills' => learnTallySkills($answers),
    ]);
}
