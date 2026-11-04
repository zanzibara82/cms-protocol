<?php

declare(strict_types=1);

// Чтение ученического контура — порт getUserCourseTree/getModulePayload/getLessonPayload.
// Курс выбирается по текущему уровню, а без него — самый нижний опубликованный: после
// онбординга и теста на уровень progress.current_level_id уже заполнен, а до них работает
// фолбэк MVP. Замки уроков сквозные по курсу: первый урок открыт, следующий ждёт закрытия
// предыдущего, поэтому уроки следующих модулей закрыты до завершения текущего.

/**
 * Строка прогресса без записи в базу: чтение экрана не должно создавать данные.
 *
 * @return array{current_level_id: string|null, completed_lessons: string, completed_modules: string, current_module_order: int, current_lesson_order: int}
 */
function learnProgressRead(string $userId): array
{
    $row = one(
        'SELECT current_level_id, completed_lessons, completed_modules, current_module_order, current_lesson_order'
            . ' FROM ' . t('learn_progress') . ' WHERE user_id = ? LIMIT 1',
        [$userId]
    );

    if ($row === null) {
        return [
            'current_level_id' => null,
            'completed_lessons' => '[]',
            'completed_modules' => '[]',
            'current_module_order' => 1,
            'current_lesson_order' => 1,
        ];
    }

    return [
        'current_level_id' => $row['current_level_id'] === null ? null : (string) $row['current_level_id'],
        'completed_lessons' => (string) $row['completed_lessons'],
        'completed_modules' => (string) $row['completed_modules'],
        'current_module_order' => (int) $row['current_module_order'],
        'current_lesson_order' => (int) $row['current_lesson_order'],
    ];
}

/**
 * Закрытые уроки множеством: проверка идёт по идентификатору, а не по позиции в списке.
 *
 * @return array<string, true>
 */
function learnCompletedSet(string $raw): array
{
    $set = [];
    foreach (learnJsonList($raw) as $entry) {
        $id = (string) ($entry['lessonId'] ?? '');
        if ($id !== '') {
            $set[$id] = true;
        }
    }

    return $set;
}

/**
 * Закрытые модули множеством: бонус модуля не должен начисляться дважды.
 *
 * @return array<string, true>
 */
function learnCompletedModuleSet(string $raw): array
{
    $set = [];
    foreach (learnJsonList($raw) as $entry) {
        $id = (string) ($entry['moduleId'] ?? '');
        if ($id !== '') {
            $set[$id] = true;
        }
    }

    return $set;
}

/** @return array{id: string, code: string, title: string, sort: int}|null */
function learnLevelById(string $id): ?array
{
    $row = one(
        'SELECT id, code, title, sort FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    if ($row === null) {
        return null;
    }

    return [
        'id' => (string) $row['id'],
        'code' => (string) $row['code'],
        'title' => (string) $row['title'],
        'sort' => (int) $row['sort'],
    ];
}

/** @return array{id: string, level_id: string, title: string, description: string, sort: int}|null */
function learnCourseForLevel(string $levelId): ?array
{
    $row = one(
        'SELECT id, level_id, title, description, sort FROM ' . t('learn_course')
            . ' WHERE level_id = ? AND status = ? ORDER BY sort, id LIMIT 1',
        [$levelId, 'PUBLISHED']
    );

    return $row === null ? null : learnCourseRow($row);
}

/** @return array{id: string, level_id: string, title: string, description: string, sort: int}|null */
function learnCourseFallback(): ?array
{
    $row = one(
        'SELECT course.id, course.level_id, course.title, course.description, course.sort'
            . ' FROM ' . t('learn_course') . ' AS course'
            . ' JOIN ' . t('learn_level') . ' AS level ON level.id = course.level_id'
            . ' WHERE course.status = ? ORDER BY level.sort, course.sort, course.id LIMIT 1',
        ['PUBLISHED']
    );

    return $row === null ? null : learnCourseRow($row);
}

/**
 * @param array<string, mixed> $row
 * @return array{id: string, level_id: string, title: string, description: string, sort: int}
 */
function learnCourseRow(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'level_id' => (string) $row['level_id'],
        'title' => (string) $row['title'],
        'description' => (string) $row['description'],
        'sort' => (int) $row['sort'],
    ];
}

