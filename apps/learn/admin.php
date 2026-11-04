<?php

declare(strict_types=1);

// Контроллеры контент-админки раздела «Курсы». Право на вход проверяет гейт маршрута
// (private + permission => 'login'), повторной проверки здесь нет: она была бы вторым
// описанием того же правила. Один POST-обработчик с `op` на экране курсов и уроков,
// `csrfCheck()` — первый вызов, отказы — finishAppAction с человеческой причиной.

// Чтение всего дерева контента без фильтра по статусу: админ видит черновики и ревью.

/** @return list<array{id: string, code: string, title: string, sort: int}> */
function learnAdminLevels(): array
{
    return all(
        'SELECT id, code, title, sort FROM ' . t('learn_level') . ' ORDER BY sort, id LIMIT 200'
    );
}

/** @return list<array{id: string, level_id: string, title: string, description: string, status: string, sort: int}> */
function learnAdminCourseRows(): array
{
    return all(
        'SELECT id, level_id, title, description, status, sort FROM ' . t('learn_course')
            . ' ORDER BY level_id, sort, id LIMIT 500'
    );
}

/** @return list<array{id: string, course_id: string, title: string, description: string, status: string, sort: int}> */
function learnAdminModules(): array
{
    return all(
        'SELECT id, course_id, title, description, status, sort FROM ' . t('learn_module')
            . ' ORDER BY course_id, sort, id LIMIT 500'
    );
}

/** @return list<array{id: string, module_id: string, slug: string, title: string, description: string, status: string, sort: int}> */
function learnAdminLessonRows(): array
{
    return all(
        'SELECT id, module_id, slug, title, description, status, sort FROM ' . t('learn_lesson')
            . ' ORDER BY module_id, sort, id LIMIT 1000'
    );
}

