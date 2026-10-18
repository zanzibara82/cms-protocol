<?php

declare(strict_types=1);

// Валидаторы магазина: правила здесь, формы их печатают. Каждая проверка возвращает
// человеческую причину отказа, и вместе с formValues() из ядра правило одно:
// отказ сохранения обязан вернуть человеку набранный текст.

/**
 * Числовые поля товара приходят из формы строками: приём «строка → число» сосредоточен
 * здесь, а контроллер получает уже проверенные значения.
 *
 * @param array<string, string> $post
 * @param list<array{id: string, title: string}> $categoryOptions
 * @param callable(string): bool $slugTaken
 * @return array{errors: array<string, string>, values: array<string, mixed>, slug_error: string}
 */
function commerceValidateProduct(array $post, string $currentSlug, array $categoryOptions, callable $slugTaken): array
{
    $errors = [];
    $fields = [
        'title' => str_replace("\r", '', trim((string) ($post['title'] ?? ''))),
        'slug' => trim((string) ($post['slug'] ?? '')),
        'sku' => trim((string) ($post['sku'] ?? '')),
        'lead_text' => str_replace("\r", '', trim((string) ($post['lead_text'] ?? ''))),
        'brand' => trim((string) ($post['brand'] ?? '')),
        'barcode' => trim((string) ($post['barcode'] ?? '')),
        'price_retail' => trim((string) ($post['price_retail'] ?? '')),
        'price_promo' => trim((string) ($post['price_promo'] ?? '')),
        'price_cost' => trim((string) ($post['price_cost'] ?? '')),
        'currency' => strtoupper(trim((string) ($post['currency'] ?? ''))),
        'stock' => trim((string) ($post['stock'] ?? '')),
        'status' => strtoupper(trim((string) ($post['status'] ?? ''))),
        'seo_title' => trim((string) ($post['seo_title'] ?? '')),
        'seo_description' => trim((string) ($post['seo_description'] ?? '')),
        'noindex' => isOn($post['noindex'] ?? '') ? '1' : '0',
    ];

    if ($fields['title'] === '') {
        $errors['title'] = 'Укажите название товара.';
    } elseif (mb_strlen($fields['title']) > COMMERCE_TITLE_MAX) {
        $errors['title'] = 'Название не длиннее ' . COMMERCE_TITLE_MAX . ' символов.';
    }

    if ($fields['sku'] === '') {
        $errors['sku'] = 'Укажите артикул (SKU) — по нему товар ищут и учитывают.';
    } elseif (mb_strlen($fields['sku']) > COMMERCE_SKU_MAX) {
        $errors['sku'] = 'Артикул не длиннее ' . COMMERCE_SKU_MAX . ' символов.';
    }

    $skuSeen = $fields['sku'] === ''
        ? null
        : one(
            'SELECT id FROM ' . t('commerce_product') . ' WHERE sku = ?',
            [$fields['sku']]
        );
    if ($skuSeen !== null && (string) ($post['id'] ?? '') !== (string) $skuSeen['id']) {
        $errors['sku'] = 'Такой артикул уже занят другим товаром.';
    }

    if ($fields['currency'] === '') {
        $fields['currency'] = COMMERCE_CURRENCY_DEFAULT;
    }
    if (!in_array($fields['currency'], COMMERCE_CURRENCIES, true)) {
        $errors['currency'] = 'Валюта выбирается из списка.';
    }

    $prices = [];
    foreach (['price_retail' => 'Укажите цену товара.', 'price_promo' => 'Промо-цена не разобрана.', 'price_cost' => 'Себестоимость не разобрана.'] as $priceField => $emptyMessage) {
        $raw = $fields[$priceField];
        if ($priceField !== 'price_retail' && $raw === '') {
            $prices[$priceField] = null;

            continue;
        }

        if ($raw === '' || !preg_match('/^\d+([.,]\d{1,2})?$/', str_replace(' ', '', $raw))) {
            $errors[$priceField] = $emptyMessage;

            continue;
        }

        $amount = (float) str_replace(',', '.', str_replace(' ', '', $raw));
        if ($amount <= 0 || $amount > COMMERCE_PRICE_MAX) {
            $errors[$priceField] = 'Цена должна быть больше нуля и не выше ' . number_format(COMMERCE_PRICE_MAX, 2, ',', ' ') . '.';

            continue;
        }

        $prices[$priceField] = $amount;
    }

    if (isset($prices['price_retail']) && isset($prices['price_promo'])
        && $prices['price_promo'] >= $prices['price_retail']) {
        $errors['price_promo'] = 'Промо-цена должна быть ниже обычной.';
    }

    if ($fields['status'] !== '' && !in_array($fields['status'], COMMERCE_PRODUCT_STATUSES, true)) {
        $errors['status'] = 'Статус выбирается из списка.';
    }
    if ($fields['status'] === '') {
        $fields['status'] = 'DRAFT';
    }

    $stock = (int) $fields['stock'];
    if ($fields['stock'] !== '' && ($stock < 0 || $stock > COMMERCE_QTY_MAX)) {
        $errors['stock'] = 'Остаток — целое число от 0 до ' . COMMERCE_QTY_MAX . '.';
    }

    if (mb_strlen($fields['brand']) > 120) {
        $errors['brand'] = 'Наименование бренда не длиннее 120 символов.';
    }
    if (mb_strlen($fields['barcode']) > 64) {
        $errors['barcode'] = 'Штрихкод не длиннее 64 символов.';
    }
    if (mb_strlen($fields['lead_text']) > COMMERCE_LEAD_MAX) {
        $errors['lead_text'] = 'Краткое описание не длиннее ' . COMMERCE_LEAD_MAX . ' символов.';
    }
    if (mb_strlen($fields['seo_title']) > 120) {
        $errors['seo_title'] = 'SEO-заголовок не длиннее 120 символов.';
    }
    if (mb_strlen($fields['seo_description']) > 300) {
        $errors['seo_description'] = 'SEO-описание не длиннее 300 символов.';
    }

    // Абзацы описания приходят параллельными полями формы body_title[] и body_text[].
    $body = [];
    $bodyTitles = array_slice((array) ($post['body_title'] ?? []), 0, 64);
    $bodyTexts = array_slice((array) ($post['body_text'] ?? []), 0, 64);
    foreach ($bodyTexts as $i => $text) {
        $text = str_replace("\r", '', trim((string) $text));
        $heading = trim((string) ($bodyTitles[$i] ?? ''));
        if ($text === '' && $heading === '') {
            continue;
        }
        if (mb_strlen($text) > 20000 || mb_strlen($heading) > 120) {
            $errors['body'] = 'Абзац описания слишком длинный.';

            break;
        }
        $body[] = ['title' => $heading, 'text' => $text];
    }

    $plan = commerceSlugPlan(
        $fields['slug'],
        $fields['title'],
        static function (string $candidate) use ($slugTaken, $currentSlug): bool {
            return $candidate === $currentSlug ? false : $slugTaken($candidate);
        },
        'product'
    );
    if ($plan['error'] !== '') {
        $errors['slug'] = $plan['error'];
    }

    $categories = [];
    $known = [];
    foreach ($categoryOptions as $option) {
        $known[(string) $option['id']] = true;
    }
    $chosen = (array) ($post['categories'] ?? []);
    foreach (array_slice($chosen, 0, 12) as $categoryId) {
        $categoryId = (string) $categoryId;
        if (isset($known[$categoryId])) {
            $categories[] = $categoryId;
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'fields' => $fields,
            'prices' => $prices,
            'stock' => $stock,
            'body' => $body,
            'slug' => $plan['slug'],
            'categories' => $categories,
        ],
        'slug_error' => $plan['error'],
    ];
}

