<?php

declare(strict_types=1);

// Письма по заявкам: подтверждение приёма и ответ школы. Имя из формы и текст ответа из админки
// попадают в HTML-письмо, поэтому вставляются только экранированным текстом: символ «<» в поле
// иначе стал бы разметкой письма. Перевод строк человек ставит клавишей Enter — в письме он виден
// только как <br>. Имя сайта читается из настройки, как в остальной теме ядра.

function leadBrandName(): string
{
    $site = trim((string) configValue('SITE_NAME'));

    return $site !== '' ? $site : 'Школа';
}

function leadEmailText(string $value): string
{
    $escaped = str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], $value);

    return str_replace("\r\n", "\n", str_replace("\r", "\n", $escaped));
}

function leadEmailHtml(string $value): string
{
    return str_replace("\n", '<br>', leadEmailText($value));
}

function leadConfirmSubject(): string
{
    return 'Заявка принята — ' . leadBrandName();
}

function leadReplySubject(): string
{
    return 'Ответ школы — ' . leadBrandName();
}

function leadFooter(): string
{
    return 'Письмо отправлено школой ' . leadBrandName() . ' по вашей заявке на сайте. Если вы заявку не оставляли — просто удалите его.';
}

function leadMailWrap(string $greeting, string $body): string
{
    return implode('', [
        '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;font-size:16px;line-height:1.55;color:#1f2937;max-width:560px">',
        '<p style="margin:0 0 22px;font-size:15px;font-weight:600;color:#7c3aed">' . leadEmailText(leadBrandName()) . '</p>',
        '<p style="margin:0 0 14px">' . $greeting . '</p>',
        $body,
        '<p style="margin:26px 0 0;font-size:13px;line-height:1.5;color:#6b7280">' . leadEmailText(leadFooter()) . '</p>',
        '</div>',
    ]);
}

// Подтверждение приходит сразу после формы: человек должен видеть, что заявка не потерялась.
function leadConfirmHtml(string $name): string
{
    $body = '<p style="margin:0 0 14px">Заявку получили. Ответим в течение рабочего дня и предложим время для первого бесплатного урока.</p>'
        . '<p style="margin:0">Хотите рассказать о себе заранее — ответьте на это письмо: так занятие подбирают быстрее.</p>';

    return leadMailWrap('Здравствуйте, ' . leadEmailHtml($name) . '!', $body);
}

function leadConfirmText(string $name): string
{
    return "Здравствуйте, {$name}!\n\n"
        . "Заявку получили. Ответим в течение рабочего дня и предложим время для первого бесплатного урока.\n\n"
        . "Хотите рассказать о себе заранее — ответьте на это письмо: так занятие подбирают быстрее.\n\n"
        . leadFooter();
}

// Ответ пишет администратор: весь текст его письма и есть тело, ничего от себя не добавляем.
function leadReplyHtml(string $name, string $text): string
{
    return leadMailWrap('Здравствуйте, ' . leadEmailHtml($name) . '!', '<p style="margin:0">' . leadEmailHtml($text) . '</p>');
}

function leadReplyText(string $name, string $text): string
{
    return "Здравствуйте, {$name}!\n\n{$text}\n\n" . leadFooter();
}

// Черновик ответа в форме администратора. Готовый текст ускоряет переписку, но его правят:
// в заявке видно, о чём спрашивал человек.
function leadReplyDraft(string $name): string
{
    $brand = leadBrandName();

    return "Здравствуйте, {$name}!\n"
        . "\n"
        . "Спасибо за заявку в {$brand}. Чтобы подобрать занятие, уточним пару деталей:\n"
        . "\n"
        . "— Какая цель: разговорный английский, работа, переезд или экзамен?\n"
        . "— Сколько раз в неделю вам удобно заниматься?\n"
        . "\n"
        . 'Ответьте на это письмо — договоримся о первом бесплатном уроке.';
}
