<?php

declare(strict_types=1);

/**
 * Тест на уровень: форма вопросов или экран результата после прохождения.
 *
 * @var array{id: string, title: string, pass_score: int} $test
 * @var list<array{id: string, skill: string, text: string, options: list<string>, audio_url: string|null}> $questions
 * @var string $notice
 * @var array{score: int, vocabulary_score: int|null, grammar_score: int|null, reading_score: int|null, listening_score: int|null, level_code: string, level_title: string, start_module_id: string, questions: list<array<string, mixed>>, created_at: string}|null $result
 */
$questionField = static function (string $name, string $idPrefix, array $options): string {
    $html = '';
    foreach ($options as $optionIndex => $optionText) {
        $inputId = $idPrefix . '-' . $optionIndex;
        $html .= '<label class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg border border-ink/10 p-3 hover:bg-ink/5" for="' . e($inputId) . '">'
            . '<input class="size-11 min-h-[44px]" type="radio" name="' . e($name) . '"'
            . ' id="' . e($inputId) . '" value="' . (string) $optionIndex . '" required>'
            . '<span class="text-sm text-ink">' . e((string) $optionText) . '</span>'
            . '</label>';
    }

    return $html;
};

$hasResult = $result !== null;

if ($hasResult) {
    $content = '<section class="mx-auto max-w-md space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Тест на уровень', 'href' => ''],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Тест на уровень</h1>'
        . '<p class="text-ink/70">' . e($notice) . '</p>'
        . '</header>'
        . '<div class="rounded-xl border border-ink/10 bg-white p-5 space-y-4">'
        . '<div class="space-y-2">'
        . '<span class="text-sm text-ink/60">Балл: ' . (string) $result['score'] . '%</span>'
        . '</div>'
        . '<div class="space-y-2">'
        . '<span class="text-sm text-ink/60">Уровень:</span>'
        . '<span class="text-xl font-bold text-ink">' . e($result['level_code']) . ' — ' . e($result['level_title']) . '</span>'
        . '</div>'
        . (($result['course_title'] ?? '') !== ''
            ? '<div class="space-y-2">'
            . '<span class="text-sm text-ink/60">Курс:</span>'
            . '<span class="text-xl font-bold text-ink">' . e($result['course_title']) . '</span>'
            . '</div>'
            : '')
        . '<div class="space-y-2 border-t border-ink/10 pt-4">'
        . '<p class="text-sm font-medium text-ink/80">Разбор по навыкам</p>'
        . '<div class="space-y-1">'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['VOCABULARY']) . ' — ' . (string) ($result['vocabulary_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['GRAMMAR']) . ' — ' . (string) ($result['grammar_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['READING']) . ' — ' . (string) ($result['reading_score'] ?? 0) . '%</span></div>'
        . '<div class="flex justify-between text-sm"><span class="text-ink">' . e(LEARN_SKILL_LABELS['LISTENING']) . ' — ' . (string) ($result['listening_score'] ?? 0) . '%</span></div>'
        . '</div>'
        . '</div>'
        . '<div class="space-y-3 border-t border-ink/10 pt-4">'
        . '<p class="text-sm font-medium text-ink/80">Разбор ответов</p>';
    foreach ($result['questions'] as $question) {
        $content .= '<div class="space-y-1 text-sm">'
            . '<div class="flex justify-between"><span class="text-ink">' . e((string) $question['text']) . '</span>'
            . '<span class="text-xs ' . (($question['correct'] ?? false) ? 'text-green-700' : 'text-red-700') . ' font-medium">'
            . (($question['correct'] ?? false) ? 'Верно' : 'Неверно') . '</span></div>';
        if (($question['correct'] ?? false) !== true) {
            $content .= '<div class="text-ink/60">Правильный ответ: ' . e((string) (($question['options'][$question['correct_index']] ?? ''))) . '</div>';
        }
        if ((string) ($question['explanation'] ?? '') !== '') {
            $content .= '<div class="text-ink/50 text-xs italic">' . e((string) $question['explanation']) . '</div>';
        }
        $content .= '</div>';
    }
    $content .= '</div>'
        . '</div>'
        . '<div class="space-y-2">'
        . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="/dashboard">'
        . 'В кабинет'
        . '</a>'
        . '</div>'
        . '</section>';
} else {
    $questionCount = count($questions);
    $content = '<section class="mx-auto max-w-md space-y-6">'
        . partial('partials/breadcrumbs', ['items' => [
            ['label' => 'Главная', 'href' => '/'],
            ['label' => 'Тест на уровень', 'href' => ''],
        ]])
        . '<header class="space-y-2">'
        . '<h1 class="text-3xl font-bold">Тест на уровень</h1>'
        . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
        . '<p class="text-ink/70">' . e($test['title']) . '. Пройдёт около десяти минут — по десять вопросов на лексику и грамматику, по пять на чтение и аудирование.</p>'
        . '</header>'
        . '<form class="space-y-4" method="post" action="/placement-test" data-placement-cards>'
        . csrfField()
        . '<div class="space-y-2 rounded-xl border border-ink/10 bg-white p-4" data-placement-progress hidden>'
        . '<div class="flex items-center justify-between text-xs text-ink/60">'
        . '<span data-placement-progress-label>Вопрос 1 из ' . (string) $questionCount . '</span>'
        . '<span data-placement-progress-percent></span>'
        . '</div>'
        . '<div class="h-2 overflow-hidden rounded-full bg-ink/10">'
        . '<div class="h-full rounded-full bg-ink transition-[width] duration-200" style="width: 0%" data-placement-progress-bar></div>'
        . '</div>'
        . '</div>';
    $questionNumber = 0;
    foreach ($questions as $question) {
        $questionNumber++;
        $name = 'answer[' . e((string) $question['id']) . ']';
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        $skill = (string) ($question['skill'] ?? '');
        $skillLabel = LEARN_SKILL_LABELS[$skill] ?? $skill;
        $audioUrl = trim((string) ($question['audio_url'] ?? ''));
        $hasAudio = $audioUrl !== '' && preg_match('~^(?:https?://|/(?!/))~i', $audioUrl) === 1;

        $content .= '<article class="space-y-3 rounded-xl border border-ink/10 bg-white p-5" data-placement-card>'
            . '<div class="flex flex-wrap items-center justify-between gap-2">'
            . '<span class="text-xs font-medium uppercase tracking-wide text-ink/50">Вопрос ' . (string) $questionNumber . '</span>'
            . ($skillLabel !== '' ? '<span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs text-ink/60">' . e($skillLabel) . '</span>' : '')
            . '</div>'
            . '<p class="text-base font-medium text-ink">' . e((string) $question['text']) . '</p>'
            . ($hasAudio
                ? '<audio class="w-full" controls preload="none" src="' . e($audioUrl) . '" data-placement-audio>'
                . '<a class="inline-flex min-h-[44px] items-center text-sm underline" href="' . e($audioUrl) . '">Скачать запись</a>'
                . '</audio>'
                : '')
            . '<div class="space-y-2">' . $questionField($name, 'placement-' . (string) $questionNumber, $options) . '</div>'
            . '</article>';
    }
    $content .= '<p class="text-sm text-red-700" data-placement-error role="alert" hidden>Выберите ответ, чтобы продолжить.</p>'
        . '<div class="flex flex-col gap-2 sm:flex-row">'
        . '<button class="min-h-[44px] rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" type="button" data-placement-prev hidden>Назад</button>'
        . '<button class="min-h-[44px] flex-1 rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit" data-placement-submit>'
        . 'Завершить тест'
        . '</button>'
        . '<button class="min-h-[44px] flex-1 rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="button" data-placement-next hidden>Далее</button>'
        . '</div>'
        . '</form>'
        . '</section>';
}
?>
<?= $content /* @safe html */ ?>
