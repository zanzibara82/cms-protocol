<?php

declare(strict_types=1);

$user = function_exists('currentUser') ? currentUser() : null;
// Текст и логотип шапки правятся на /admin/settings; пустая опция — знак сайта без подписи.
$headerLogo = function_exists('siteSettingValue') ? siteSettingValue('header_logo') : '';
$headerText = function_exists('siteSettingValue') ? siteSettingValue('header_text') : (string) configValue('SITE_NAME', 'Школа');

// Вариант шапки — набор флагов, а не отдельный шаблон: полоса одна, меняется раскладка и состав.
// Значение пришло из опции, поэтому проверяется правилом headerPlan(), а не доверяется как есть.
$headerVariant = function_exists('siteSettingValue') ? siteSettingValue('header_variant') : HEADER_VARIANT_DEFAULT;
$plan = function_exists('headerPlan')
    ? headerPlan($headerVariant)
    : ['variant' => 'standard', 'menu' => true, 'centered' => false, 'top' => false];

$menu = $plan['menu'];
$centered = $plan['centered'];
$top = $plan['top'];
// Верхняя полоса: подпись сайта мелким кеглем над шапкой. Пустой текст полосу не рисует —
// иначе шапка обрастала бы пустой рамкой на свежем сайте.
$topline = function_exists('siteSettingValue') ? siteSettingValue('footer_copyright') : '';

// Строка шапки: состав и раскладка зависят от варианта, но собирается всё через партиалы.
// Прямые переменные с разметкой здесь не печатаются — страж экранирования разрешает под
// пометкой только агрегаторы и вызовы partial().
$rowClass = 'mx-auto flex w-full max-w-5xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6' . ($centered ? ' justify-center' : '');
?>
<header class="border-b border-ink/10 bg-cream">
    <?php if ($top && $topline !== ''): ?>
        <div class="border-b border-ink/10 bg-ink/5">
            <div class="mx-auto w-full max-w-5xl px-4 py-1 text-xs text-ink/70 sm:px-6"><?= e($topline) ?></div>
        </div>
    <?php endif; ?>
    <div class="<?= e($rowClass) ?>">
        <?= partial('partials/header-brand', ['logo' => $headerLogo, 'text' => $headerText]) /* @safe html */ ?>
        <?php if ($centered): ?>
            <?= partial('partials/header-account', ['centered' => true]) /* @safe html */ ?>
            <?php if ($menu): ?>
                <div class="w-full"><?= partial('partials/header-menu', ['centered' => true]) /* @safe html */ ?></div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($menu): ?>
                <?= partial('partials/header-menu', ['centered' => false]) /* @safe html */ ?>
            <?php endif; ?>
            <?= partial('partials/header-account', ['centered' => false]) /* @safe html */ ?>
        <?php endif; ?>
    </div>
</header>
