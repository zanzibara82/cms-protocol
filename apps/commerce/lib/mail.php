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
 * варианта, а суммы формируются тем же форматировщиком, что печатает витрина.
 *
 * @param list<array{title: string, sku: string, qty: int, price_cents: int, currency: string}> $items
 * @return array{to: string, subject: string, text: string}
 */
function commerceOrderLetter(
    int $number,
    string $email,
    string $name,
    string $intro,
    array $items,
    int $totalCents,
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
    $lines[] = 'Итого: ' . commerceFormatMoney($totalCents, $currency);
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
