<?php

declare(strict_types=1);

/**
 * Профиль продавца: имя и контакты. Телефон публикуется только с согласия,
 * почта аккаунта здесь не редактируется и нигде не выводится.
 *
 * @var array{name: string, phone: string, city: string, about: string, show_phone: int} $form
 * @var array<string, string> $errors
 * @var string $notice
 * @var string $publicUrl
 */
?>
<section class="mx-auto max-w-2xl space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Профиль продавца</h1>
        <p class="text-ink/70">Покупатели увидят имя, город и описание. Телефон показывается, только если вы разрешите.</p>
        <a class="inline-flex min-h-[44px] items-center text-sm underline underline-offset-4 hover:text-accent"
           href="<?= e($publicUrl) ?>">Открыть публичную страницу</a>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <form class="space-y-5 rounded-xl border border-ink/10 bg-white p-5" method="post" action="/classifieds/cabinet/profile">
        <?= csrfField() ?>

        <label class="block" for="name">
            <span class="mb-1 block text-sm text-ink/70">Имя продавца</span>
            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="name"
                   name="name" value="<?= e($form['name']) ?>" maxlength="<?= e((string) CLASSIFIEDS_NAME_MAX) ?>" required>
            <?php if (isset($errors['name'])): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($errors['name']) ?></span>
            <?php endif; ?>
        </label>

        <label class="block" for="phone">
            <span class="mb-1 block text-sm text-ink/70">Телефон</span>
            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="phone"
                   name="phone" value="<?= e($form['phone']) ?>" maxlength="<?= e((string) CLASSIFIEDS_PHONE_MAX) ?>">
            <?php if (isset($errors['phone'])): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($errors['phone']) ?></span>
            <?php endif; ?>
        </label>

        <label class="flex min-h-[44px] items-center gap-3 text-sm">
            <input class="h-5 w-5 min-h-[44px]" type="checkbox" name="show_phone" value="1"<?php if ($form['show_phone'] === 1): ?> checked<?php endif; ?>>
            <span>Показывать телефон покупателям</span>
        </label>

        <label class="block" for="city">
            <span class="mb-1 block text-sm text-ink/70">Город</span>
            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="city"
                   name="city" value="<?= e($form['city']) ?>" maxlength="<?= e((string) CLASSIFIEDS_CITY_MAX) ?>">
            <?php if (isset($errors['city'])): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($errors['city']) ?></span>
            <?php endif; ?>
        </label>

        <label class="block" for="about">
            <span class="mb-1 block text-sm text-ink/70">О продавце</span>
            <textarea class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="about"
                      name="about" rows="6"><?= e($form['about']) ?></textarea>
            <?php if (isset($errors['about'])): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($errors['about']) ?></span>
            <?php endif; ?>
        </label>

        <div class="flex flex-wrap gap-2">
            <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"
                    type="submit">Сохранить профиль</button>
            <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5"
               href="/classifieds/cabinet">В кабинет</a>
        </div>
    </form>
</section>
