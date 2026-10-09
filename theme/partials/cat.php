<?php

declare(strict_types=1);

/** @var string $mood */
/** @var int $size */
/** @var bool $lazy */

$mood = ($mood ?? 'happy') === 'sad' ? 'sad' : 'happy';
$file = $mood === 'sad' ? 'cat-sad.png' : 'cat-happy.png';
$width = max(1, (int) ($size ?? 40));

// height считается от настоящих пропорций файла: грустный кот не квадратный, и при height === width
// он на экранах отказа сплюснут по вертикали. Если файл не читается, остаётся квадрат — место под
// картинку всё равно зарезервировано, и вёрстка не едет.
$natural = imageNaturalSize('public/assets/' . $file);
$height = $natural[0] > 0 ? (int) round($width * $natural[1] / $natural[0]) : $width;
$lazy = (bool) ($lazy ?? false);
?>
<img src="/assets/<?= e($file) ?>" width="<?= e((string) $width) ?>" height="<?= e((string) $height) ?>"
     alt="Кот-талисман школы"<?php if ($lazy): ?> loading="lazy" decoding="async"<?php endif; ?>>
