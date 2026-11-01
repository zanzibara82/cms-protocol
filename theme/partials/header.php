<?php

declare(strict_types=1);

$user = function_exists('currentUser') ? currentUser() : null;
?>
<header class="border-b border-ink/10 bg-cream">
    <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
        <a class="flex min-h-[44px] items-center gap-2 text-lg font-bold" href="/">
            <?= partial('partials/cat', ['mood' => 'happy', 'size' => 32]) /* @safe html */ ?>
            <span><?= e((string) configValue('SITE_NAME', 'Школа')) ?></span>
        </a>
        <nav class="ml-auto flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <?php foreach (menuHeaderLinks() as $link): ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e((string) $link['href']) ?>"<?php if ((bool) $link['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e((string) $link['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($user !== null): ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin">Админка</a>
            <?php else: ?>
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="/login">Вход</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
