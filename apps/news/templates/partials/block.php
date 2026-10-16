<?php

declare(strict_types=1);

/**
 * Блок вывода на публичной странице: заголовок и сетка карточек. Карточка — тот же partial,
 * что печатает лента, поэтому вид подборки и вид списка не расъезжаются при правке одной из них.
 *
 * Классы берутся из разметки ленты и карточки: нового токена здесь нет, и артефакт стилей
 * остаётся свежим (`StyleSourceTest` проверяет, что каждый класс разметки лежит в артефакте).
 *
 * @var string $title
 * @var list<array<string, mixed>> $cards
 */
?>
<div class="space-y-4">
    <h2 class="text-xl font-semibold"><?= e($title) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($cards as $card): ?>
            <?= partial('news/partials/card', ['card' => $card]) /* @safe html */ ?>
        <?php endforeach; ?>
    </div>
</div>
