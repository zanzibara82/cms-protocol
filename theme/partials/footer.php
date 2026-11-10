<?php

declare(strict_types=1);

// Подвал: колонки пунктов читаются из базы (menuFooterSections), текст, логотип и копирайт — из
// настроек шапки/подвала (/admin/settings). Пустое меню день-1 печатает только брендовый ряд:
// колонок нет, заглушка не рисуется.
$footerSections = menuFooterSections();
$footerLogo = function_exists('siteSettingValue') ? siteSettingValue('footer_logo') : '';
$footerText = function_exists('siteSettingValue')
    ? siteSettingValue('footer_text')
    : (string) configValue('SITE_NAME', 'Школа') . ' — новости, курсы и объявления школы.';
$footerCopyright = function_exists('siteSettingValue')
    ? siteSettingValue('footer_copyright')
    : 'Сделано с котом и заботой о языке.';
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
            <?php if ($footerLogo !== ''): ?>
                <img class="h-8 w-auto" src="<?= e($footerLogo) ?>" alt="">
            <?php endif; ?>
            <?php if ($footerText !== ''): ?>
                <p><?= e($footerText) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($footerCopyright !== ''): ?>
        <div class="pb-6 text-center text-xs text-ink/60"><?= e($footerCopyright) ?></div>
    <?php endif; ?>
</footer>
