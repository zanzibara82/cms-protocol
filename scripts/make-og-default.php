<?php

declare(strict_types=1);

// Заготовка фирменного превью 1200×630. Раздел обязан отдавать картинку даже у материала
// без обложки (§8.6), а требовать GD от виртуального хостинга нельзя: рисунок создаётся
// один раз здесь и попадает в репозиторий готовым файлом.
// Запуск: docker compose -f docker-compose.dev.yml run --rm -T php php scripts/make-og-default.php

$target = __DIR__ . '/../public/assets/og-default.png';

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, 'GD не установлен — перерисовать файл нечем.' . PHP_EOL);
    exit(1);
}

$image = imagecreatetruecolor(1200, 630);
if (!$image instanceof GdImage) {
    fwrite(STDERR, 'Холст не создан.' . PHP_EOL);
    exit(1);
}

// Три цвета темы из src-css/app.css: бумага, accent-полоса внизу и силуэт кота цветом ink.
$paper = imagecolorallocate($image, 0xfd, 0xf6, 0xec);
$accent = imagecolorallocate($image, 0xd9, 0x77, 0x06);
$ink = imagecolorallocate($image, 0x2b, 0x21, 0x18);

imagefilledrectangle($image, 0, 0, 1199, 629, $paper);
imagefilledrectangle($image, 0, 566, 1199, 629, $accent);

// Надписи нет намеренно: шрифт в контейнере не установлен, а название раздела несёт шаблон.
imagefilledellipse($image, 880, 470, 260, 200, $ink);
imagefilledellipse($image, 880, 320, 170, 160, $ink);
imagefilledpolygon($image, [815, 265, 830, 195, 875, 255], $ink);
imagefilledpolygon($image, [945, 265, 930, 195, 885, 255], $ink);
imagefilledellipse($image, 700, 500, 200, 40, $ink);

imagepng($image, $target);
imagedestroy($image);

echo 'Готово: ' . $target . PHP_EOL;
