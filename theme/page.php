<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $sections */
/** @var array<string, mixed> $page */
/** @var bool $preview */

// Секции собираются диспетчером: известные виды — шаблоны ядра, чужие — вклады приложений.
$content = '';
foreach ($sections as $section) {
    $content .= sectionHtml($section);
}
?>
<?php if ($content !== ''): ?><?= $content /* @safe html */ ?><?php endif; ?>
<?php if ($content === ''): ?>
<section class="py-12">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?php if ($preview): ?>
            <p class="text-sm font-semibold uppercase tracking-wide text-ink/60">Предпросмотр</p>
        <?php endif; ?>
        <h1 class="text-2xl font-bold sm:text-3xl"><?= e((string) $page['title']) ?></h1>
        <p class="mt-3 max-w-2xl text-ink/70">На этой странице пока нет блоков.</p>
    </div>
</section>
<?php endif; ?>
