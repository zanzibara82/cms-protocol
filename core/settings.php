<?php

declare(strict_types=1);

// Настройки шапки и подвала: текст и логотип хранятся в tbl_option и читаются темой на каждой
// странице. Отдельный экран админки правит их одной формой; ключи стабильны, потому что на них
// ссылаются шаблоны темы, а не только этот экран.
/** @var array<string, array{max: int}> */
const SITE_SETTINGS_FIELDS = [
    'header_logo' => ['max' => 200],
    'header_text' => ['max' => 120],
    'footer_logo' => ['max' => 200],
    'footer_text' => ['max' => 300],
];

// Значение по умолчанию — то, что человек видит на свежем сайте до первой правки. Пустая строка
// в опции остаётся пустой: её можно сохранить, чтобы скрыть текст или логотип.
function siteSettingDefault(string $key): string
{
    $siteName = (string) configValue('SITE_NAME', 'Школа');

    return match ($key) {
        'header_text' => $siteName,
        'footer_text' => $siteName . ' — новости, курсы и объявления школы.',
        default => '',
    };
}

function siteSettingValue(string $key): string
{
    return getOption($key, siteSettingDefault($key));
}

/**
 * Поля формы настроек: обрезаются пробелы и предел, чтобы подделанный POST не раздувал опцию.
 *
 * @return array<string, string>
 */
function siteSettingsFromPost(): array
{
    $values = [];

    foreach (SITE_SETTINGS_FIELDS as $key => $field) {
        $values[$key] = mb_substr(trim(postString($key)), 0, $field['max']);
    }

    return $values;
}

/**
 * Запись настроек одной транзакцией: экран показывает четыре поля как одно целое, и половина
 * сохранённой шапки при сбое базы хуже, чем прежнее значение целиком.
 *
 * @param array<string, string> $values
 */
function siteSettingsSave(array $values): void
{
    tx(static function () use ($values): void {
        foreach (SITE_SETTINGS_FIELDS as $key => $field) {
            setOption($key, (string) ($values[$key] ?? ''));
        }
    });
}