/**
 * Непройденный открытый урок модуля, в котором есть упражнения данного навыка.
 * Нужно для карточки фокуса кабинета: ссылка на урок, где можно подтянуть слабое.
 *
 * @return array{lesson: array{id: string, slug: string, title: string, sort: int, unlocked: bool, completed: bool, skill: string}, module: array{id: string, title: string, sort: int, lesson_count: int, completed_count: int, percent: int}}|null
 */
function learnLessonWithSkill(string $userId, string $moduleId, string $skill): ?array
{
    $moduleRow = one(
        'SELECT id, course_id, title, sort FROM ' . t('learn_module') . ' WHERE id = ? LIMIT 1',
        [$moduleId]
    );

    if ($moduleRow === null) {
        return null;
    }

    $courseId = (string) $moduleRow['course_id'];
    $progress = learnProgressRead($userId);
    $completed = learnCompletedSet($progress['completed_lessons']);
    $modules = learnModulesWithLocks($courseId, $completed);

    $targetModule = null;
    foreach ($modules as $module) {
        if ($module['id'] === $moduleId) {
            $targetModule = $module;
            break;
        }
    }

    if ($targetModule === null) {
        return null;
    }

    $targetModuleShort = [
        'id' => (string) $targetModule['id'],
        'title' => (string) $targetModule['title'],
        'sort' => (int) $targetModule['sort'],
        'lesson_count' => (int) ($targetModule['lesson_count'] ?? 0),
        'completed_count' => (int) ($targetModule['completed_count'] ?? 0),
        'percent' => (int) ($targetModule['percent'] ?? 0),
    ];

    foreach ($targetModule['lessons'] as $lesson) {
        if (($lesson['unlocked'] ?? false) !== true || ($lesson['completed'] ?? false) === true) {
            continue;
        }

        $exercises = is_array($lesson['exercises'] ?? null) ? $lesson['exercises'] : [];
        /** @phpstan-ignore-next-line */
        foreach ($exercises as $exercise) {
            /** @phpstan-ignore-next-line */
            if (!is_array($exercise)) {
                continue;
            }

            /** @phpstan-ignore-next-line */
            if ((string) ($exercise['skill'] ?? '') === $skill) {
                return [
                    'lesson' => [
                        'id' => (string) ($lesson['id'] ?? ''),
                        'slug' => (string) ($lesson['slug'] ?? ''),
                        'title' => (string) ($lesson['title'] ?? ''),
                        'sort' => (int) ($lesson['sort'] ?? 0),
                        'unlocked' => (bool) ($lesson['unlocked'] ?? false),
                        'completed' => (bool) ($lesson['completed'] ?? false),
                        'skill' => $skill,
                    ],
                    'module' => $targetModuleShort,
                ];
            }
        }
    }

    return null;
}

/**
 * Модули курса с уроками и сквозными замками. `$prevDone` переживает границу модуля:
 * первый урок следующего модуля открывается только после последнего урока предыдущего.
 *
 * @param array<string, true> $completed
 * @return list<array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>}>
 */
function learnModulesWithLocks(string $courseId, array $completed): array
{
    $modules = all(
        'SELECT id, title, description, sort FROM ' . t('learn_module')
            . ' WHERE course_id = ? AND status = ? ORDER BY sort, id LIMIT 200',
        [$courseId, 'PUBLISHED']
    );

    $out = [];
    $prevDone = true;

    foreach ($modules as $module) {
        $lessons = all(
            'SELECT id, slug, title, sort FROM ' . t('learn_lesson')
                . ' WHERE module_id = ? AND status = ? ORDER BY sort, id LIMIT 500',
            [(string) $module['id'], 'PUBLISHED']
        );

        $lessonRows = [];
        foreach ($lessons as $lesson) {
            $done = isset($completed[(string) $lesson['id']]);
            $unlocked = $prevDone || $done;

            $lessonRows[] = [
                'id' => (string) $lesson['id'],
                'slug' => (string) $lesson['slug'],
                'title' => (string) $lesson['title'],
                'sort' => (int) $lesson['sort'],
                'completed' => $done,
                'unlocked' => $unlocked,
            ];

            $prevDone = $done;
        }

        $out[] = [
            'id' => (string) $module['id'],
            'title' => (string) $module['title'],
            'description' => (string) $module['description'],
            'sort' => (int) $module['sort'],
            'lessons' => $lessonRows,
        ];
    }

    return $out;
}

