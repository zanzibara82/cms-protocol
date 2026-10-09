<?php

declare(strict_types=1);

// Настройки читаются одним запросом и кешируются на запрос: на shared-хостинге процесс живёт
// ровно столько, сколько запрос, поэтому кеш не успевает устареть, а лишний запрос на каждой
// странице — расход. Пишущие функции сбрасывают кеш, чтобы чтение в том же запросе увидело новое.
/** @return array<string, string|null>|null */
function &optionsRef(): ?array
{
    static $options = null;

    return $options;
}

/** @return array<string, string|null> */
function optionsAll(): array
{
    $cache = &optionsRef();

    if ($cache !== null) {
        return $cache;
    }

    $loaded = [];
    foreach (all('SELECT option_name, option_value FROM ' . t('option') . ' WHERE autoload = 1') as $row) {
        $loaded[(string) $row['option_name']] = $row['option_value'] === null ? null : (string) $row['option_value'];
    }

    $cache = $loaded;

    return $cache;
}

function optionsReset(): void
{
    $cache = &optionsRef();
    $cache = null;
}

function getOption(string $name, string $default = ''): string
{
    $options = optionsAll();

    // Пустая строка — значение, а не отсутствие настройки: иначе поле нельзя очистить.
    if (!array_key_exists($name, $options) || $options[$name] === null) {
        return $default;
    }

    return (string) $options[$name];
}

function setOption(string $name, ?string $value): void
{
    if ($value === null) {
        deleteOption($name);

        return;
    }

    // Одна пишущая инструкция вместо «сначала узнать, есть ли строка»: между проверкой и вставкой
    // успевал встать второй писатель, и имя получало дубликат. Уникальный ключ по имени опции
    // задан в базовой схеме, поэтому ветка обновления выражается через ON DUPLICATE KEY UPDATE.
    // Функция VALUES() взята вместо формы с псевдонимом таблицы: она работает и на MySQL 8,
    // и на MariaDB, тогда как псевдоним доступен только с MySQL 8.0.19. Обновляется только
    // значение — флаг автозагрузки существующей строки остаётся как был.
    run(
        'INSERT INTO ' . t('option') . ' (option_name, option_value, autoload) VALUES (?, ?, 1)'
        . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
        [$name, $value]
    );

    optionsReset();
}

function deleteOption(string $name): void
{
    run('DELETE FROM ' . t('option') . ' WHERE option_name = ?', [$name]);
    optionsReset();
}
