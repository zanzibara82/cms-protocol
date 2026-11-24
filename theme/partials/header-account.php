<?php

declare(strict_types=1);

/** @var bool $centered */

// Кнопка входа печатается всегда: без неё посетитель не попадёт в админку с телефона.
$user = function_exists('currentUser') ? currentUser() : null;
$blockClass = 'flex items-center text-sm' . ($centered ? ' justify-center' : ' ml-auto');
?>
<div class="<?= e($blockClass) ?>">
    <?php if ($user !== null): ?>
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/admin">Админка</a>
    <?php else: ?>
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/login">Вход</a>
    <?php endif; ?>
</div>