/**
 * @param array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>} $module
 * @return array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>, lesson_count: int, completed_count: int, percent: int}
 */
function learnModuleWithStats(array $module): array
{
    $lessonCount = count($module['lessons']);
    $completedCount = 0;

    foreach ($module['lessons'] as $lesson) {
        if ($lesson['completed']) {
            $completedCount++;
        }
    }

    $module['lesson_count'] = $lessonCount;
    $module['completed_count'] = $completedCount;
    $module['percent'] = $lessonCount > 0 ? (int) round($completedCount * 100 / $lessonCount) : 0;

    return $module;
}

/**
 * @return array{
 *     level: array{id: string, code: string, title: string, sort: int}|null,
 *     course: array{id: string, level_id: string, title: string, description: string, sort: int}|null,
 *     modules: list<array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>, lesson_count: int, completed_count: int, percent: int}>
 * }
 */
function learnCourseTree(string $userId): array
{
    $progress = learnProgressRead($userId);
    $completed = learnCompletedSet($progress['completed_lessons']);

    $level = null;
    $course = null;

    if ($progress['current_level_id'] !== null) {
        $level = learnLevelById($progress['current_level_id']);

        if ($level !== null) {
            $course = learnCourseForLevel($level['id']);
        }
    }

    if ($course === null) {
        $course = learnCourseFallback();

        if ($course !== null) {
            $level = learnLevelById($course['level_id']);
        }
    }

    if ($course === null) {
        return ['level' => $level, 'course' => null, 'modules' => []];
    }

    $modules = [];
    foreach (learnModulesWithLocks($course['id'], $completed) as $module) {
        $modules[] = learnModuleWithStats($module);
    }

    return ['level' => $level, 'course' => $course, 'modules' => $modules];
}

/**
 * Экран модуля: шапка модуля и его уроки с галочками и замками.
 *
 * @return array{
 *     module: array{id: string, title: string, description: string, sort: int, lessons: list<array{id: string, slug: string, title: string, sort: int, completed: bool, unlocked: bool}>, lesson_count: int, completed_count: int, percent: int},
 *     course: array{id: string, level_id: string, title: string, description: string, sort: int},
 *     level: array{id: string, code: string, title: string, sort: int}
 * }|null
 */
function learnModulePayload(string $userId, string $moduleId): ?array
{
    $row = one(
        'SELECT module.id, module.course_id, module.title, module.description, module.sort,'
            . ' course.title AS course_title, course.description AS course_description, course.sort AS course_sort,'
            . ' level.id AS level_id, level.code AS level_code, level.title AS level_title, level.sort AS level_sort'
            . ' FROM ' . t('learn_module') . ' AS module'
            . ' JOIN ' . t('learn_course') . ' AS course ON course.id = module.course_id'
            . ' JOIN ' . t('learn_level') . ' AS level ON level.id = course.level_id'
            . ' WHERE module.id = ? AND module.status = ? AND course.status = ? LIMIT 1',
        [$moduleId, 'PUBLISHED', 'PUBLISHED']
    );

    if ($row === null) {
        return null;
    }

    $progress = learnProgressRead($userId);
    $completed = learnCompletedSet($progress['completed_lessons']);
    $modules = learnModulesWithLocks((string) $row['course_id'], $completed);

    foreach ($modules as $module) {
        if ($module['id'] !== $moduleId) {
            continue;
        }

        return [
            'module' => learnModuleWithStats($module),
            'course' => [
                'id' => (string) $row['course_id'],
                'level_id' => (string) $row['level_id'],
                'title' => (string) $row['course_title'],
                'description' => (string) $row['course_description'],
                'sort' => (int) $row['course_sort'],
            ],
            'level' => [
                'id' => (string) $row['level_id'],
                'code' => (string) $row['level_code'],
                'title' => (string) $row['level_title'],
                'sort' => (int) $row['level_sort'],
            ],
        ];
    }

    return null;
}

