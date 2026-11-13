<?php

declare(strict_types=1);

/** @var string $token */
/** @var string $email */
/** @var string $error */
?>
<section class="mx-auto w-full max-w-sm">
    <?= partial('partials/breadcrumbs', ['items' => [['label' => 'Главная', 'href' => '/'], ['label' => 'Новый пароль', 'href' => '']]]) /* @safe html */ ?>
    <div class="mt-4 rounded-xl border border-ink/10 bg-white p-6">
        <h1 class="text-2xl font-bold">Новый пароль</h1>
        <p class="mt-2 text-sm text-ink/70">Пароль для <?= e($email) ?>. Не короче 10 символов; после сохранения все прежние сеансы завершатся.</p>

        <?php if ($error !== ''): ?>
            <p class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form class="mt-6 space-y-4" method="post" action="/reset-password/<?= e($token) ?>">
            <?= csrfField() /* @safe html */ ?>
            <label class="block text-sm">
                <span class="font-medium">Новый пароль</span>
                <input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password" name="password"
                       autocomplete="new-password" minlength="10" maxlength="200" required>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Повторите пароль</span>
                <input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password" name="password_confirm"
                       autocomplete="new-password" minlength="10" maxlength="200" required>
            </label>
            <button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90" type="submit">Сменить пароль</button>
        </form>

        <p class="mt-4 text-sm"><a class="inline-flex min-h-[44px] items-center text-ink/70 underline hover:text-ink" href="/login">Вернуться ко входу</a></p>
    </div>
</section>
