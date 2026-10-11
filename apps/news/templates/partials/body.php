<?php

declare(strict_types=1);

/** @var list<array{title: string, text: string}> $paragraphs */
?>
<div class="space-y-5">
    <?php foreach ((array) $paragraphs as $paragraph): ?>
        <?php if ((string) $paragraph['title'] !== ''): ?>
            <h2 class="text-xl font-semibold break-words"><?= e((string) $paragraph['title']) ?></h2>
        <?php endif; ?>
        <p class="leading-relaxed break-words"><?= richText((string) $paragraph['text']) /* @safe html */ ?></p>
    <?php endforeach; ?>
</div>