/**
 * Экран урока: карточка, цели, слова, грамматика, подобранные упражнения и мини-тест.
 * Правильные ответы и объяснения в публичную форму не попадают — их отдаёт только
 * проверка попытки после ответа ученика.
 *
 * @return array{
 *     lesson: array{
 *         id: string, slug: string, title: string, description: string, sort: int,
 *         objectives: list<array{id: string, text: string, sort: int}>,
 *         vocabulary: list<array{id: string, word: string, translation: string, ipa: string, example_en: string, example_ru: string, sort: int}>,
 *         grammar: list<array{id: string, title: string, summary: string, details: string, examples: list<array{en: string, ru: string}>, sort: int}>,
 *         exercises: list<array<string, mixed>>,
 *         exercise_bank_size: int,
 *         test: array{id: string, title: string, pass_score: int, question_count: int}|null,
 *         test_questions: list<array{id: string, skill: string, text: string, options: list<string>, audio_url: string|null, sort: int}>
 *     },
 *     meta: array{
 *         unlocked: bool, completed: bool,
 *         module: array{id: string, title: string, sort: int, lesson_count: int},
 *         module_all_done: bool, module_completed: bool, completed_test_score: int|null,
 *         level_code: string, course_title: string,
 *         next_lesson: array{id: string, slug: string, title: string, sort: int}|null,
 *         done_count: int, total_exercises: int
 *     }
 * }|null
 */
