<?php

declare(strict_types=1);

/**
 * Панель фильтров экрана статистики: период и источник. Форма уходит методом GET — фильтр
 * ничего не меняет на сервере, а значит, и токен ему не нужен.
 *
 * @var string $base
 * @var int $days
 * @var string $source
 */
$periodOptions = '';

foreach (SEO_STATS_PERIODS as $period) {
    $label = $period === 7 ? '7 дней' : ($period === 30 ? '30 дней' : '90 дней');
    $periodOptions .= '<option value="' . e((string) $period) . '"' . ($days === $period ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

// Пустой источник — это «все источники», а не «источник не выбран»: без отметки форма
// показывала бы поле без выбранного варианта, и человек не понял бы, какой фильтр работает.
$sourceOptions = '<option value=""' . ($source === '' ? ' selected' : '') . '>Все источники</option>';

foreach (SEO_SOURCES as $value => $label) {
    $sourceOptions .= '<option value="' . e((string) $value) . '"' . ($source === $value ? ' selected' : '') . '>'
        . e($label) . '</option>';
}

$content = '<form class="flex flex-wrap items-end gap-2" method="get" action="' . e($base) . '">'
    . '<label class="text-sm text-ink/60" for="stats_days">Период</label>'
    . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="stats_days" name="days">'
    . $periodOptions . '</select>'
    . '<label class="text-sm text-ink/60" for="stats_source">Источник</label>'
    . '<select class="min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2" id="stats_source" name="source">'
    . $sourceOptions . '</select>'
    . '<button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>'
    . '</form>';
?>
<?= $content /* @safe html */ ?>
