<?php

declare(strict_types=1);

// Обёртка точки вывода. Разметка живёт здесь, а не в коде `region()`: сборка стилей читает
// каталог `core/templates` (страж `StyleSourceTest::MARKUP_DIRS`), а литеральный `class=` внутри
// `core/template.php` краснеет в его же проверке «разметка не растёт вне объявленных каталогов».
/** @var string $zone */
/** @var string $content */
?>
<section class="region region-<?= e($zone) ?>">
<?= $content /* @safe html */ ?>
</section>
