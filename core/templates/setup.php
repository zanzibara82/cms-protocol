<?php

declare(strict_types=1);

/** @var list<string> $problems */
/** @var array<string, string> $errors */
/** @var array<string, string> $values */

$disabled = $problems === [] ? '' : ' disabled';
$fields = [
    'site_name' => ['label' => 'Название сайта', 'type' => 'text', 'max' => 120, 'auto' => ''],
    'admin_email' => ['label' => 'Почта администратора', 'type' => 'email', 'max' => 190, 'auto' => 'username'],
    'admin_name' => ['label' => 'Имя администратора', 'type' => 'text', 'max' => 120, 'auto' => ''],
];
?>
<section class="mx-auto max-w-xl py-10">
    <h1 class="text-2xl font-bold">Установка сайта</h1>
    <p class="mt-2 text-sm text-ink/70">Шаг первый из двух: здесь появляются таблицы ядра и первый администратор. Разделы устанавливаются потом, на странице «Приложения».</p>

    <?php if ($problems !== []): ?>
        <ul class="mt-4 space-y-1 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">
            <?php foreach ($problems as $problem): ?>
                <li><?= e($problem) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="mt-6 space-y-4" method="post" action="/setup.php">
        <?php /* @safe html */ ?><?= csrfField() ?>

        <?php foreach ($fields as $name => $field): ?>
            <label class="block text-sm">
                <span class="font-medium"><?= e($field['label']) ?></span>
                <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2"
                       type="<?= e($field['type']) ?>" name="<?= e((string) $name) ?>"
                       value="<?= e($values[(string) $name]) ?>" required
                       maxlength="<?= e((string) $field['max']) ?>"
                       <?php if ($field['auto'] !== ''): ?>autocomplete="<?= e($field['auto']) ?>"<?php endif; ?>>
                <?php if (isset($errors[(string) $name])): ?>
                    <span class="mt-1 block text-bad"><?= e($errors[(string) $name]) ?></span>
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <label class="block text-sm">
            <span class="font-medium">Пароль</span>
            <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password"
                   name="password" required minlength="<?= e((string) SETUP_PASSWORD_MIN) ?>" autocomplete="new-password">
            <?php if (isset($errors['password'])): ?>
                <span class="mt-1 block text-bad"><?= e($errors['password']) ?></span>
            <?php endif; ?>
        </label>

        <label class="block text-sm">
            <span class="font-medium">Пароль ещё раз</span>
            <input class="mt-1 w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password"
                   name="password_again" required autocomplete="new-password">
            <?php if (isset($errors['password_again'])): ?>
                <span class="mt-1 block text-bad"><?= e($errors['password_again']) ?></span>
            <?php endif; ?>
        </label>

        <button class="w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90 disabled:opacity-50"
                type="submit"<?= e($disabled) ?>>Установить</button>
    </form>
</section>
