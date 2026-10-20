<?php

declare(strict_types=1);

/**
 * Панель поиска и фильтров ленты: слова, характеристики ветки, сортировка и вид
 * сохраняются между применениями. Без JavaScript — обычная GET-форма.
 *
 * @var list<array{id: string, category_id: string, key_slug: string, title: string, kind: string, unit: string, options: list<string>, required: bool, filterable: bool, order_index: int}> $attributes
 * @var array{q: string, sort: string, view: string, filters: array<string, array{kind: string, value?: string, min?: string, max?: string}>} $state
 * @var string $base
 */
?>
<form class="space-y-4 rounded-xl border border-ink/10 bg-white p-4" method="get" action="<?= e($base) ?>">
    <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-56 flex-1" for="feed-q">
            <span class="mb-1 block text-sm text-ink/70">Слова</span>
            <input class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="feed-q" name="q"
                   maxlength="<?= e((string) CLASSIFIEDS_SEARCH_MAX) ?>" value="<?= e($state['q']) ?>" placeholder="Например, стол">
        </label>
        <input type="hidden" name="sort" value="<?= e($state['sort']) ?>">
        <input type="hidden" name="view" value="<?= e($state['view']) ?>">
        <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать</button>
        <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" href="<?= e($base) ?>">Сбросить</a>
    </div>

    <?php if ($attributes !== []): ?>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($attributes as $attribute): ?>
                <?php
                if (!$attribute['filterable']) {
                    continue;
                }
                $id = (string) $attribute['id'];
                $filter = $state['filters'][$id] ?? null;
                ?>
                <?php if ($attribute['kind'] === 'number'): ?>
                    <fieldset>
                        <legend class="mb-1 block text-sm text-ink/70"><?= e((string) $attribute['title']) ?><?php if ($attribute['unit'] !== ''): ?>, <?= e((string) $attribute['unit']) ?><?php endif; ?></legend>
                        <div class="flex gap-2">
                            <label class="block flex-1"><span class="sr-only">От</span>
                                <input class="min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2 tabular-nums" name="f[<?= e($id) ?>][min]"
                                       inputmode="decimal" value="<?= e((string) ($filter['min'] ?? '')) ?>" placeholder="от"></label>
                            <label class="block flex-1"><span class="sr-only">До</span>
                                <input class="min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2 tabular-nums" name="f[<?= e($id) ?>][max]"
                                       inputmode="decimal" value="<?= e((string) ($filter['max'] ?? '')) ?>" placeholder="до"></label>
                        </div>
                    </fieldset>
                <?php elseif ($attribute['kind'] === 'select' || $attribute['kind'] === 'bool'): ?>
                    <?php
                    $choices = $attribute['kind'] === 'bool' ? ['1' => 'Да', '0' => 'Нет'] : [];

                    if ($attribute['kind'] === 'select') {
                        foreach ($attribute['options'] as $choice) {
                            $choices[$choice] = $choice;
                        }
                    }
                    ?>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink/70"><?= e((string) $attribute['title']) ?></span>
                        <select class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" name="f[<?= e($id) ?>][value]">
                            <option value="">— любой —</option>
                            <?php foreach ($choices as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>"<?php if ((string) ($filter['value'] ?? '') === (string) $value): ?> selected<?php endif; ?>><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink/70"><?= e((string) $attribute['title']) ?></span>
                        <input class="min-h-[44px] w-full rounded-lg border border-ink/20 px-3 py-2" name="f[<?= e($id) ?>][value]"
                               maxlength="200" value="<?= e((string) ($filter['value'] ?? '')) ?>">
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</form>
