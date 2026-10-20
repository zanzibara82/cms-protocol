<?php

declare(strict_types=1);

/**
 * Открытая регистрация продавца: почта, имя и пароль.
 *
 * @var array<string, string> $errors
 * @var array{email: string, name: string} $form
 */
$field = static function (string $name, string $label, string $type, string $value, string $autocomplete) use ($errors): string {
    $error = (string) ($errors[$name] ?? '');
    $invalid = $error !== '' ? ' aria-invalid="true"' : '';
    $describe = $error !== '' ? ' aria-describedby="' . e($name) . '-error"' : '';

    $html = '<label class="block" for="' . e($name) . '">'
        . '<span class="mb-1 block text-sm text-ink/70">' . e($label) . '</span>'
        . '<input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2"'
        . ' id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '"'
        . ' value="' . e($value) . '" autocomplete="' . e($autocomplete) . '" required' . $invalid . $describe . '>';

    if ($error !== '') {
        $html .= '<span class="mt-1 block text-sm text-red-700" id="' . e($name) . '-error">' . e($error) . '</span>';
    }

    return $html . '</label>';
};

$content = '<section class="mx-auto max-w-md space-y-6">'
    . '<header class="space-y-2">'
    . '<h1 class="text-3xl font-bold">Регистрация продавца</h1>'
    . '<p class="text-ink/70">Аккаунт нужен, чтобы размещать объявления и следить за ними. Уже есть аккаунт — '
    . '<a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"'
    . ' href="/login?next=%2Fclassifieds%2Fcabinet">войдите</a>.</p>'
    . '</header>'
    . '<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-5" method="post" action="/classifieds/register">'
    . csrfField()
    . $field('email', 'Почта', 'email', $form['email'], 'email')
    . $field('name', 'Как вас зовут', 'text', $form['name'], 'name')
    . $field('password', 'Пароль (не короче 8 символов)', 'password', '', 'new-password')
    . '<button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">'
    . 'Зарегистрироваться'
    . '</button>'
    . '</form>'
    . '</section>';
?>
<?= $content /* @safe html */ ?>
