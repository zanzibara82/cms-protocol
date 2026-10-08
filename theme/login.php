<?php

declare(strict_types=1);

/** @var string $error */
/** @var string $email */
/** @var string $next */
?>
<section class="mx-auto max-w-md py-10">
    <h1 class="text-2xl font-bold">Вход для редактора</h1>
    <p class="mt-2 text-sm text-ink/70">Раздел открыт администратору и редактору контента.</p>

    <?php if ($error !== ''): ?>
        <p class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="mt-6 space-y-4" method="post" action="/login">
        <?= csrfField() /* @safe html */ ?>
        <?php if ($next !== ''): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>
        <label class="block text-sm">
            <span class="font-medium">Почта</span>
            <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="email" name="email"
                   value="<?= e($email) ?>" autocomplete="username" required maxlength="190">
        </label>
        <label class="block text-sm">
            <span class="font-medium">Пароль</span>
            <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password" name="password"
                   autocomplete="current-password" required>
        </label>
        <button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90" type="submit">Войти</button>
    </form>
</section>
