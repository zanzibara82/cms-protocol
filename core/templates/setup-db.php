<?php

declare(strict_types=1);

/** @var array<string, string> $errors */
/** @var array<string, string> $values */

$fields = [
    'db_host' => ['label' => 'Хост базы', 'type' => 'text', 'max' => 190, 'auto' => ''],
    'db_port' => ['label' => 'Порт базы', 'type' => 'number', 'max' => 5, 'auto' => ''],
    'db_name' => ['label' => 'Имя базы', 'type' => 'text', 'max' => 190, 'auto' => ''],
    'db_user' => ['label' => 'Пользователь базы', 'type' => 'text', 'max' => 190, 'auto' => 'username'],
    'table_prefix' => ['label' => 'Префикс таблиц', 'type' => 'text', 'max' => 30, 'auto' => ''],
    'site_url' => ['label' => 'Адрес сайта', 'type' => 'text', 'max' => 190, 'auto' => 'url'],
    'site_name' => ['label' => 'Название сайта', 'type' => 'text', 'max' => 120, 'auto' => ''],
];
?>
<section class="mx-auto max-w-xl py-10">
    <h1 class="text-2xl font-bold">Установка сайта</h1>
    <p class="mt-2 text-sm text-ink/70">Шаг первый из двух: здесь появляются параметры базы и адрес сайта.</p>

    <?php if (($errors['form'] ?? '') !== ''): ?>
        <div class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">
            <?= e((string) $errors['form']) ?>
        </div>
    <?php endif; ?>

    <form class="mt-6 space-y-4" method="post" action="/setup.php?step=db">
        <?php /* @safe html */ ?><?= csrfField() ?>

        <?php foreach ($fields as $name => $field): ?>
            <label class="block text-sm">
                <span class="font-medium"><?= e($field['label']) ?></span>
                <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2"
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
            <span class="font-medium">Пароль базы</span>
            <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password"
                   name="db_pass" value="" autocomplete="new-password">
            <?php if (isset($errors['db_pass'])): ?>
                <span class="mt-1 block text-bad"><?= e($errors['db_pass']) ?></span>
            <?php endif; ?>
        </label>

        <p class="mt-2 text-sm text-ink/70">Этот экран собирает только базу и адрес сайта. Сервер почты (SMTP) и остальные ключи настраиваются вручную в файле настроек.</p>

        <button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90 disabled:opacity-50"
                type="submit">Продолжить</button>
    </form>
</section>
