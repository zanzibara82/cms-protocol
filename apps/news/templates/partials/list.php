<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $feed
 * @var string $empty
 */
?>
<?php if ((array) $feed['items'] === []): ?>
    <p class="rounded-xl border border-ink/10 bg-cream p-8 text-center text-ink/70"><?= e($empty) ?></p>
<?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ((array) $feed['items'] as $card): ?>
            <?= partial('news/partials/card', ['card' => $card]) /* @safe html */ ?>
        <?php endforeach; ?>
    </div>

    <?= partial('news/partials/pager', ['feed' => $feed]) /* @safe html */ ?>
<?php endif; ?>
