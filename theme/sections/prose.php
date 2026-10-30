<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Текстовый блок: шапка (надзаголовок, заголовок, абзацы лида) живёт в общем частичном,
// примечание печатается после абзацев мелким текстом.
$anchor = (string) $section['anchor'];
$note = (string) $section['note'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <?php if ($note !== ''): ?>
            <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
        <?php endif; ?>
    </div>
</section>
