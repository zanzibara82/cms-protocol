<?php

declare(strict_types=1);

/** @var array<string, mixed> $feed */
$pages = (int) $feed['pages'];
$current = (int) $feed['current'];
$query = (string) $feed['q'];
?>
<?php if ($pages > 1): ?>
    <nav aria-label="Страницы материалов">
        <ul class="flex flex-wrap justify-center gap-2">
            <?php foreach ((array) $feed['window'] as $number): ?>
                <li>
                    <?php if ((int) $number === $current): ?>
                        <span aria-current="page" class="inline-flex min-h-[44px] min-w-9 items-center justify-center rounded-lg border border-accent bg-accent/10 px-3 text-sm"><?= e((string) $number) ?></span>
                    <?php else: ?>
                        <a class="inline-flex min-h-[44px] min-w-9 items-center justify-center rounded-lg border border-ink/10 bg-cream px-3 text-sm hover:border-ink/20"
                           href="<?= e(feedHref((string) $feed['base'], ['q' => $query, 'page' => (int) $number])) ?>"><?= e((string) $number) ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
