<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Ведомость: строка items — одна характеристика. title — что перечисляем (заголовок строки),
// text — значение, note — примечание к значению. Портал строил много колоночную таблицу из
// вложенных lines[], а в конструкторе CMS строка плоская (title/text/href/image/note), поэтому
// ведомость двухколоночная с третьей колонкой примечаний, а сравнение планов делает вид
// «Тарифы». Строка без названия не печатается (безымянная ячейка ничего не значит), строка без
// значения и примечания — обрывок. Примечание секции — сноска под ведомостью, как у тарифов.
$rows = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $title = trim((string) ($row['title'] ?? ''));
    $text = trim((string) ($row['text'] ?? ''));
    $note = trim((string) ($row['note'] ?? ''));

    if ($title === '' || ($text === '' && $note === '')) {
        continue;
    }

    $rows[] = ['title' => $title, 'text' => $text, 'note' => $note];
}

$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
?>
<?php if ($rows !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <div class="mt-8 overflow-x-auto rounded-xl border border-ink/10 bg-cream">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-ink/10">
                        <?php foreach ($rows as $row): ?>
                            <tr class="align-top">
                                <th class="p-4 font-semibold" scope="row"><?= e($row['title']) ?></th>
                                <td class="p-4 text-ink/80"><?= e($row['text']) ?></td>
                                <td class="p-4 text-ink/60"><?= e($row['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($note !== ''): ?>
                <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
