<?php

declare(strict_types=1);

// Контроллеры ученического контура: курс, модуль и урок шагами. Экраны только читают
// данные — попытки и сдача теста появятся следующей задачей; здесь важен честный
// контракт «что ученик видит до ответа», без правильных ответов в разметке.

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

    render('learn/lesson', [
        'payload' => $payload,
        'step' => $step,
        'steps' => $steps,
        'exercise' => $exercise,
        'exerciseIndex' => $exerciseIndex,
        'exerciseTotal' => $exerciseTotal,
        'moduleUrl' => '/learn/module/' . rawurlencode((string) $payload['meta']['module']['id']),
    ], [
        'title' => 'Урок: ' . $lessonTitle,
        'description' => $moduleTitle . ' — ' . $lessonTitle,
        'noindex' => true,
    ]);
}