/** @return array<string, mixed>|null */
function learnAdminLevelById(string $id): ?array
{
    $row = one(
        'SELECT id, code, title, sort FROM ' . t('learn_level') . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    return $row === null ? null : [
        'id' => (string) $row['id'],
        'code' => (string) $row['code'],
        'title' => (string) $row['title'],
        'sort' => (int) $row['sort'],
    ];
}

/** @return array<string, mixed>|null */
function learnAdminCourseById(string $id): ?array
{
    $row = one(
        'SELECT id, level_id, title, description, status, sort FROM ' . t('learn_course') . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    return $row === null ? null : [
        'id' => (string) $row['id'],
        'level_id' => (string) $row['level_id'],
        'title' => (string) $row['title'],
        'description' => (string) $row['description'],
        'status' => (string) $row['status'],
        'sort' => (int) $row['sort'],
    ];
}

/** @return array<string, mixed>|null */
function learnAdminModuleById(string $id): ?array
{
    $row = one(
        'SELECT id, course_id, title, description, status, sort FROM ' . t('learn_module') . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    return $row === null ? null : [
        'id' => (string) $row['id'],
        'course_id' => (string) $row['course_id'],
        'title' => (string) $row['title'],
        'description' => (string) $row['description'],
        'status' => (string) $row['status'],
        'sort' => (int) $row['sort'],
    ];
}

/** @return array<string, mixed>|null */
function learnAdminLessonById(string $id): ?array
{
    $row = one(
        'SELECT id, module_id, slug, title, description, status, sort FROM ' . t('learn_lesson') . ' WHERE id = ? LIMIT 1',
        [$id]
    );

    return $row === null ? null : [
        'id' => (string) $row['id'],
        'module_id' => (string) $row['module_id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'description' => (string) $row['description'],
        'status' => (string) $row['status'],
        'sort' => (int) $row['sort'],
    ];
}

// Экран админки: обзор — счётчики и ссылки на экраны контента.

/** @param array<string, string> $params */
function learnAdminSummary(array $params = []): void
{
    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $levels = (int) value('SELECT COUNT(*) FROM ' . t('learn_level'));
    $courses = (int) value('SELECT COUNT(*) FROM ' . t('learn_course'));
    $modules = (int) value('SELECT COUNT(*) FROM ' . t('learn_module'));
    $lessons = (int) value('SELECT COUNT(*) FROM ' . t('learn_lesson'));

    render('learn/admin/summary', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'levels' => $levels,
        'courses' => $courses,
        'modules' => $modules,
        'lessons' => $lessons,
    ], ['title' => 'Курсы — админка', 'noindex' => true]);
}

// Дерево уровней с курсами и модулями: уровни строкой, под каждым — курсы,
// под каждым курсом — модули. Права и статус не фильтруются: админ управляет всем.

/** @param array<string, string> $params */
function learnAdminCourses(array $params = []): void
{
    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $levels = learnAdminLevels();
    $courses = learnAdminCourseRows();
    $modules = learnAdminModules();

    $coursesByLevel = [];
    foreach ($courses as $course) {
        $coursesByLevel[(string) $course['level_id']][] = $course;
    }

    $modulesByCourse = [];
    foreach ($modules as $module) {
        $modulesByCourse[(string) $module['course_id']][] = $module;
    }

    $tree = [];
    foreach ($levels as $level) {
        $levelCourses = [];
        foreach ($coursesByLevel[(string) $level['id']] ?? [] as $course) {
            $levelCourses[] = [
                'course' => $course,
                'modules' => $modulesByCourse[(string) $course['id']] ?? [],
            ];
        }

        $tree[] = [
            'level' => $level,
            'courses' => $levelCourses,
        ];
    }

    render('learn/admin/courses', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'tree' => $tree,
    ], ['title' => 'Уровни и курсы — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function learnAdminCoursesAction(array $params = []): void
{
    csrfCheck();

    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $op = postString('op');

    $result = match ($op) {
        'create-level' => learnAdminCreateLevel(),
        'save-level' => learnAdminSaveLevel(),
        'move-level' => learnAdminMoveLevel(),
        'delete-level' => learnAdminDeleteLevel(),
        'create-course' => learnAdminCreateCourse(),
        'save-course' => learnAdminSaveCourse(),
        'move-course' => learnAdminMoveCourse(),
        'delete-course' => learnAdminDeleteCourse(),
        'create-module' => learnAdminCreateModule(),
        'save-module' => learnAdminSaveModule(),
        'move-module' => learnAdminMoveModule(),
        'delete-module' => learnAdminDeleteModule(),
        default => ['ok' => false, 'error' => 'Неизвестное действие.'],
    };

    $notice = match ($op) {
        'create-level' => 'learn_saved',
        'save-level', 'toggle-level' => 'learn_saved',
        'move-level' => 'learn_moved',
        'delete-level' => 'learn_deleted',
        'create-course' => 'learn_saved',
        'save-course', 'toggle-course' => 'learn_saved',
        'move-course' => 'learn_moved',
        'delete-course' => 'learn_deleted',
        'create-module' => 'learn_saved',
        'save-module', 'toggle-module' => 'learn_saved',
        'move-module' => 'learn_moved',
        'delete-module' => 'learn_deleted',
        default => '',
    };

    finishAppAction($result, '/learn/admin/courses', $notice);
}

/** @param array<string, string> $params */
function learnAdminLessons(array $params = []): void
{
    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $lessons = learnAdminLessonRows();

    $modules = learnAdminModules();
    $moduleById = [];
    foreach ($modules as $module) {
        $moduleById[(string) $module['id']] = $module;
    }

    $courses = learnAdminCourseRows();
    $courseById = [];
    foreach ($courses as $course) {
        $courseById[(string) $course['id']] = $course;
    }

    $levels = learnAdminLevels();
    $levelById = [];
    foreach ($levels as $level) {
        $levelById[(string) $level['id']] = $level;
    }

    $rows = [];
    foreach ($lessons as $lesson) {
        $module = $moduleById[(string) $lesson['module_id']] ?? null;
        $course = $module !== null ? ($courseById[(string) $module['course_id']] ?? null) : null;
        $level = $course !== null ? ($levelById[(string) $course['level_id']] ?? null) : null;

        $rows[] = [
            'lesson' => $lesson,
            'module' => $module,
            'course' => $course,
            'level' => $level,
        ];
    }

    render('learn/admin/lessons', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'rows' => $rows,
    ], ['title' => 'Уроки — админка', 'noindex' => true]);
}

/** @param array<string, string> $params */
function learnAdminLessonsAction(array $params = []): void
{
    csrfCheck();

    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $op = postString('op');

    if ($op === 'delete-lesson') {
        $lessonId = postString('item');
        $result = learnAdminDeleteLesson($lessonId);
        finishAppAction($result, '/learn/admin/lessons', 'learn_deleted');
    }

    finishAppAction(['ok' => false, 'error' => 'Неизвестное действие.'], '/learn/admin/lessons', '');
}

// Форма урока: создание и правка отличаются только адресом, всё остальное — общий экран.

/** @param array<string, string> $params */
function learnAdminLessonNew(array $params = []): void
{
    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $modules = learnAdminModules();
    learnAdminLessonForm([], [], [], '', false, $modules);
}

/** @param array<string, string> $params */
function learnAdminLessonEdit(array $params = []): void
{
    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $id = mb_substr(trim((string) ($params['id'] ?? '')), 0, 26);
    $lesson = one(
        'SELECT id, module_id, slug, title, description, status, sort FROM ' . t('learn_lesson')
            . ' WHERE (id = ? OR slug = ?) LIMIT 1',
        [$id, $id]
    );

    if ($lesson === null) {
        finishAppAction(
            ['ok' => false, 'error' => 'Урока уже нет — обновите список.'],
            '/learn/admin/lessons',
            ''
        );
    }

    $modules = learnAdminModules();
    $raw = [
        'module_id' => $lesson['module_id'],
        'slug' => $lesson['slug'],
        'title' => $lesson['title'],
        'description' => $lesson['description'],
        'status' => $lesson['status'],
    ];

    learnAdminLessonForm($lesson, $raw, [], '', false, $modules);
}

/** @param array<string, string> $params */
function learnAdminLessonSaveAction(array $params = []): void
{
    csrfCheck();

    $user = learnUser();
    if (!roleGrantsManage(['role' => (string) $user['role'], 'blocked' => $user['blocked_at'] !== null])) {
        forbidden('Раздел доступен только редактору и администратору.');
    }

    $id = postString('id');
    $isNew = $id === '';

    $raw = [
        'module_id' => postString('module_id'),
        'slug' => postString('slug'),
        'title' => postString('title'),
        'description' => postString('description'),
        'status' => postString('status'),
    ];

    $errors = [];

    if ($raw['module_id'] === '') {
        $errors['module_id'] = 'Выберите модуль — без него урок не appartient к курсу.';
    }

    if ($raw['slug'] === '') {
        $errors['slug'] = 'Придумайте адрес урока — по нему открывается страница.';
    } elseif (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $raw['slug'])) {
        $errors['slug'] = 'Адрес только из строчных латинских букв, цифр и дефиса, не начинается с дефиса.';
    } elseif (mb_strlen($raw['slug']) > 120) {
        $errors['slug'] = 'Адрес длиннее 120 символов — сократите.';
    }

    if ($raw['title'] === '') {
        $errors['title'] = 'Название урока не может быть пустым.';
    } elseif (mb_strlen($raw['title']) > 120) {
        $errors['title'] = 'Название длиннее 120 символов — сократите.';
    }

    if (!in_array($raw['status'], ['DRAFT', 'REVIEW', 'PUBLISHED'], true)) {
        $errors['status'] = 'Выберите статус урока.';
    }

    if ($errors !== []) {
        $modules = learnAdminModules();
        learnAdminLessonForm([], $raw, $errors, '', $isNew, $modules);

        return;
    }

    if ($isNew) {
        $existing = one(
            'SELECT id FROM ' . t('learn_lesson') . ' WHERE slug = ? LIMIT 1',
            [$raw['slug']]
        );
        if ($existing !== null) {
            $errors['slug'] = 'По такому адресу уже есть урок — придумайте другой.';
            $modules = learnAdminModules();
            learnAdminLessonForm([], $raw, $errors, '', true, $modules);

            return;
        }

        $result = tx(static function () use ($raw): array {
            $id = newId();

            insert('learn_lesson', [
                'id' => $id,
                'module_id' => $raw['module_id'],
                'slug' => $raw['slug'],
                'title' => trim($raw['title']),
                'description' => trim($raw['description']),
                'status' => $raw['status'],
                'sort' => (int) value(
                    'SELECT COALESCE(MAX(sort), -1) + 1 FROM ' . t('learn_lesson') . ' WHERE module_id = ?',
                    [$raw['module_id']]
                ),
            ]);

            return ['ok' => true, 'error' => '', 'id' => $id];
        });
    } else {
        $lesson = learnAdminLessonById($id);
        if ($lesson === null) {
            finishAppAction(
                ['ok' => false, 'error' => 'Урока уже нет — обновите список.'],
                '/learn/admin/lessons',
                ''
            );
        }

        $result = tx(static function () use ($id, $raw): array {
            run(
                'UPDATE ' . t('learn_lesson') . ' SET module_id = ?, slug = ?, title = ?, description = ?, status = ? WHERE id = ?',
                [
                    $raw['module_id'],
                    $raw['slug'],
                    trim($raw['title']),
                    trim($raw['description']),
                    $raw['status'],
                    $id,
                ]
            );

            return ['ok' => true, 'error' => '', 'id' => $id];
        });
    }

    if (($result['ok'] ?? false) === false) {
        $modules = learnAdminModules();
        learnAdminLessonForm([], $raw, [$raw['slug'] => $result['error'] ?? ''], '', $isNew, $modules);

        return;
    }

    finishAppAction($result, '/learn/admin/lessons', 'learn_saved');
}

/** @return array<string, mixed> */
function learnAdminCreateLevel(): array
{
    $code = trim(postString('code'));
    $title = trim(postString('title'));
    $sort = (int) postString('sort');

    if ($code === '' || $title === '') {
        return ['ok' => false, 'error' => 'Код и название уровня не могут быть пустыми.'];
    }

    $existing = one(
        'SELECT id FROM ' . t('learn_level') . ' WHERE code = ? LIMIT 1',
        [$code]
    );
    if ($existing !== null) {
        return ['ok' => false, 'error' => 'Уровень с таким кодом уже есть.'];
    }

    try {
        $id = newId();

        insert('learn_level', [
            'id' => $id,
            'code' => $code,
            'title' => $title,
            'sort' => $sort,
        ]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Уровень не сохранился — повторите попытку.'];
    }

    logInfo('learn.level_created', ['id' => $id, 'code' => $code]);

    return ['ok' => true, 'error' => '', 'id' => $id];
}

/** @return array<string, mixed> */
function learnAdminSaveLevel(): array
{
    $id = postString('item');
    $code = trim(postString('code'));
    $title = trim(postString('title'));
    $sort = (int) postString('sort');

    $level = learnAdminLevelById($id);
    if ($level === null) {
        return ['ok' => false, 'error' => 'Уровень исчез — обновите список.'];
    }

    if ($code === '' || $title === '') {
        return ['ok' => false, 'error' => 'Код и название уровня не могут быть пустыми.'];
    }

    $existing = one(
        'SELECT id FROM ' . t('learn_level') . ' WHERE code = ? AND id <> ? LIMIT 1',
        [$code, $id]
    );
    if ($existing !== null) {
        return ['ok' => false, 'error' => 'Уровень с таким кодом уже есть.'];
    }

    try {
        run(
            'UPDATE ' . t('learn_level') . ' SET code = ?, title = ?, sort = ? WHERE id = ?',
            [$code, $title, $sort, $id]
        );
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Уровень не сохранился — повторите попытку.'];
    }

    logInfo('learn.level_updated', ['id' => $id, 'code' => $code]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminMoveLevel(): array
{
    $id = postString('item');
    $dir = postString('dir');

    if (!in_array($dir, ['up', 'down'], true)) {
        return ['ok' => false, 'error' => 'Направление перестановки — «вверх» или «вниз».'];
    }

    $level = learnAdminLevelById($id);
    if ($level === null) {
        return ['ok' => false, 'error' => 'Уровень исчез — обновите список.'];
    }

    $up = $dir === 'up';
    $neighbor = one(
        'SELECT id, sort FROM ' . t('learn_level') . ' WHERE sort ' . ($up ? '<' : '>') . ' ? ORDER BY sort ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
        [(int) $level['sort']]
    );

    if ($neighbor === null) {
        return ['ok' => false, 'error' => 'Уровень уже крайний.'];
    }

    try {
        tx(static function () use ($id, $neighbor, $level): void {
            $neighborId = (string) $neighbor['id'];
            $sort = (int) $level['sort'];
            $neighborSort = (int) $neighbor['sort'];

            run('UPDATE ' . t('learn_level') . ' SET sort = ? WHERE id = ?', [$neighborSort, $id]);
            run('UPDATE ' . t('learn_level') . ' SET sort = ? WHERE id = ?', [$sort, $neighborId]);
        });
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Порядок не изменился — повторите попытку.'];
    }

    logInfo('learn.level_moved', ['id' => $id, 'direction' => $dir]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminDeleteLevel(): array
{
    $id = postString('item');

    $level = learnAdminLevelById($id);
    if ($level === null) {
        return ['ok' => false, 'error' => 'Уровень исчез — обновите список.'];
    }

    $courseCount = (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_course') . ' WHERE level_id = ? LIMIT 1',
        [$id]
    );

    if ($courseCount > 0) {
        return [
            'ok' => false,
            'error' => 'На уровне есть курсы — сперва удалите или перенесите их, уровень нельзя.',
        ];
    }

    try {
        run('DELETE FROM ' . t('learn_level') . ' WHERE id = ?', [$id]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Уровень не удалён — повторите попытку.'];
    }

    logInfo('learn.level_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminCreateCourse(): array
{
    $levelId = postString('level_id');
    $title = trim(postString('title'));
    $description = trim(postString('description'));
    $status = postString('status');
    $sort = (int) postString('sort');

    if ($levelId === '' || $title === '') {
        return ['ok' => false, 'error' => 'Уровень и название курса не могут быть пустыми.'];
    }

    if (!in_array($status, ['DRAFT', 'REVIEW', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }

    try {
        $id = newId();

        insert('learn_course', [
            'id' => $id,
            'level_id' => $levelId,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'sort' => $sort,
        ]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Курс не сохранился — повторите попытку.'];
    }

    logInfo('learn.course_created', ['id' => $id, 'level_id' => $levelId]);

    return ['ok' => true, 'error' => '', 'id' => $id];
}

/** @return array<string, mixed> */
function learnAdminSaveCourse(): array
{
    $id = postString('item');
    $levelId = postString('level_id');
    $title = trim(postString('title'));
    $description = trim(postString('description'));
    $status = postString('status');
    $sort = (int) postString('sort');

    $course = learnAdminCourseById($id);
    if ($course === null) {
        return ['ok' => false, 'error' => 'Курс исчез — обновите список.'];
    }

    if ($levelId === '' || $title === '') {
        return ['ok' => false, 'error' => 'Уровень и название курса не могут быть пустыми.'];
    }

    if (!in_array($status, ['DRAFT', 'REVIEW', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }

    try {
        run(
            'UPDATE ' . t('learn_course') . ' SET level_id = ?, title = ?, description = ?, status = ?, sort = ? WHERE id = ?',
            [$levelId, $title, $description, $status, $sort, $id]
        );
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Курс не сохранился — повторите попытку.'];
    }

    logInfo('learn.course_updated', ['id' => $id, 'level_id' => $levelId]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminMoveCourse(): array
{
    $id = postString('item');
    $dir = postString('dir');

    if (!in_array($dir, ['up', 'down'], true)) {
        return ['ok' => false, 'error' => 'Направление перестановки — «вверх» или «вниз».'];
    }

    $course = learnAdminCourseById($id);
    if ($course === null) {
        return ['ok' => false, 'error' => 'Курс исчез — обновите список.'];
    }

    $up = $dir === 'up';
    $neighbor = one(
        'SELECT id, sort FROM ' . t('learn_course') . ' WHERE level_id = ? AND sort ' . ($up ? '<' : '>') . ' ? ORDER BY sort ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
        [$course['level_id'], (int) $course['sort']]
    );

    if ($neighbor === null) {
        return ['ok' => false, 'error' => 'Курс уже крайний в своём уровне.'];
    }

    try {
        tx(static function () use ($id, $neighbor, $course): void {
            $neighborId = (string) $neighbor['id'];
            $sort = (int) $course['sort'];
            $neighborSort = (int) $neighbor['sort'];

            run('UPDATE ' . t('learn_course') . ' SET sort = ? WHERE id = ?', [$neighborSort, $id]);
            run('UPDATE ' . t('learn_course') . ' SET sort = ? WHERE id = ?', [$sort, $neighborId]);
        });
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Порядок не изменился — повторите попытку.'];
    }

    logInfo('learn.course_moved', ['id' => $id, 'direction' => $dir]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminDeleteCourse(): array
{
    $id = postString('item');

    $course = learnAdminCourseById($id);
    if ($course === null) {
        return ['ok' => false, 'error' => 'Курс исчез — обновите список.'];
    }

    $moduleCount = (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_module') . ' WHERE course_id = ? LIMIT 1',
        [$id]
    );
    $lessonCount = (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_lesson') . ' l JOIN ' . t('learn_module') . ' m ON m.id = l.module_id WHERE m.course_id = ? LIMIT 1',
        [$id]
    );

    if ($moduleCount > 0 || $lessonCount > 0) {
        return [
            'ok' => false,
            'error' => sprintf(
                'На курсе %d модулей и %d уроков — сперва удалите их, курс нельзя.',
                $moduleCount,
                $lessonCount
            ),
        ];
    }

    try {
        run('DELETE FROM ' . t('learn_course') . ' WHERE id = ?', [$id]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Курс не удалён — повторите попытку.'];
    }

    logInfo('learn.course_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminCreateModule(): array
{
    $courseId = postString('course_id');
    $title = trim(postString('title'));
    $description = trim(postString('description'));
    $status = postString('status');
    $sort = (int) postString('sort');

    if ($courseId === '' || $title === '') {
        return ['ok' => false, 'error' => 'Курс и название модуля не могут быть пустыми.'];
    }

    if (!in_array($status, ['DRAFT', 'REVIEW', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }

    try {
        $id = newId();

        insert('learn_module', [
            'id' => $id,
            'course_id' => $courseId,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'sort' => $sort,
        ]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Модуль не сохранился — повторите попытку.'];
    }

    logInfo('learn.module_created', ['id' => $id, 'course_id' => $courseId]);

    return ['ok' => true, 'error' => '', 'id' => $id];
}

/** @return array<string, mixed> */
function learnAdminSaveModule(): array
{
    $id = postString('item');
    $courseId = postString('course_id');
    $title = trim(postString('title'));
    $description = trim(postString('description'));
    $status = postString('status');
    $sort = (int) postString('sort');

    $module = learnAdminModuleById($id);
    if ($module === null) {
        return ['ok' => false, 'error' => 'Модуль исчез — обновите список.'];
    }

    if ($courseId === '' || $title === '') {
        return ['ok' => false, 'error' => 'Курс и название модуля не могут быть пустыми.'];
    }

    if (!in_array($status, ['DRAFT', 'REVIEW', 'PUBLISHED'], true)) {
        $status = 'DRAFT';
    }

    try {
        run(
            'UPDATE ' . t('learn_module') . ' SET course_id = ?, title = ?, description = ?, status = ?, sort = ? WHERE id = ?',
            [$courseId, $title, $description, $status, $sort, $id]
        );
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Модуль не сохранился — повторите попытку.'];
    }

    logInfo('learn.module_updated', ['id' => $id, 'course_id' => $courseId]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminMoveModule(): array
{
    $id = postString('item');
    $dir = postString('dir');

    if (!in_array($dir, ['up', 'down'], true)) {
        return ['ok' => false, 'error' => 'Направление перестановки — «вверх» или «вниз».'];
    }

    $module = learnAdminModuleById($id);
    if ($module === null) {
        return ['ok' => false, 'error' => 'Модуль исчез — обновите список.'];
    }

    $up = $dir === 'up';
    $neighbor = one(
        'SELECT id, sort FROM ' . t('learn_module') . ' WHERE course_id = ? AND sort ' . ($up ? '<' : '>') . ' ? ORDER BY sort ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
        [$module['course_id'], (int) $module['sort']]
    );

    if ($neighbor === null) {
        return ['ok' => false, 'error' => 'Модуль уже крайний в своём курсе.'];
    }

    try {
        tx(static function () use ($id, $neighbor, $module): void {
            $neighborId = (string) $neighbor['id'];
            $sort = (int) $module['sort'];
            $neighborSort = (int) $neighbor['sort'];

            run('UPDATE ' . t('learn_module') . ' SET sort = ? WHERE id = ?', [$neighborSort, $id]);
            run('UPDATE ' . t('learn_module') . ' SET sort = ? WHERE id = ?', [$sort, $neighborId]);
        });
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Порядок не изменился — повторите попытку.'];
    }

    logInfo('learn.module_moved', ['id' => $id, 'direction' => $dir]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminDeleteModule(): array
{
    $id = postString('item');

    $module = learnAdminModuleById($id);
    if ($module === null) {
        return ['ok' => false, 'error' => 'Модуль исчез — обновите список.'];
    }

    $lessonCount = (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_lesson') . ' WHERE module_id = ? LIMIT 1',
        [$id]
    );

    if ($lessonCount > 0) {
        return [
            'ok' => false,
            'error' => sprintf(
                'В модуле %d уроков — сперва удалите их, модуль нельзя.',
                $lessonCount
            ),
        ];
    }

    try {
        run('DELETE FROM ' . t('learn_module') . ' WHERE id = ?', [$id]);
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Модуль не удалён — повторите попытку.'];
    }

    logInfo('learn.module_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => ''];
}

/** @return array<string, mixed> */
function learnAdminDeleteLesson(string $id): array
{
    $lesson = learnAdminLessonById($id);
    if ($lesson === null) {
        return ['ok' => false, 'error' => 'Урока уже нет — обновите список.'];
    }

    try {
        tx(static function () use ($id): void {
            run('DELETE FROM ' . t('learn_objective') . ' WHERE lesson_id = ?', [$id]);
            run('DELETE FROM ' . t('learn_vocab') . ' WHERE lesson_id = ?', [$id]);
            run('DELETE FROM ' . t('learn_grammar') . ' WHERE lesson_id = ?', [$id]);
            run('DELETE FROM ' . t('learn_exercise') . ' WHERE lesson_id = ?', [$id]);
            run('DELETE FROM ' . t('learn_test_question') . ' WHERE test_id IN (SELECT id FROM ' . t('learn_test') . ' WHERE lesson_id = ?)', [$id]);
            run('DELETE FROM ' . t('learn_test') . ' WHERE lesson_id = ?', [$id]);
            run('DELETE FROM ' . t('learn_lesson') . ' WHERE id = ?', [$id]);
        });
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'Урок не удалён — повторите попытку.'];
    }

    logInfo('learn.lesson_deleted', ['id' => $id]);

    return ['ok' => true, 'error' => ''];
}

// Общий экран формы урока: создание и правка.
/**
 * @param array<string, mixed> $lesson
 * @param array<string, mixed> $raw
 * @param array<string, string> $errors
 * @param string $error
 * @param bool $isNew
 * @param list<array<string, mixed>> $modules
 */
function learnAdminLessonForm(
    array $lesson,
    array $raw,
    array $errors,
    string $error,
    bool $isNew,
    array $modules
): void {
    $isNew = $isNew || $lesson === [];

    $values = [
        'module_id' => (string) ($raw['module_id'] ?? ($lesson['module_id'] ?? '')),
        'slug' => (string) ($raw['slug'] ?? ($lesson['slug'] ?? '')),
        'title' => (string) ($raw['title'] ?? ($lesson['title'] ?? '')),
        'description' => (string) ($raw['description'] ?? ($lesson['description'] ?? '')),
        'status' => (string) ($raw['status'] ?? ($lesson['status'] ?? 'DRAFT')),
    ];

    $action = $isNew ? '/learn/admin/lessons/new' : '/learn/admin/lessons/' . (string) ($lesson['id'] ?? '');

    render('learn/admin/lesson-form', [
        'notice' => noticeForAdminScreen(CORE_NOTICE_TEXT),
        'error' => $error,
        'errors' => $errors,
        'values' => $values,
        'modules' => $modules,
        'action' => $action,
        'isNew' => $isNew,
        'lessonId' => (string) ($lesson['id'] ?? ''),
    ], [
        'title' => ($isNew ? 'Новый урок' : 'Правка урока') . ' — админка',
        'noindex' => true,
    ]);
}
