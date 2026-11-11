<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Поделиться: обычные ссылки на сети и мессенджеры, без скрипта и внешних библиотек. Адрес
// страницы берётся из SITE_URL и текущего пути: строка запроса и якорь в него не попадают,
// поэтому получатель видит ту же страницу, а не результат поиска или переход по оглавлению.
// Внешние кнопки открываются в новой вкладке, письмо — в почтовом клиенте того же окна.
$links = pagesShareLinks(absoluteUrl(requestPath()));
$anchor = (string) $section['anchor'];
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
        <ul class="mt-8 flex flex-wrap gap-3">
            <?php foreach ($links as $link): ?>
                <?php $external = str_starts_with($link['href'], 'https://'); ?>
                <li>
                    <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/10 bg-cream px-4 py-2 text-sm font-semibold hover:border-ink/30 hover:underline" href="<?= e($link['href']) ?>"<?php if ($external): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e($link['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
