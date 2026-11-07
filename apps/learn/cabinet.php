<?php

declare(strict_types=1);

// Кабинет ученика: регистрация, онбординг, тест на уровень, dashboard и прогресс.
// Каждый POST начинается с csrfCheck(), а значения уходят в базу параметрами.

/**
 * Экран регистрации ученика: почта, имя, пароль. noindex — личный шаг, не для поиска.
 *
 * @param array<string, string> $params
 */
function learnRegisterPage(array $params = []): void
{
    $user = currentUser();
    if ($user !== null && $user['blocked_at'] === null) {
        redirect('/dashboard');
    }

    render('learn/register', [
        'errors' => [],
        'form' => ['email' => '', 'name' => ''],
    ], [
        'title' => 'Регистрация ученика',
        'description' => 'Создать аккаунт ученика и начать обучение.',
        'noindex' => true,
    ]);
}

/**
 * Регистрация ученика: роль `student`, мгновенный вход через loginUser() и PRG на
 * онбординг. Гард попыток по IP — не больше LIMIT_PER_IP за WINDOW минут.
 *
 * @param array<string, string> $params
 */
function learnRegisterAction(array $params = []): void
{
    csrfCheck();

    $email = mb_strtolower(trim(postString('email')));
    $name = trim(postString('name'));
    $password = postString('password');
    $ip = clientIp();
    $errors = learnValidateRegistration(['email' => $email, 'name' => $name, 'password' => $password]);

    if ($errors === [] && learnRegistrationBlocked($ip)) {
        $errors['email'] = 'Слишком много попыток регистрации с этого адреса — подождите час.';
    }

    if ($errors === [] && one('SELECT id FROM ' . t('user') . ' WHERE email = ? LIMIT 1', [$email]) !== null) {
        $errors['email'] = 'Такая почта уже зарегистрирована — войдите или восстановите пароль.';
    }

    if ($errors !== []) {
        render('learn/register', [
            'errors' => $errors,
            'form' => ['email' => $email, 'name' => $name],
        ], [
            'title' => 'Регистрация ученика',
            'description' => 'Создать аккаунт ученика и начать обучение.',
            'noindex' => true,
        ]);

        return;
    }

    $id = newId();
    insert('user', [
        'id' => $id,
        'email' => $email,
        'password_hash' => hashPassword($password),
        'name' => $name,
        'role' => 'student',
        'session_version' => 0,
        'created_at' => utcStamp(nowUtc()),
    ]);
    logInfo('learn.student_registered', ['userId' => $id]);

    $row = one('SELECT id, email, name, role, session_version FROM ' . t('user') . ' WHERE id = ? LIMIT 1', [$id]);
    if ($row !== null) {
        loginUser($row);
    }

    learnRecordRegistrationAttempt($email, $ip);

    redirectWithNotice('/onboarding', 'learn_registered');
}

/**
 * Экран онбординга: три вопроса с вариантами ответа из констант. noindex — личный шаг.
 *
 * @param array<string, string> $params
 */
function learnOnboardingPage(array $params = []): void
{
    learnUser();

    $notice = noticeText('learn_registered', CORE_NOTICE_TEXT);

    render('learn/onboarding', [
        'selfOptions' => LEARN_ONBOARDING_SELF,
        'goalOptions' => LEARN_ONBOARDING_GOALS,
        'minuteOptions' => LEARN_ONBOARDING_MINUTES,
        'notice' => $notice === null ? '' : (string) $notice,
        'errors' => [],
        'form' => ['self_assessment' => '', 'goal' => '', 'daily_minutes' => ''],
    ], [
        'title' => 'Онбординг',
        'description' => 'Расскажите, как вы учили английский, чтобы подобрать уровень.',
        'noindex' => true,
    ]);
}

/**
 * Сохранение ответов онбординга в профиль и PRG на тест на уровень.
 *
 * @param array<string, string> $params
 */
