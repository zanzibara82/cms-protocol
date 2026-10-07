<?php

declare(strict_types=1);
?>
<footer class="mt-12 border-t border-ink/10 bg-cream">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:px-6">
        <p><?= e((string) configValue('SITE_NAME', 'Школа')) ?> — новости, курсы и объявления школы.</p>
        <nav class="flex flex-wrap gap-x-4 gap-y-2 sm:ml-auto">
            <a class="hover:underline" href="/news">Новости</a>
            <a class="hover:underline" href="/sitemap.xml">Карта сайта</a>
        </nav>
    </div>
    <div class="pb-6 text-center text-xs text-ink/60">Сделано с котом и заботой о языке.</div>
</footer>
