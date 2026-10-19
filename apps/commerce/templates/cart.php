<?php

declare(strict_types=1);

/**
 * Корзина и оформление: один экран, без JavaScript.
 *
 * @var list<array{id: string, slug: string, title: string, variant_title: string, price_cents: int, currency: string, qty: int, stock: int, line_cents: int}> $lines
 * @var int $totalCents
 * @var int $cartCount
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var string $notice
 */
$currency = $lines !== [] ? (string) $lines[0]['currency'] : 'RUB';
$formError = (string) ($errors['form'] ?? '');
?>
<section class="space-y-8">
    <header class="space-y-2">
        <h1 class="text-3xl font-bold">Корзина</h1>
        <p class="text-ink/70"><?= e($cartCount > 0 ? 'Позиций: ' . $cartCount : 'Корзина пуста') ?></p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($formError !== ''): ?>
        <p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"><?= e($formError) ?></p>
    <?php endif; ?>

    <?php if ($lines === []): ?>
        <p class="rounded-lg border border-ink/10 bg-cream p-6 text-sm text-ink/70">
            Положите товары из <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-ink" href="/shop">каталога</a>.
        </p>
    <?php else: ?>
        <ul class="space-y-4">
            <?php foreach ($lines as $line): ?>
                <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink/10 bg-white p-4">
                    <div class="min-w-0">
                        <a class="inline-flex min-h-[44px] items-center font-semibold hover:underline" href="<?= e('/shop/' . rawurlencode((string) $line['slug'])) ?>"><?= e((string) $line['title']) ?></a>
                        <?php if ((string) $line['variant_title'] !== ''): ?>
                            <p class="text-sm font-medium"><?= e((string) $line['variant_title']) ?></p>
                        <?php endif; ?>
                        <p class="text-sm text-ink/60">
                            <?= e(commerceFormatMoney((int) $line['price_cents'], (string) $line['currency'])) ?> × <?= e((string) $line['qty']) ?>
                            <?php if ($line['qty'] >= $line['stock']): ?>
                                · столько в наличии
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <form class="flex items-end gap-2" method="post" action="/shop/cart/update">
                            <?= csrfField() /* @safe html */ ?>
                            <input type="hidden" name="op" value="set">
                            <input type="hidden" name="id" value="<?= e((string) $line['id']) ?>">
                            <label class="text-sm text-ink/60" for="qty-<?= e((string) $line['id']) ?>">Штук</label>
                            <input class="min-h-[44px] w-20 rounded-lg border border-ink/20 px-3 py-2" id="qty-<?= e((string) $line['id']) ?>" name="qty" type="number" min="0" max="<?= e((string) $line['stock']) ?>" value="<?= e((string) $line['qty']) ?>">
                            <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-3 py-2 text-sm hover:bg-ink/5" type="submit">Пересчитать</button>
                        </form>
                        <p class="font-bold"><?= e(commerceFormatMoney((int) $line['line_cents'], (string) $line['currency'])) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="text-xl font-bold">Итого: <?= e(commerceFormatMoney($totalCents, $currency)) ?></p>

        <form class="space-y-4 rounded-xl border border-ink/10 bg-white p-6" method="post" action="/shop/order">
            <?= csrfField() /* @safe html */ ?>
            <h2 class="text-xl font-semibold">Оформление заказа</h2>

            <?php foreach (['customer_name' => 'Имя', 'customer_email' => 'E-mail', 'customer_phone' => 'Телефон (необязательно)'] as $field => $label): ?>
                <?php $error = (string) ($errors[$field] ?? ''); ?>
                <label class="block" for="<?= e($field) ?>">
                    <span class="text-sm text-ink/60"><?= e($label) ?></span>
                    <input class="mt-1 w-full min-h-[44px] rounded-lg border <?= e($error !== '' ? 'border-red-400' : 'border-ink/20') ?> px-3 py-2" id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($field === 'customer_email' ? 'email' : 'text') ?>" value="<?= e((string) ($values[$field] ?? '')) ?>">
                    <?php if ($error !== ''): ?>
                        <span class="mt-1 block text-sm text-red-700"><?= e($error) ?></span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>

            <label class="block" for="address">
                <span class="text-sm text-ink/60">Адрес доставки (необязательно)</span>
                <input class="mt-1 w-full min-h-[44px] rounded-lg border <?= e(isset($errors['address']) ? 'border-red-400' : 'border-ink/20') ?> px-3 py-2" id="address" name="address" type="text" value="<?= e((string) ($values['address'] ?? '')) ?>">
                <?php $error = (string) ($errors['address'] ?? ''); ?>
                <?php if ($error !== ''): ?>
                    <span class="mt-1 block text-sm text-red-700"><?= e($error) ?></span>
                <?php endif; ?>
            </label>

            <label class="block" for="comment">
                <span class="text-sm text-ink/60">Комментарий (необязательно)</span>
                <textarea class="mt-1 w-full min-h-[44px] rounded-lg border <?= e(isset($errors['comment']) ? 'border-red-400' : 'border-ink/20') ?> px-3 py-2" id="comment" name="comment" rows="3" maxlength="<?= e((string) COMMERCE_ORDER_COMMENT_MAX) ?>"><?= e((string) ($values['comment'] ?? '')) ?></textarea>
                <?php $error = (string) ($errors['comment'] ?? ''); ?>
                <?php if ($error !== ''): ?>
                    <span class="mt-1 block text-sm text-red-700"><?= e($error) ?></span>
                <?php endif; ?>
            </label>

            <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-5 py-2 text-sm font-medium text-cream hover:bg-ink/90" type="submit">Оформить заказ</button>
        </form>

        <form method="post" action="/shop/cart/update">
            <?= csrfField() /* @safe html */ ?>
            <input type="hidden" name="op" value="clear">
            <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5" type="submit">Опустошить корзину</button>
        </form>
    <?php endif; ?>
</section>
