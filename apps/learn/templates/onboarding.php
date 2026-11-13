<?php

declare(strict_types=1);

/**
 * Онбординг ученика: три вопроса с вариантами ответа.
 *
 * @var list<array{value: string, label: string}> $selfOptions
 * @var list<array{value: string, label: string}> $goalOptions
 * @var list<int> $minuteOptions
 * @var string $notice
 * @var array<string, string> $errors
 * @var array{self_assessment: string, goal: string, daily_minutes: string} $form
 */
$fieldError = static function (string $name) use ($errors): string {
    return (string) ($errors[$name] ?? '');
};

$radios = static function (string $name, array $options, string $value) use ($fieldError): string {
    $error = $fieldError($name);
    $invalid = $error !== '' ? ' aria-invalid="true"' : '';
    $describe = $error !== '' ? ' aria-describedby="' . e($name) . '-error"' : '';
    $html = '';
    foreach ($options as $option) {
        $checked = (string) $option['value'] === $value ? ' checked' : '';
        $html .= '<label class="flex min-h-[44px] cursor-pointer items-center gap-2" for="' . e($name) . '-' . e((string) $option['value']) . '">'
            . '<input class="size-11 min-h-[44px]" type="radio" name="' . e($name) . '"'
            . ' id="' . e($name) . '-' . e((string) $option['value']) . '" value="' . e((string) $option['value']) . '"' . $checked . $invalid . $describe . '>'
            . '<span class="text-sm text-ink">' . e((string) $option['label']) . '</span>'
            . '</label>';
    }
    if ($error !== '') {
        $html .= '<span class="mt-1.5 block text-sm text-red-700" id="' . e($name) . '-error">' . e($error) . '</span>';
    }
    return $html;
};

$content = '<section class="mx-auto max-w-md space-y-6">'
    . partial('partials/breadcrumbs', ['items' => [
        ['label' => 'Главная', 'href' => '/'],
        ['label' => 'Онбординг', 'href' => ''],
    ]])
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Онбординг</h1>'
    . ($notice !== '' ? '<p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">' . e($notice) . '</p>' : '')
    . '<p class="text-ink/70">Расскажите о себе, чтобы школа подобрала начальный уровень и курс.</p>'
    . '</header>'
    . '<form class="space-y-6 rounded-xl border border-ink/10 bg-white p-5" method="post" action="/onboarding">'
    . csrfField()
    . '<div class="space-y-3">'
    . '<p class="text-sm font-medium text-ink/80">Как вы учили английский?</p>'
    . $radios('self_assessment', $selfOptions, $form['self_assessment'])
    . '</div>'
    . '<div class="space-y-3">'
    . '<p class="text-sm font-medium text-ink/80">Цель изучения</p>'
    . $radios('goal', $goalOptions, $form['goal'])
    . '</div>'
    . '<div class="space-y-3">'
    . '<p class="text-sm font-medium text-ink/80">Сколько минут в день готовы заниматься?</p>'
    . $radios('daily_minutes', array_map(static fn (int $m): array => ['value' => (string) $m, 'label' => $m . ' минут'], $minuteOptions), $form['daily_minutes'])
    . '</div>'
    . '<button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Продолжить'
    . '</button>'
    . '</form>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
