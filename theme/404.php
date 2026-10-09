<?php

declare(strict_types=1);

/** @var string $message */
?>
<section class="py-12 text-center">
    <?= partial('partials/cat', ['mood' => 'sad', 'size' => 96]) /* @safe html */ ?>
    <h1 class="mt-6 text-3xl font-bold">Страницы нет</h1>
    <p class="mt-3 text-ink/70">
        <?php if (($message ?? '') !== ''): ?>
            <?= e((string) $message) ?>
        <?php else: ?>
            Адрес мог измениться или в нём опечатка. Загляните в ленту новостей — возможно, материал переехал.
        <?php endif; ?>
    </p>
    <p class="mt-6 flex flex-wrap justify-center gap-3">
        <a class="rounded-lg bg-ink px-4 py-3 text-cream hover:opacity-90" href="/">На главную</a>
        <a class="rounded-lg border border-ink/20 px-4 py-3 hover:bg-cream" href="/news">К новостям</a>
    </p>
</section>
