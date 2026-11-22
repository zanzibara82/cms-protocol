<?php

declare(strict_types=1);

// Боковая панель каркаса. Это не новый вид контента и не отдельный конструктор: навигация
// раздела — те же пункты меню шапки, положенные колонкой. Пустой список не печатает ничего:
// страница с макетом «сайдбар» и без пунктов меню не обрастает пустой рамкой (решение 5
// спецификации про пустые зоны).

$links = function_exists('menuHeaderLinks') ? menuHeaderLinks() : [];
?>
<?php if ($links !== []): ?>
<nav class="rounded-xl border border-ink/10 bg-white p-4" aria-label="Разделы сайта">
    <p class="text-sm font-semibold uppercase tracking-wide text-ink/60">Разделы</p>
    <ul class="mt-3 space-y-1">
        <?php foreach ($links as $link): ?>
            <li>
                <a class="inline-flex min-h-[44px] w-full items-center rounded-lg px-2 text-sm hover:bg-ink/5" href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php endif; ?>
