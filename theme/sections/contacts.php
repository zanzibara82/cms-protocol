<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Контакты: строка items — один способ связи. title — подпись («Телефон», «Адрес»), text —
// значение, href — адрес (если задан, значение становится ссылкой — звонок, письмо, карта),
// note — примечание. Строка без значения не печатается: подпись без телефона или адреса ничего
// не сообщает. Примечание секции — сноска под списком (часы работы, порядок ответа).
$contacts = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $text = trim((string) ($row['text'] ?? ''));
    $href = trim((string) ($row['href'] ?? ''));

    if ($text === '' && $href === '') {
        continue;
    }

    $contacts[] = [
        'title' => trim((string) ($row['title'] ?? '')),
        'text' => $text,
        'href' => $href,
        'note' => trim((string) ($row['note'] ?? '')),
    ];
}

$note = trim((string) ($section['note'] ?? ''));
$anchor = (string) $section['anchor'];
?>
<?php if ($contacts !== []): ?>
    <section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
            <ul class="mt-8 grid gap-4 sm:grid-cols-2">
                <?php foreach ($contacts as $contact): ?>
                    <li class="rounded-xl border border-ink/10 bg-cream p-5">
                        <?php if ($contact['title'] !== ''): ?>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-ink/60"><?= e($contact['title']) ?></span>
                        <?php endif; ?>
                        <?php if ($contact['href'] !== ''): ?>
                            <a class="mt-1 inline-flex min-h-[44px] items-center break-words font-medium text-accent underline-offset-4 hover:underline" href="<?= e($contact['href']) ?>"><?= e($contact['text'] !== '' ? $contact['text'] : $contact['href']) ?></a>
                        <?php else: ?>
                            <span class="mt-1 block break-words font-medium"><?= e($contact['text']) ?></span>
                        <?php endif; ?>
                        <?php if ($contact['note'] !== ''): ?>
                            <span class="mt-1 block text-sm text-ink/50"><?= e($contact['note']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($note !== ''): ?>
                <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