function learnOnboardingAction(array $params = []): void
{
    csrfCheck();
    $user = learnUser();
    $userId = (string) $user['id'];

    $errors = learnValidateOnboarding([
        'self_assessment' => postString('self_assessment'),
        'goal' => postString('goal'),
        'daily_minutes' => postString('daily_minutes'),
    ]);

    if ($errors !== []) {
        render('learn/onboarding', [
            'selfOptions' => LEARN_ONBOARDING_SELF,
            'goalOptions' => LEARN_ONBOARDING_GOALS,
            'minuteOptions' => LEARN_ONBOARDING_MINUTES,
            'notice' => '',
            'errors' => $errors,
            'form' => [
                'self_assessment' => postString('self_assessment'),
                'goal' => postString('goal'),
                'daily_minutes' => postString('daily_minutes'),
            ],
        ], [
            'title' => 'Онбординг',
            'description' => 'Расскажите, как вы учили английский, чтобы подобрать уровень.',
            'noindex' => true,
        ]);

        return;
    }

    learnSaveProfile($userId, [
        'onboarding_done' => 1,
        'self_assessment' => postString('self_assessment'),
        'goal' => postString('goal'),
        'daily_minutes' => (int) postString('daily_minutes'),
    ]);

    redirectWithNotice('/placement-test', 'learn_onboarded');
}

/**
 * Экран теста на уровень: GET печатает вопросы, после прохождения показывает результат.
 *
 * @param array<string, string> $params
 */
function learnPlacementPage(array $params = []): void
{
    $user = learnUser();
    $userId = (string) $user['id'];

    $test = one(
        'SELECT id, title, pass_score FROM ' . t('learn_test') . ' WHERE kind = ? AND status = ? LIMIT 1',
        ['PLACEMENT', 'PUBLISHED']
    );

    if ($test === null) {
        notFound();
    }

    $testId = (string) $test['id'];
    $questions = all(
        'SELECT id, skill, text, options, correct_option_index, explanation FROM ' . t('learn_test_question')
            . ' WHERE test_id = ? ORDER BY sort, id LIMIT 100',
        [$testId]
    );

    foreach ($questions as &$question) {
        $question['options'] = is_string($question['options'] ?? null)
            ? json_decode((string) $question['options'], true)
            : ($question['options'] ?? []);
    }
    unset($question);

    $noticeKey = queryString('notice');
    $notice = $noticeKey === 'learn_placed'
        ? noticeText('learn_placed', CORE_NOTICE_TEXT)
        : (noticeText('learn_onboarded', CORE_NOTICE_TEXT) ?? '');

    $result = null;
    if ($notice !== null && (string) $notice !== '') {
        $attempt = one(
            'SELECT score, vocabulary_score, grammar_score, reading_score, listening_score,'
                . ' recommended_level_code, recommended_start_module_id, answers, created_at'
                . ' FROM ' . t('learn_test_attempt') . ' WHERE user_id = ? AND test_id = ?'
                . ' ORDER BY created_at DESC, id DESC LIMIT 1',
            [$userId, $testId]
        );
        if ($attempt !== null) {
            $levelRow = one(
                'SELECT code, title FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1',
                [(string) ($attempt['recommended_level_code'] ?? '')]
            );
            $courseTitle = '';
            $startModuleIdRaw = (string) ($attempt['recommended_start_module_id'] ?? '');
            if ($startModuleIdRaw !== '') {
                $moduleRow = one('SELECT course_id FROM ' . t('learn_module') . ' WHERE id = ? LIMIT 1', [$startModuleIdRaw]);
                if ($moduleRow !== null) {
                    $courseRow = one('SELECT title FROM ' . t('learn_course') . ' WHERE id = ? LIMIT 1', [(string) $moduleRow['course_id']]);
                    if ($courseRow !== null) {
                        $courseTitle = (string) $courseRow['title'];
                    }
                }
            }
            $rawAnswers = is_string($attempt['answers'] ?? null) ? json_decode((string) $attempt['answers'], true) : [];
            if (!is_array($rawAnswers)) {
                $rawAnswers = [];
            }
            $selectedByQuestion = [];
            foreach ($rawAnswers as $answer) {
                if (is_array($answer)) {
                    $selectedByQuestion[(string) ($answer['question_id'] ?? '')] = (int) ($answer['option_index'] ?? -1);
                }
            }

            $resultQuestions = [];
            foreach ($questions as $q) {
                $qId = (string) $q['id'];
                $options = is_string($q['options'] ?? null) ? json_decode((string) $q['options'], true) : ($q['options'] ?? []);
                if (!is_array($options)) {
                    $options = [];
                }
                $correctIndex = (int) ($q['correct_option_index'] ?? -1);
                $selectedIndex = $selectedByQuestion[$qId] ?? -1;
                $resultQuestions[] = [
                    'id' => $qId,
                    'skill' => (string) ($q['skill'] ?? ''),
                    'text' => (string) ($q['text'] ?? ''),
                    'options' => array_values($options),
                    'correct_index' => $correctIndex,
                    'selected_index' => $selectedIndex,
                    'explanation' => (string) ($q['explanation'] ?? ''),
                    'correct' => $selectedIndex === $correctIndex,
                ];
            }

            $result = [
                'score' => (int) $attempt['score'],
                'vocabulary_score' => $attempt['vocabulary_score'] === null ? null : (int) $attempt['vocabulary_score'],
                'grammar_score' => $attempt['grammar_score'] === null ? null : (int) $attempt['grammar_score'],
                'reading_score' => $attempt['reading_score'] === null ? null : (int) $attempt['reading_score'],
                'listening_score' => $attempt['listening_score'] === null ? null : (int) $attempt['listening_score'],
                'level_code' => (string) ($attempt['recommended_level_code'] ?? ''),
                'level_title' => $levelRow === null ? '' : (string) $levelRow['title'],
                'start_module_id' => (string) ($attempt['recommended_start_module_id'] ?? ''),
                'course_title' => $courseTitle,
                'questions' => $resultQuestions,
                'created_at' => (string) $attempt['created_at'],
            ];
        }
    }

    render('learn/placement', [
        'test' => ['id' => $testId, 'title' => (string) $test['title'], 'pass_score' => (int) $test['pass_score']],
        'questions' => $questions,
        'notice' => $notice === null ? '' : (string) $notice,
        'result' => $result,
    ], [
        'title' => 'Тест на уровень',
        'description' => 'Проверьте знания, чтобы школа подобрала начальный курс.',
        'noindex' => true,
    ]);
}