/**
 * Данные гостевого заказа с витрины. Сплошной trim, границы длины и один формат e-mail:
 * от имени человека зависит доставка счёта, а не внутренние дела базы.
 *
 * @param array<string, string> $post
 * @return array{errors: array<string, string>, values: array<string, string>}
 */
function commerceValidateCheckout(array $post): array
{
    $errors = [];
    $getField = static function (string $key) use ($post): string {
        return str_replace("\r", '', trim((string) ($post[$key] ?? '')));
    };

    $name = $getField('customer_name');
    $email = $getField('customer_email');
    $phone = $getField('customer_phone');
    $address = $getField('address');
    $comment = $getField('comment');

    if ($name === '') {
        $errors['customer_name'] = 'Укажите имя — как к вам обратиться.';
    } elseif (mb_strlen($name) > COMMERCE_NAME_MAX) {
        $errors['customer_name'] = 'Имя не длиннее ' . COMMERCE_NAME_MAX . ' символов.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > COMMERCE_EMAIL_MAX) {
        $errors['customer_email'] = 'Укажите корректный e-mail — на него придёт подтверждение.';
    }

    if ($phone !== '' && mb_strlen($phone) > COMMERCE_PHONE_MAX) {
        $errors['customer_phone'] = 'Телефон не длиннее ' . COMMERCE_PHONE_MAX . ' символов.';
    }

    if (mb_strlen($address) > COMMERCE_ADDRESS_MAX) {
        $errors['address'] = 'Адрес доставки не длиннее ' . COMMERCE_ADDRESS_MAX . ' символов.';
    }

    if (mb_strlen($comment) > COMMERCE_ORDER_COMMENT_MAX) {
        $errors['comment'] = 'Комментарий не длиннее ' . COMMERCE_ORDER_COMMENT_MAX . ' символов.';
    }

    return [
        'errors' => $errors,
        'values' => [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'address' => $address,
            'comment' => $comment,
        ],
    ];
}

/**
 * Рубрики магазина. Своё правило заголовка: он несёт адрес страницы и печатается в
 * хлебных крошках, поэтому пустой или безразмерный заголовок недопустим.
 *
 * @param array<string, string> $post
 * @param callable(string): bool $slugTaken
 * @return array{errors: array<string, string>, values: array<string, mixed>}
 */
function commerceValidateCategory(array $post, string $currentSlug, callable $slugTaken, int $nextOrderIndex): array
{
    $errors = [];
    $title = trim((string) ($post['title'] ?? ''));
    $typoSlug = trim((string) ($post['slug'] ?? ''));

    if ($title === '') {
        $errors['title'] = 'Укажите название рубрики.';
    } elseif (mb_strlen($title) > 120) {
        $errors['title'] = 'Название не длиннее 120 символов.';
    }

    $plan = commerceSlugPlan(
        $typoSlug,
        $title,
        static function (string $candidate) use ($slugTaken, $currentSlug): bool {
            return $candidate === $currentSlug ? false : $slugTaken($candidate);
        },
        'category'
    );
    if ($plan['error'] !== '') {
        $errors['slug'] = $plan['error'];
    }

    return [
        'errors' => $errors,
        'values' => [
            'title' => $title,
            'slug' => $plan['slug'],
            'order_index' => $nextOrderIndex,
        ],
    ];
}