function learnLessonPayload(string $userId, string $slugOrId): ?array
{
    $row = one(
        'SELECT lesson.id, lesson.slug, lesson.title, lesson.description, lesson.sort,'
            . ' module.id AS module_id, module.title AS module_title, module.sort AS module_sort,'
            . ' module.course_id, course.title AS course_title, level.code AS level_code'
            . ' FROM ' . t('learn_lesson') . ' AS lesson'
            . ' JOIN ' . t('learn_module') . ' AS module ON module.id = lesson.module_id'
            . ' JOIN ' . t('learn_course') . ' AS course ON course.id = module.course_id'
            . ' JOIN ' . t('learn_level') . ' AS level ON level.id = course.level_id'
            . ' WHERE (lesson.slug = ? OR lesson.id = ?)'
            . ' AND lesson.status = ? AND module.status = ? AND course.status = ? LIMIT 1',
        [$slugOrId, $slugOrId, 'PUBLISHED', 'PUBLISHED', 'PUBLISHED']
    );

    if ($row === null) {
        return null;
    }

    $lessonId = (string) $row['id'];
    $courseId = (string) $row['course_id'];

    $progress = learnProgressRead($userId);
    $completed = learnCompletedSet($progress['completed_lessons']);
    $modules = learnModulesWithLocks($courseId, $completed);

    $currentModule = null;
    $currentLesson = null;
    $nextLesson = null;

    foreach ($modules as $module) {
        if ($module['id'] !== (string) $row['module_id']) {
            continue;
        }

        $currentModule = $module;
        foreach ($module['lessons'] as $index => $lesson) {
            if ($lesson['id'] !== $lessonId) {
                continue;
            }

            $currentLesson = $lesson;
            $nextLesson = $module['lessons'][$index + 1] ?? null;
            break 2;
        }
    }

    if ($currentModule === null || $currentLesson === null) {
        return null;
    }

    // Урок закрывает модуль, когда все его уроки уже закрыты, а текущий — последний
    // незакрытый. Считаем по списку, а не по позиции: так правило переживает правку порядка.
    $moduleAllDone = true;
    foreach ($currentModule['lessons'] as $lesson) {
        if ($lesson['id'] !== $lessonId && $lesson['completed'] !== true) {
            $moduleAllDone = false;
            break;
        }
    }

    $completedModules = learnCompletedModuleSet($progress['completed_modules']);
    $completedTestScore = null;
    foreach (learnJsonList($progress['completed_lessons']) as $entry) {
        if ((string) ($entry['lessonId'] ?? '') === $lessonId) {
            $completedTestScore = (int) ($entry['testScore'] ?? 0);
            break;
        }
    }

    $objectives = [];
    foreach (all(
        'SELECT id, text, sort FROM ' . t('learn_objective') . ' WHERE lesson_id = ? ORDER BY sort, id LIMIT 100',
        [$lessonId]
    ) as $objective) {
        $objectives[] = [
            'id' => (string) $objective['id'],
            'text' => (string) $objective['text'],
            'sort' => (int) $objective['sort'],
        ];
    }

    $vocabulary = [];
    foreach (all(
        'SELECT id, word, translation, ipa, example_en, example_ru, sort FROM ' . t('learn_vocab')
            . ' WHERE lesson_id = ? ORDER BY sort, id LIMIT 300',
        [$lessonId]
    ) as $word) {
        $vocabulary[] = [
            'id' => (string) $word['id'],
            'word' => (string) $word['word'],
            'translation' => (string) $word['translation'],
            'ipa' => (string) $word['ipa'],
            'example_en' => (string) $word['example_en'],
            'example_ru' => (string) $word['example_ru'],
            'sort' => (int) $word['sort'],
        ];
    }

    $grammar = [];
    foreach (all(
        'SELECT id, title, summary, details, examples, sort FROM ' . t('learn_grammar')
            . ' WHERE lesson_id = ? ORDER BY sort, id LIMIT 100',
        [$lessonId]
    ) as $rule) {
        $grammar[] = [
            'id' => (string) $rule['id'],
            'title' => (string) $rule['title'],
            'summary' => (string) $rule['summary'],
            'details' => (string) ($rule['details'] ?? ''),
            'examples' => learnGrammarExamples($rule['examples'] ?? null),
            'sort' => (int) $rule['sort'],
        ];
    }

    $exerciseRows = all(
        'SELECT id, lesson_id, type, question, options, correct_answer, explanation, difficulty,'
            . ' skill, xp_reward, audio_url, sort FROM ' . t('learn_exercise')
            . ' WHERE lesson_id = ? ORDER BY sort, id LIMIT 300',
        [$lessonId]
    );

    $attempted = [];
    $doneIds = [];
    if ($exerciseRows !== []) {
        $exerciseIds = [];
        foreach ($exerciseRows as $exercise) {
            $exerciseIds[] = (string) $exercise['id'];
        }

        $attempts = all(
            'SELECT exercise_id, is_correct FROM ' . t('learn_attempt')
                . ' WHERE user_id = ? AND exercise_id IN (' . inPlaceholders(count($exerciseIds)) . ')'
                . ' ORDER BY created_at, id LIMIT 1000',
            array_merge([$userId], $exerciseIds)
        );

        foreach ($attempts as $attempt) {
            $attempted[(string) $attempt['exercise_id']] = true;
            if ((int) $attempt['is_correct'] === 1) {
                $doneIds[(string) $attempt['exercise_id']] = true;
            }
        }
    }

    $picked = learnPickExercises(
        $exerciseRows,
        array_keys($attempted),
        LEARN_EXERCISES_PER_VISIT,
        $userId . ':' . $lessonId
    );

    $exercises = [];
    foreach ($picked as $exercise) {
        $public = learnPublicExercise($exercise);
        $public['done'] = isset($doneIds[(string) $exercise['id']]);
        $exercises[] = $public;
    }

    $test = one(
        'SELECT id, title, pass_score FROM ' . t('learn_test')
            . ' WHERE lesson_id = ? AND kind = ? AND status = ? ORDER BY id LIMIT 1',
        [$lessonId, 'LESSON', 'PUBLISHED']
    );

    $questions = [];
    $testOut = null;
    if ($test !== null) {
        $testId = (string) $test['id'];
        foreach (all(
            'SELECT id, skill, text, options, audio_url, sort FROM ' . t('learn_test_question')
                . ' WHERE test_id = ? ORDER BY sort, id LIMIT 100',
            [$testId]
        ) as $question) {
            $questions[] = [
                'id' => (string) $question['id'],
                'skill' => (string) $question['skill'],
                'text' => (string) $question['text'],
                'options' => learnChoiceList($question['options'] ?? null),
                'audio_url' => $question['audio_url'] === null ? null : (string) $question['audio_url'],
                'sort' => (int) $question['sort'],
            ];
        }

        $testOut = [
            'id' => $testId,
            'title' => (string) $test['title'],
            'pass_score' => (int) $test['pass_score'],
            'question_count' => count($questions),
        ];
    }

    $doneCount = 0;
    foreach ($exercises as $exercise) {
        if ($exercise['done'] === true) {
            $doneCount++;
        }
    }

    return [
        'lesson' => [
            'id' => $lessonId,
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'sort' => (int) $row['sort'],
            'objectives' => $objectives,
            'vocabulary' => $vocabulary,
            'grammar' => $grammar,
            'exercises' => $exercises,
            'exercise_bank_size' => count($exerciseRows),
            'test' => $testOut,
            'test_questions' => $questions,
        ],
        'meta' => [
            'unlocked' => $currentLesson['unlocked'],
            'completed' => $currentLesson['completed'],
            'module' => [
                'id' => $currentModule['id'],
                'title' => $currentModule['title'],
                'sort' => $currentModule['sort'],
                'lesson_count' => count($currentModule['lessons']),
            ],
            'module_all_done' => $moduleAllDone,
            'module_completed' => isset($completedModules[$currentModule['id']]),
            'completed_test_score' => $completedTestScore,
            'level_code' => (string) $row['level_code'],
            'course_title' => (string) $row['course_title'],
            'next_lesson' => $nextLesson === null ? null : [
                'id' => $nextLesson['id'],
                'slug' => $nextLesson['slug'],
                'title' => $nextLesson['title'],
                'sort' => $nextLesson['sort'],
            ],
            'done_count' => $doneCount,
            'total_exercises' => count($exercises),
        ],
    ];
}