/**
 * Сдача теста на уровень: попытка с разбивкой по навыкам, рекомендованный уровень и
 * стартовый модуль, профиль и progress.current_level_id. Фолбэк — первый опубликованный
 * курс школы, если на recommanded уровне нет курса.
 *
 * @param array<string, string> $params
 */
function learnPlacementAction(array $params = []): void
{
    csrfCheck();
    $user = learnUser();
    $userId = (string) $user['id'];

    $test = one(
        'SELECT id, pass_score FROM ' . t('learn_test') . ' WHERE kind = ? AND status = ? LIMIT 1',
        ['PLACEMENT', 'PUBLISHED']
    );

    if ($test === null) {
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
    $answerList = is_array($rawAnswers) ? $rawAnswers : [];

    $answers = [];
    foreach ($answerList as $questionId => $optionIndex) {
        $answers[] = [
            'question_id' => (string) $questionId,
            'option_index' => is_numeric($optionIndex) ? (int) $optionIndex : -1,
        ];
    }

    $questionIds = [];
    foreach ($questions as $q) {
        $questionIds[] = (string) $q['id'];
    }

    $scores = learnPlacementScores($questions, $answers, $questionIds);
    $levelCode = learnDecideLevel($scores['score']);

    $levelRow = one('SELECT id FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1', [$levelCode]);
    $levelId = $levelRow === null ? null : (string) $levelRow['id'];

    // Стартовый модуль: первый опубликованный модуль курса recommanded уровня,
    // иначе — первый опубликованный курс школы (фолбэк MVP).
    $startModuleId = null;
    $startCourseId = null;

    if ($levelId !== null) {
        $course = learnCourseForLevel($levelId);
        if ($course !== null) {
            $startCourseId = (string) $course['id'];
            $startModuleRow = one(
                'SELECT id FROM ' . t('learn_module') . ' WHERE course_id = ? AND status = ? ORDER BY sort, id LIMIT 1',
                [$startCourseId, 'PUBLISHED']
            );
            if ($startModuleRow !== null) {
                $startModuleId = (string) $startModuleRow['id'];
            }
        }
    }

    if ($startModuleId === null) {
        $fallback = learnCourseFallback();
        if ($fallback !== null) {
            $startCourseId = (string) $fallback['id'];
            $startModuleRow = one(
                'SELECT id FROM ' . t('learn_module') . ' WHERE course_id = ? AND status = ? ORDER BY sort, id LIMIT 1',
                [$startCourseId, 'PUBLISHED']
            );
            if ($startModuleRow !== null) {
                $startModuleId = (string) $startModuleRow['id'];
            }
        }
    }

    insert('learn_test_attempt', [
        'id' => newId(),
        'user_id' => $userId,
        'test_id' => $testId,
        'score' => $scores['score'],
        'vocabulary_score' => $scores['vocabulary_score'],
        'grammar_score' => $scores['grammar_score'],
        'reading_score' => $scores['reading_score'],
        'listening_score' => $scores['listening_score'],
        'passed' => $scores['score'] >= (int) $test['pass_score'] ? 1 : 0,
        'recommended_level_code' => $levelCode,
        'recommended_start_module_id' => $startModuleId,
        'answers' => learnJsonText($answers),
        'created_at' => utcStamp(nowUtc()),
    ]);

    learnSaveProfile($userId, [
        'placement_done' => 1,
        'start_level_id' => $levelId,
    ]);

    $progress = learnProgressRow($userId);
    if ($progress['current_level_id'] === null && $levelId !== null) {
        run(
            'UPDATE ' . t('learn_progress') . ' SET current_level_id = ? WHERE user_id = ?',
            [$levelId, $userId]
        );
    }

    redirectWithNotice('/placement-test', 'learn_placed');
}

/**
 * Кабинет ученика: гард готовности — без онбординга ведёт на /onboarding, без placement
 * — на /placement-test. Приветствие, серия, слабый навык, статистика, текущий модуль.
 *
 * @param array<string, string> $params
 */
function learnDashboardPage(array $params = []): void
{
    $user = learnUser();
    $userId = (string) $user['id'];

    $profile = learnProfileRead($userId);

    if ($profile === null || (int) ($profile['onboarding_done'] ?? 0) !== 1) {
        redirect('/onboarding');
    }

    if ((int) ($profile['placement_done'] ?? 0) !== 1) {
        redirect('/placement-test');
    }

    $summary = learnCabinetSummary($userId);

    render('learn/dashboard', [
        'summary' => $summary,
    ], [
        'title' => 'Кабинет',
        'description' => 'Ваш прогресс и текущий модуль.',
        'noindex' => true,
    ]);
}

/**
 * Экран прогресса: XP, серия, четыре навыка полосами, история тестов LIMIT 10.
 *
 * @param array<string, string> $params
 */
function learnProgressPage(array $params = []): void
{
    $user = learnUser();
    $userId = (string) $user['id'];

    $summary = learnCabinetSummary($userId);
    $skillStats = learnSkillStats($userId);
    $testHistory = learnTestHistory($userId);

    render('learn/progress', [
        'summary' => $summary,
        'skillStats' => $skillStats,
        'testHistory' => $testHistory,
    ], [
        'title' => 'Прогресс',
        'description' => 'XP, серия, навыки и история попыток тестов.',
        'noindex' => true,
    ]);
}
