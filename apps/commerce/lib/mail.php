<?php

declare(strict_types=1);

// Письма заказа: подтверждение оформления и оповещение о статусе. Отделено от
// orders.php, чтобы создание заказа не зависело от судьбы конверта: письмо уходит
// после транзакции, его провал остаётся записью в журнале ядра.
//
// Транспорт подменяется фильтром commerce_mailer: тесты сажают туда Mailer со
// своим замыканием-собирателем и читают письмо, не трогая SMTP.

use Cms\Core\Mailer;

/**
 * Издатель писем раздела. Фильтр commerce_mailer имеет право вернуть готовый
 * Mailer (тестовый, с подменным транспортом); без фильтра — боевой с SMTP-доставкой.
 */
function commerceMailer(): Mailer
{
    $overridden = applyFilters('commerce_mailer', null);

    return $overridden instanceof Mailer ? $overridden : new Mailer();
}

/** Конвертные адреса: отправитель — «от кого не отвечать» на хосте сайта. */
function commerceMailFrom(): string
{
    $from = (string) configValue('MAIL_FROM', '');

    if ($from !== '') {
        return $from;
    }

    $host = (string) (parse_url((string) configValue('SITE_URL', ''), PHP_URL_HOST) ?: '');

    return $host !== '' ? 'no-reply@' . $host : 'no-reply@example.com';
}

/**
 * Письмо заказа. Позиции приходят строками заказа: названия уже с привидением
 * варианта, а скидки — снимок сделки: автоскидка правила и скидка купона печатаются
 * отдельными строками, итог — к оплате.
 *
 * @param list<array{title: string, sku: string, qty: int, price_cents: int, currency: string}> $items
 * @param array{coupon_code: string, coupon_discount_cents: int, promo_title: string, promo_discount_cents: int, total_cents: int} $pricing
 * @return array{to: string, subject: string, text: string}
 */
function commerceOrderLetter(
    int $number,
    string $email,
    string $name,
    string $intro,
    array $items,
    array $pricing,
    string $currency
): array {
    $site = (string) configValue('SITE_NAME', 'Школа');

    $lines = [
        'Здравствуйте' . ($name !== '' ? ', ' . $name : '') . '!',
        '',
        $intro,
        '',
    ];

    foreach ($items as $item) {
        $lines[] = '- ' . $item['title'] . ' (арт. ' . $item['sku'] . ') — '
            . $item['qty'] . ' × '
            . commerceFormatMoney($item['price_cents'], $item['currency']);
    }

    $lines[] = '';

    if ((int) $pricing['promo_discount_cents'] > 0 && $pricing['promo_title'] !== '') {
        $lines[] = 'Скидка по правилу «' . $pricing['promo_title'] . '»: −'
            . commerceFormatMoney((int) $pricing['promo_discount_cents'], $currency);
    }

    if ((int) $pricing['coupon_discount_cents'] > 0 && $pricing['coupon_code'] !== '') {
        $lines[] = 'Скидка по купону ' . $pricing['coupon_code'] . ': −'
            . commerceFormatMoney((int) $pricing['coupon_discount_cents'], $currency);
    }

    $lines[] = 'Итого к оплате: ' . commerceFormatMoney((int) $pricing['total_cents'], $currency);
    $lines[] = 'Номер заказа: ' . $number . '. Состояние заказа: '
        . rtrim((string) configValue('SITE_URL', ''), '/') . '/shop/order (номер и e-mail).';
    $lines[] = '';
    $lines[] = 'С уважением, магазин «' . $site . '».';

    return [
        'to' => $email,
        'subject' => 'Заказ №' . $number . ' — ' . $site,
        'text' => implode("\n", $lines),
    ];
}

/**
 * Отправка с журналом: письмо не валидировать оформление заказа — его провал
 * живёт записью commerce_mail_failed в журнале ядра.
 *
 * @param array{to: string, subject: string, text: string} $message
 */
function commerceMailSend(array $message): void
{
    $result = commerceMailer()->send($message);

    if ($result['ok'] !== true) {
        logWarn('commerce_mail_failed', ['reason' => $result['error']]);
    }
}
