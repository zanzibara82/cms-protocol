<?php

declare(strict_types=1);

/**
 * Форма фильтров рубрики: собирает себя из набора характеристик рубрики. Чип выбранных
 * значений снимает один фильтр, остальные условия остаются в адресе страницы.
 *
 * @var array{rows: list<array{id: string, key: string, label: string, type: string, unit: ?string, choices: list<string>, raw: string, from: string, to: string}>, conditions: list<array{attribute_id: string, value: string, from: ?float, to: ?float}>, chips: list<array{label: string, value: string, query: string}>, query: string} $filters
 * @var string $categoryHref
 */
$keptQuery = (string) ($filters['query'] ?? '');
?>
<?php if ((array) $filters['rows'] !== []): ?>
    <form class="space-y-3 rounded-xl border border-ink/10 bg-white p-4" method="get" action="<?= e($categoryHref) ?>">
        <fieldset class="flex flex-wrap items-end gap-3">
            <legend class="text-sm text-ink/60">Фильтры характеристик</legend>
            <?php foreach ((array) $filters['rows'] as $row): ?>
                <?php $key = (string) $row['key']; ?>
                <?php if ((string) $row['type'] === 'CHOICE'): ?>
                    <label class="block" for="f_<?= e($key) ?>">
                        <span class="text-sm text-ink/60"><?= e((string) $row['label']) ?></span>
                        <select class="mt-1 w-full min-h-[44px] rounded-lg border border-ink/20 bg-white px-3 py-2 sm:w-44" id="f_<?= e($key) ?>" name="<?= e($key) ?>">
                            <option value="">— не важно</option>
                            <?php foreach ((array) $row['choices'] as $choice): ?>
                                <option value="<?= e((string) $choice) ?>"<?= e((string) $row['raw'] === (string) $choice ? ' selected' : '') ?>><?= e((string) $choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <?php $unit = $row['unit'] !== null && (string) $row['unit'] !== '' ? ' (' . (string) $row['unit'] . ')' : ''; ?>
                    <span class="block">
                        <span class="text-sm text-ink/60"><?= e((string) $row['label'] . $unit) ?></span>
                        <span class="mt-1 flex gap-2">
                            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="text" inputmode="decimal" name="<?= e($key . '_from') ?>" value="<?= e((string) $row['from']) ?>" placeholder="от" aria-label="<?= e((string) $row['label'] . ' от') ?>">
                            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="text" inputmode="decimal" name="<?= e($key . '_to') ?>" value="<?= e((string) $row['to']) ?>" placeholder="до" aria-label="<?= e((string) $row['label'] . ' до') ?>">
                        </span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
            <span class="flex flex-wrap gap-2">
                <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>
                <?php if ($keptQuery !== ''): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" href="<?= e($categoryHref) ?>">Сбросить</a>
                <?php endif; ?>
            </span>
        </fieldset>
        <?php if ($keptQuery !== ''): ?>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-ink/60">Выбрано:</span>
                <?php foreach ((array) $filters['chips'] as $chip): ?>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm font-medium text-cream" href="<?= e($categoryHref . ((string) $chip['query'] !== '' ? '?' . (string) $chip['query'] : '')) ?>">
                        <?= e((string) $chip['label']) ?>: <?= e((string) $chip['value']) ?> ×
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
