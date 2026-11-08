<?php

declare(strict_types=1);

// Вклад раздела «Курсы» в конструктор страниц: карта уровней школы.
// Ядро хранит настройки блока как есть и смысла их не понимает, поэтому число уровней и адрес
// кнопки сверяет сам вклад, а данные берёт из своих таблиц — карта не расходится с программой:
// уровень без опубликованного курса не печатается, число уроков считается по живым строкам.

/**
 * Перечень вкладов раздела для конструктора страниц.
 *
 * @return list<array{kind: string, name: string, fields: list<array{key: string, label: string, hint: string}>}>
 */
function learnPagesBlockKinds(): array
{
    return [
        [
            'kind' => LEARN_PAGES_LEVELS_KIND,
            'name' => 'Курсы — карта уровней',
            'fields' => [
                [
                    'key' => 'count',
                    'label' => 'Сколько уровней',
                    'hint' => 'От 1 до ' . LEARN_PAGES_LEVELS_LIMIT . '; пусто — все уровни программы.',
                ],
                ['key' => 'cta_label', 'label' => 'Подпись кнопки', 'hint' => 'Пусто — кнопки нет.'],
                ['key' => 'cta_href', 'label' => 'Адрес кнопки', 'hint' => 'Например /placement-test.'],
            ],
        ],
    ];
}

/**
 * Фильтр перечня вкладов: выключенный раздел не предлагает своих видов в селекте конструктора.
 * Именованная функция, а не замыкание: подписки в routes.php читает страж маршрутов.
 *
 * @param list<array{kind: string, name: string, fields: list<array{key: string, label: string, hint: string}>}> $kinds
 * @return list<array{kind: string, name: string, fields: list<array{key: string, label: string, hint: string}>}>
 */
function learnPagesBlockKindsFilter(array $kinds): array
{
    if (!isAppActive('learn')) {
        return $kinds;
    }

    return array_merge($kinds, learnPagesBlockKinds());
}

/**
 * Разбор настройки «сколько уровней»: пусто — вся программа, кривое число — отказ с причиной.
 * Молча зажатое значение человек прочитал бы как свою настройку, поэтому предела два: пусто и 1..N.
 *
 * @return array{limit: int, error: string}
 */
function learnLevelsCountPlan(mixed $raw): array
{
    $value = trim((string) (is_scalar($raw) ? $raw : ''));

    if ($value === '') {
        return ['limit' => LEARN_PAGES_LEVELS_LIMIT, 'error' => ''];
    }

    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > LEARN_PAGES_LEVELS_LIMIT) {
        return [
            'limit' => 0,
            'error' => 'Число уровней должно быть от 1 до ' . LEARN_PAGES_LEVELS_LIMIT . '.',
        ];
    }

    return ['limit' => (int) $value, 'error' => ''];
}

/**
 * Строки карты: код и название уровня плюс число уроков его опубликованного курса.
 * Уровень без уроков в карту не попадает — пустая ступень обещала бы программу, которой нет.
 *
 * @return list<array{code: string, title: string, lessons: int}>
 */
function learnLevelCardRows(int $limit): array
{
    if ($limit < 1) {
        return [];
    }

    $levels = all(sprintf(
        'SELECT id, code, title FROM %s ORDER BY sort, id LIMIT %d',
        t('learn_level'),
        $limit
    ));

    if ($levels === []) {
        return [];
    }

    // Один запрос на все уровни вместо запроса на каждый: карта печатается на публичной странице,
    // и число обращений к базе не должно зависеть от числа ступеней.
    $counts = [];
    foreach (all(
        'SELECT course.level_id AS level_id, COUNT(*) AS lessons'
            . ' FROM ' . t('learn_lesson') . ' AS lesson'
            . ' JOIN ' . t('learn_module') . ' AS module ON module.id = lesson.module_id'
            . ' JOIN ' . t('learn_course') . ' AS course ON course.id = module.course_id'
            . ' WHERE lesson.status = ? AND module.status = ? AND course.status = ?'
            . ' GROUP BY course.level_id LIMIT 100',
        ['PUBLISHED', 'PUBLISHED', 'PUBLISHED']
    ) as $row) {
        $counts[(string) $row['level_id']] = (int) $row['lessons'];
    }

    $out = [];

    foreach ($levels as $level) {
        $lessons = $counts[(string) $level['id']] ?? 0;

        if ($lessons < 1) {
            continue;
        }

        $out[] = [
            'code' => (string) $level['code'],
            'title' => (string) $level['title'],
            'lessons' => $lessons,
        ];
    }

    return $out;
}

/**
 * Рендер вклада «Курсы — карта уровней»: шапка секции, карточки ступеней и необязательная кнопка.
 *
 * @param array<string, string> $settings
 * @param array<string, string> $head
 */
function learnPagesLevelsHtml(string $html, array $settings, array $head): string
{
    if (!isAppActive('learn')) {
        return $html;
    }

    $plan = learnLevelsCountPlan($settings['count'] ?? '');

    if ($plan['error'] !== '') {
        logWarn('learn.pages_levels_count', ['path' => requestPath(), 'count' => (string) ($settings['count'] ?? '')]);

        return $html;
    }

    $cards = learnLevelCardRows($plan['limit']);

    if ($cards === []) {
        return $html;
    }

    $anchor = trim((string) ($head['anchor'] ?? ''));
    $badge = trim((string) ($head['badge'] ?? ''));
    $title = trim((string) ($head['title'] ?? ''));
    $lead = trim((string) ($head['lead'] ?? ''));
    $note = trim((string) ($head['note'] ?? ''));

    $content = '<section class="py-12"' . ($anchor !== '' ? ' id="' . e($anchor) . '"' : '') . '>'
        . '<div class="mx-auto max-w-5xl px-4 sm:px-6">';

    if ($badge !== '') {
        $content .= '<p class="text-sm font-semibold uppercase tracking-wide text-ink/60">' . e($badge) . '</p>';
    }

    if ($title !== '') {
        $content .= '<h2 class="mt-2 text-2xl font-bold sm:text-3xl">' . e($title) . '</h2>';
    }

    foreach (pagesParagraphs($lead) as $paragraph) {
        $content .= '<p class="mt-4 max-w-2xl text-ink/80">' . e($paragraph) . '</p>';
    }

    $content .= '<ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">';

    foreach ($cards as $card) {
        $content .= '<li class="rounded-xl border border-ink/10 bg-cream p-6">'
            . '<p class="text-sm font-semibold uppercase tracking-wide text-ink/60">' . e($card['code']) . '</p>'
            . '<h3 class="mt-1 text-lg font-semibold">' . e($card['title']) . '</h3>'
            . '<p class="mt-2 text-sm text-ink/70">' . e((string) $card['lessons']) . ' '
            . e(plural($card['lessons'], ['урок', 'урока', 'уроков'])) . '</p>'
            . '</li>';
    }

    $content .= '</ol>';

    if ($note !== '') {
        $content .= '<p class="mt-4 text-sm text-ink/50">' . e($note) . '</p>';
    }

    // Кнопка печатается только целой парой «подпись + адрес»: половина пары — обрывок формы,
    // и человек увидел бы кнопку в никуда или надпись без действия.
    $label = trim((string) ($settings['cta_label'] ?? ''));
    $href = trim((string) ($settings['cta_href'] ?? ''));

    if ($label !== '' && $href !== '') {
        $content .= '<p class="mt-6"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 font-semibold text-cream hover:opacity-90"'
            . ' href="' . e($href) . '">' . e($label) . '</a></p>';
    }

    return $html . $content . '</div></section>';
}
