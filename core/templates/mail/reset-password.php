<?php

declare(strict_types=1);

// Письмо со ссылкой сброса пароля. Разметка лежит в core/templates, а не в core/: сборка стилей
// читает только объявленные каталоги, а страж зон нажатия — каждую ссылку дерева. У почтового
// клиента нашего stylesheet нет, поэтому оформление задано inline: классы здесь инертны.
/**
 * @var string $url
 * @var string $name
 * @var string $brand
 */
?>
<div style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:16px;line-height:1.55;color:#1f2937;max-width:560px">
    <p style="margin:0 0 14px"><?= e('Здравствуйте, ' . $name . '!') ?></p>
    <p style="margin:0 0 14px"><?= e('Вы запросили сброс пароля на сайте ' . $brand . '. Ссылка действует час.') ?></p>
    <p style="margin:0 0 14px"><a class="inline-flex min-h-[44px] items-center" href="<?= e($url) ?>">Сменить пароль</a></p>
    <p style="margin:26px 0 0;font-size:13px;color:#6b7280"><?= e('Если вы не запрашивали сброс, просто удалите письмо — пароль останется прежним.') ?></p>
</div>
