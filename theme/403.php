<?php

declare(strict_types=1);

/** @var string $message */
?>
<section class="py-12 text-center">
    <?= partial('partials/cat', ['mood' => 'sad', 'size' => 96]) /* @safe html */ ?>
    <h1 class="mt-6 text-3xl font-bold">Сюда нельзя</h1>
    <p class="mt-3 text-ink/70">
        <?= e($message !== '' ? $message : 'Раздел открыт только редактору и администратору.') ?>
    </p>
    <p class="mt-6"><a class="rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="/login">Войти</a></p>
</section>
