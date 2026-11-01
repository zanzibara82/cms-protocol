<?php

declare(strict_types=1);

// Подвал: колонки пунктов читаются из базы (menuFooterSections), «Карта сайта» остаётся
// статикой — адрес машины состояния, не контента. Пустое меню день-1 печатает только
// брендовый ряд: колонок нет, заглушка не рисуется.
$footerSections = menuFooterSections();
?>
<footer class="mt-12 border-t border-ink/10 bg-cream">
    <div class="mx-auto w-full max-w-5xl px-4 py-8 text-sm sm:px-6">
        <?php if ($footerSections !== []): ?>
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <?php foreach ($footerSections as $section): ?>
                    <div class="space-y-2">
                        <h3 class="font-semibold"><?= e((string) $section['title']) ?></h3>
                        <nav class="flex flex-col">
                            <?php foreach ($section['links'] as $link): ?>
                                <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e((string) $link['href']) ?>"<?php if ((bool) $link['blank']): ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?= e((string) $link['label']) ?></a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
        <?php else: ?>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
        <?php endif; ?>
            <p><?= e((string) configValue('SITE_NAME', 'Школа')) ?> — новости, курсы и объявления школы.</p>
            <nav class="flex flex-wrap gap-x-4 gap-y-2 sm:ml-auto">
                <a class="inline-flex min-h-[44px] items-center hover:underline" href="/sitemap.xml">Карта сайта</a>
            </nav>
        </div>
    </div>
    <div class="pb-6 text-center text-xs text-ink/60">Сделано с котом и заботой о языке.</div>
</footer>