/**
 * Примеры грамматики из JSON-колонки. Испорченное значение читается как пустой список.
 *
 * @return list<array{en: string, ru: string}>
 */
function learnGrammarExamples(mixed $raw): array
{
    $decoded = learnDecodeJson($raw);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $example) {
        if (!is_array($example)) {
            continue;
        }

        $en = (string) ($example['en'] ?? '');
        if ($en === '') {
            continue;
        }

        $out[] = ['en' => $en, 'ru' => (string) ($example['ru'] ?? '')];
    }

    return $out;
}

/**
 * Варианты выбора из JSON: только скалярные значения, порядок сохраняется.
 *
 * @return list<string>
 */
function learnChoiceList(mixed $raw): array
{
    $decoded = learnDecodeJson($raw);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $value) {
        if (is_scalar($value)) {
            $out[] = (string) $value;
        }
    }

    return $out;
}

/**
 * Шаг, который открывается без параметра: закрытый урок — результат, ни одной попытки —
 * введение, все упражнения пройдены — тест или результат, иначе упражнения.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 */
function learnResumeStep(array $payload): string
{
    if (($payload['meta']['completed'] ?? false) === true) {
        return 'result';
    }

    $done = (int) ($payload['meta']['done_count'] ?? 0);
    $total = (int) ($payload['meta']['total_exercises'] ?? 0);

    if ($done === 0) {
        return 'intro';
    }

    if ($done >= $total) {
        return ($payload['lesson']['test'] ?? null) === null ? 'result' : 'test';
    }

    return 'exercises';
}

/**
 * Допустимые шаги урока: теста нет — шага теста нет.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 * @return list<string>
 */
function learnLessonSteps(array $payload): array
{
    $steps = ['intro', 'vocab', 'grammar', 'exercises'];

    if (($payload['lesson']['test'] ?? null) !== null) {
        $steps[] = 'test';
    }

    $steps[] = 'result';

    return $steps;
}

/**
 * Выбранное упражнение: запрошенное по идентификатору или первое непройденное.
 *
 * @param array{lesson: array<string, mixed>, meta: array<string, mixed>} $payload
 * @return array<string, mixed>|null
 */
function learnCurrentExercise(array $payload, ?string $requestedId = null): ?array
{
    $exercises = $payload['lesson']['exercises'] ?? [];
    if (!is_array($exercises)) {
        return null;
    }

    $rows = [];
    foreach ($exercises as $exercise) {
        if (is_array($exercise)) {
            $rows[] = $exercise;
        }
    }

    if ($rows === []) {
        return null;
    }

    if ($requestedId !== null && $requestedId !== '') {
        foreach ($rows as $exercise) {
            if ((string) ($exercise['id'] ?? '') === $requestedId) {
                return $exercise;
            }
        }
    }

    foreach ($rows as $exercise) {
        if (($exercise['done'] ?? false) !== true) {
            return $exercise;
        }
    }

    return $rows[0];
}
