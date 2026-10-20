<?php

declare(strict_types=1);

/**
 * Форма жалобы на объявление.
 *
 * @var array<string, mixed> $listing
 * @var array<string, string> $reasons
 * @var array<string, string> $errors
 * @var array{reason: string, comment: string} $form
 * @var string $action
 * @var string $back
 */
$options = '';

foreach ($reasons as $value => $label) {
    $options .= '<option value="' . e((string) $value) . '"'
        . ($form['reason'] === (string) $value ? ' selected' : '') . '>'
        . e((string) $label) . '</option>';
}

$error = (string) ($errors['reason'] ?? '');

$content = '<section class="space-y-6">'
    . '<nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">'
    . '<a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds">Доска</a>'
    . '<span aria-hidden="true">/</span>'
    . '<a class="inline-flex min-h-[44px] items-center hover:underline" href="' . e($back) . '">'
    . e((string) $listing['title']) . '</a>'
    . '</nav>'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Жалоба на объявление</h1>'
    . '<p class="text-ink/70">«' . e((string) $listing['title']) . '». Редактор проверит жалобу и примет решение.</p>'
    . '</header>'
    . ($error !== '' ? '<p class="rounded-lg border border-ink/20 bg-cream p-4 text-sm">' . e($error) . '</p>' : '')
    . '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-4" method="post" action="' . e($action) . '">'
    . csrfField()
    . '<label class="block" for="reason">'
    . '<span class="mb-1 block text-sm text-ink/70">Причина</span>'
    . '<select class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="reason" name="reason">'
    . $options
    . '</select>'
    . '</label>'
    . '<label class="block" for="comment">'
    . '<span class="mb-1 block text-sm text-ink/70">Что не так (необязательно)</span>'
    . '<textarea class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="comment" name="comment"'
    . ' maxlength="' . e((string) CLASSIFIEDS_REPORT_COMMENT_MAX) . '">' . e($form['comment']) . '</textarea>'
    . '</label>'
    . '<div class="flex flex-wrap gap-2">'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Отправить жалобу</button>'
    . '<a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="' . e($back) . '">Отменить</a>'
    . '</div>'
    . '</form>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
