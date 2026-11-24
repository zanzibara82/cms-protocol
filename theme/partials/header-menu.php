<?php

declare(strict_types=1);

/** @var bool $centered */

// Пункты меню шапки. Вариант «минималистичная» этот партиал не зовёт вовсе — навигация тогда
// живёт только в подвале, поэтому страницы её не теряют.
$navClass = 'flex flex-wrap items-center gap-x-4 gap-y-2 text-sm' . ($centered ? ' justify-center' : ' ml-auto');
?>
<nav class="<?= e($navClass) ?>">
    <?php foreach (menuHeaderLinks() as $link): ?>
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e((string) $link['href']) ?>"<?php if ((bool) $link['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e((string) $link['label']) ?></a>
    <?php endforeach; ?>
</nav>
