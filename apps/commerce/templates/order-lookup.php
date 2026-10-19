<?php

declare(strict_types=1);

/**
 * Состояние заказа для гостя: номер и e-mail из письма открывают подтверждение.
 *
 * @var array{number: string, customer_email: string} $values
 * @var array<string, string> $errors
 * @var string $notice
 */
$formError = (string) ($errors['form'] ?? '');
?>
<section class="space-y-6">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Состояние заказа</h1>
        <p class="text-ink/70">Номер заказа из письма и адрес e-mail, на который оно приходило. «№» и лишние пробелы в номере не мешают.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
        <p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?= e($formError) ?></p>
    <?php endif; ?>

    <form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/shop/order/lookup">
        <?= csrfField() /* @safe html */ ?>

        <?php $error = (string) ($errors['number'] ?? ''); ?>
        <label class="block" for="number">
            <span class="text-sm text-ink/60">Номер заказа</span>
            <input class="mt-1 w-full min-h-[44px] rounded-lg border <?= e($error !== '' ? 'border-red-400' : 'border-ink/20') ?> px-3 py-2" id="number" name="number" type="text" inputmode="numeric" value="<?= e((string) ($values['number'] ?? '')) ?>">
            <?php if ($error !== ''): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($error) ?></span>
            <?php endif; ?>
        </label>

        <?php $error = (string) ($errors['customer_email'] ?? ''); ?>
        <label class="block" for="customer_email">
            <span class="text-sm text-ink/60">E-mail</span>
            <input class="mt-1 w-full min-h-[44px] rounded-lg border <?= e($error !== '' ? 'border-red-400' : 'border-ink/20') ?> px-3 py-2" id="customer_email" name="customer_email" type="email" value="<?= e((string) ($values['customer_email'] ?? '')) ?>">
            <?php if ($error !== ''): ?>
                <span class="mt-1 block text-sm text-red-700"><?= e($error) ?></span>
            <?php endif; ?>
        </label>

        <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Показать заказ</button>
    </form>

    <p class="text-sm text-ink/60">
        Нет под рукой письма? <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="/shop">Загляните в каталог</a> и оформите новый.
    </p>
</section>
