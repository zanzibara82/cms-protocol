<?php

declare(strict_types=1);

// Наполнение раздела «Курсы» при установке: дерево уровней, курсов, модулей и уроков
// вместе с целями, лексикой, грамматикой, упражнениями и тестами. Файл возвращает
// замыкание, а не объявляет функции: ядро подключает seed.php повторно при каждой
// установке, и повторное объявление функции упало бы.
//
// Записи идемпотентны: курс ищется по уровню и названию, модуль — по курсу и названию,
// урок — по slug. Существующие строки не перезаписываются, чтобы не потерять правки
// контент-редактора; недостающие модули, уроки и тесты добавляются.

/**
 * @param list<array{
 *     level_code: string,
 *     title: string,
 *     description: string,
 *     modules: list<array{
 *         title: string,
 *         description: string,
 *         lessons: list<array<string, mixed>>,
 *         test: list<array<string, mixed>>
 *     }>
 * }> $courses
 */
return static function (array $courses): void {
    tx(static function () use ($courses): void {
        $json = static function (mixed $value): string {
            return (string) json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        };

        /**
         * @param list<array<string, mixed>> $questions
         */
        $insertQuestions = static function (string $testId, array $questions) use ($json): void {
            foreach ($questions as $sort => $question) {
                insert('learn_test_question', [
                    'id' => newId(),
                    'test_id' => $testId,
                    'skill' => (string) ($question['skill'] ?? 'GRAMMAR'),
                    'text' => (string) $question['text'],
                    'options' => $json($question['options']),
                    'correct_option_index' => (int) $question['correct'],
                    'explanation' => (string) ($question['explanation'] ?? ''),
                    'audio_url' => null,
                    'passage_ref' => null,
                    'sort' => $sort + 1,
                ]);
            }
        };

        foreach ($courses as $course) {
            $level = one(
                'SELECT id, sort FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1',
                [$course['level_code']]
            );

            if ($level === null) {
                continue;
            }

            $levelId = (string) $level['id'];
            $courseRow = one(
                'SELECT id FROM ' . t('learn_course') . ' WHERE level_id = ? AND title = ? LIMIT 1',
                [$levelId, $course['title']]
            );

            if ($courseRow === null) {
                $courseId = newId();
                insert('learn_course', [
                    'id' => $courseId,
                    'level_id' => $levelId,
                    'title' => $course['title'],
                    'description' => $course['description'],
                    'status' => 'PUBLISHED',
                    'sort' => (int) $level['sort'] + 1,
                ]);
            } else {
                $courseId = (string) $courseRow['id'];
            }

            foreach ($course['modules'] as $moduleSort => $module) {
                $moduleRow = one(
                    'SELECT id FROM ' . t('learn_module') . ' WHERE course_id = ? AND title = ? LIMIT 1',
                    [$courseId, $module['title']]
                );

                if ($moduleRow === null) {
                    $moduleId = newId();
                    insert('learn_module', [
                        'id' => $moduleId,
                        'course_id' => $courseId,
                        'title' => $module['title'],
                        'description' => $module['description'],
                        'status' => 'PUBLISHED',
                        'sort' => $moduleSort + 1,
                    ]);
                } else {
                    $moduleId = (string) $moduleRow['id'];
                }

                foreach ($module['lessons'] as $lessonSort => $lesson) {
                    $lessonRow = one(
                        'SELECT id FROM ' . t('learn_lesson') . ' WHERE slug = ? LIMIT 1',
                        [(string) $lesson['slug']]
                    );

                    if ($lessonRow !== null) {
                        continue;
                    }

                    $lessonId = newId();
                    insert('learn_lesson', [
                        'id' => $lessonId,
                        'module_id' => $moduleId,
                        'slug' => (string) $lesson['slug'],
                        'title' => (string) $lesson['title'],
                        'description' => (string) $lesson['description'],
                        'status' => 'PUBLISHED',
                        'sort' => $lessonSort + 1,
                    ]);

                    foreach ($lesson['objectives'] as $sort => $objective) {
                        insert('learn_objective', [
                            'id' => newId(),
                            'lesson_id' => $lessonId,
                            'text' => (string) $objective,
                            'sort' => $sort + 1,
                        ]);
                    }

                    foreach ($lesson['vocabulary'] as $sort => $word) {
                        insert('learn_vocab', [
                            'id' => newId(),
                            'lesson_id' => $lessonId,
                            'word' => (string) $word['word'],
                            'translation' => (string) $word['translation'],
                            'ipa' => (string) ($word['ipa'] ?? ''),
                            'example_en' => (string) ($word['example_en'] ?? ''),
                            'example_ru' => (string) ($word['example_ru'] ?? ''),
                            'sort' => $sort + 1,
                        ]);
                    }

                    foreach ($lesson['grammar'] as $sort => $rule) {
                        insert('learn_grammar', [
                            'id' => newId(),
                            'lesson_id' => $lessonId,
                            'title' => (string) $rule['title'],
                            'summary' => (string) $rule['summary'],
                            'details' => (string) ($rule['details'] ?? ''),
                            'examples' => $json($rule['examples']),
                            'sort' => $sort + 1,
                        ]);
                    }

                    foreach ($lesson['exercises'] as $sort => $exercise) {
                        $skill = (string) ($exercise['skill'] ?? '');

                        insert('learn_exercise', [
                            'id' => newId(),
                            'lesson_id' => $lessonId,
                            'type' => (string) $exercise['type'],
                            'question' => (string) $exercise['question'],
                            'options' => $exercise['options'] === null ? null : $json($exercise['options']),
                            'correct_answer' => $json($exercise['correct_answer']),
                            'explanation' => (string) ($exercise['explanation'] ?? ''),
                            'difficulty' => 1,
                            'skill' => $skill === '' ? null : $skill,
                            'xp_reward' => (int) ($exercise['xp_reward'] ?? 5),
                            'audio_url' => null,
                            'sort' => $sort + 1,
                        ]);
                    }

                    $lessonTest = one(
                        'SELECT id FROM ' . t('learn_test') . ' WHERE lesson_id = ? LIMIT 1',
                        [$lessonId]
                    );

                    if ($lessonTest === null) {
                        $testId = newId();
                        insert('learn_test', [
                            'id' => $testId,
                            'kind' => 'LESSON',
                            'lesson_id' => $lessonId,
                            'module_id' => null,
                            'title' => 'Тест: ' . (string) $lesson['title'],
                            'pass_score' => 60,
                            'status' => 'PUBLISHED',
                        ]);
                        $insertQuestions($testId, $lesson['test']);
                    }
                }

                $moduleTest = one(
                    'SELECT id FROM ' . t('learn_test') . ' WHERE module_id = ? AND kind = ? LIMIT 1',
                    [$moduleId, 'MODULE']
                );

                if ($moduleTest === null) {
                    $testId = newId();
                    insert('learn_test', [
                        'id' => $testId,
                        'kind' => 'MODULE',
                        'lesson_id' => null,
                        'module_id' => $moduleId,
                        'title' => 'Итоговый тест модуля ' . (string) $module['title'],
                        'pass_score' => 70,
                        'status' => 'PUBLISHED',
                    ]);
                    $insertQuestions($testId, $module['test']);
                }
            }
        }
    });
};
