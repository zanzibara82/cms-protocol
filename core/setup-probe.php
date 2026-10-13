<?php

declare(strict_types=1);

// Зонды и отметка сессии первого шага уехали из `public/setup.php` в `core/` вместе с чистой
// механикой (спецификация §3 «Механика первого шага живёт вне скрипта в документ-корне»): в
// документ-корне остаётся только поток ветки, а помощники становятся покрываемы набором
// `tests/unit` — через реальный тестовый сервер каждый из них проверяется ровно в одном сценарии.

/**
 * Зонд достигаемости хоста и порта: три секунды на установку соединения — тот же приём, что в
 * scripts/hosting-probe.php. null — адрес достижим.
 */
function setupProbeNetwork(string $host, int $port): ?string
{
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client('tcp://' . $host . ':' . (string) $port, $errno, $errstr, 3.0);

    if ($socket === false) {
        return 'Сервер базы недоступен по адресу ' . $host . ':' . (string) $port
            . '. Проверьте хост и порт: на виртуальном хостинге их выдаёт панель хостинга.';
    }

    fclose($socket);

    return null;
}

/**
 * Зонд самой базы: создаёт свой PDO, а не через db() — db() читает конфиг, который мы ещё не
 * записали. null — соединение прошло.
 *
 * @param array<string, string> $values
 */
function setupProbeDatabase(array $values): ?string
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $values['db_host'],
        $values['db_port'],
        $values['db_name']
    );

    try {
        // Атрибуты совпадают с core/db.php:7-30 — иначе установщик и сайт судили бы о соединении
        // по-разному (режим ошибок, способ выборки и представления строк).
        new PDO($dsn, $values['db_user'], $values['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) === 1049) {
            return 'Базы с именем ' . $values['db_name'] . ' нет. Создайте её в панели хостинга и повторите шаг.';
        }

        // Сообщение драйвера содержит имя пользователя: это не секрет, но целиком показывать незачем,
        // поэтому берём первую строку и режем до 160 символов. Пароля в тексте драйвера нет.
        $hint = explode("\n", $exception->getMessage())[0];

        return 'Сервер базы отклонил пользователя или пароль: ' . excerpt($hint, 160);
    }

    return null;
}

/**
 * Отметка перехода в сессию: семь значений из нормализованного набора плюс метка времени проверки.
 * DB_PASS — никогда: пароль нужен только соединению и живёт в записанном config.php, поэтому шаг 2
 * пойдёт обычным путём через db(), читающий файл, а не по значениям этой отметки.
 *
 * @param array<string, string> $values
 */
function setupMarkSession(array $values): void
{
    $_SESSION[SETUP_SESSION_DB] = [
        'DB_HOST' => $values['db_host'],
        'DB_PORT' => $values['db_port'],
        'DB_NAME' => $values['db_name'],
        'DB_USER' => $values['db_user'],
        'TABLE_PREFIX' => $values['table_prefix'],
        'SITE_URL' => $values['site_url'],
        'SITE_NAME' => $values['site_name'],
        'checked_at' => utcStamp(nowUtc()),
    ];
}
