<?php

declare(strict_types=1);

/** @var string $mood */
/** @var int $size */
$file = ($mood ?? 'happy') === 'sad' ? 'cat-sad.svg' : 'cat-happy.svg';
$size = (int) ($size ?? 40);
?>
<img src="/assets/<?= e($file) ?>" width="<?= e((string) $size) ?>" height="<?= e((string) $size) ?>" alt="Кот-талисман школы" loading="lazy">
