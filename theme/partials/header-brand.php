<?php

declare(strict_types=1);

/** @var string $logo */
/** @var string $text */

// Знак сайта: логотип из настроек или брендовый кот. Класс печатается литералом: страж зон
// нажатия читает классы значения атрибута и за переменной min-h-[44px] не видит. Центрирование
// задаёт строка шапки, а не сама ссылка: у центрированной кнопки страж требует и min-w-[44px],
// а знак сайта шириной в текст этим требованием не является.
?>
<a class="flex min-h-[44px] items-center gap-2 text-lg font-bold" href="/" aria-label="На главную">
    <?php if ($logo !== ''): ?>
        <img class="h-8 w-auto" src="<?= e($logo) ?>" alt="">
    <?php else: ?>
        <?= partial('partials/cat', ['mood' => 'happy', 'size' => 32]) /* @safe html */ ?>
    <?php endif; ?>
    <?php if ($text !== ''): ?>
        <span><?= e($text) ?></span>
    <?php endif; ?>
</a>
