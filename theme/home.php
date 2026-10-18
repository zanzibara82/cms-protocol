<?php

declare(strict_types=1);
?>
<section class="py-8">
    <h1 class="text-3xl font-bold"><?= e((string) configValue('SITE_NAME', 'Школа')) ?></h1>
    <p class="mt-3 max-w-2xl text-ink/70">
        Сайт собирается на собственной CMS: ядро отвечает за маршруты, доступ и обновления, а разделы живут
        в приложениях.
    </p>
    <p class="mt-6"><a class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-cream hover:opacity-90" href="/news">Читать новости</a></p>
<?php region('home'); ?>
</section>
