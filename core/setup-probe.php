<?php

declare(strict_types=1);

// Зонды и отметка сессии первого шага уехали из `public/setup.php` в `core/` вместе с чистой
// механикой (спецификация §3 «Механика первого шага живёт вне скрипта в документ-корне»): в
// документ-корне остаётся только поток ветки, а помощники становятся покрываемы набором
// `tests/unit` — через реальный тестовый сервер каждый из них проверяется ровно в одном сценарии.

// Ключ отметки сессии объявлен здесь, а не в документ-корне: setupMarkSession() живёт в этом
// файле, и самостоятельное подключение core/setup-probe.php вне setup.php не должно давать фатал.
const SETUP_SESSION_DB = 'setup_db';

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

        // Граница текста отказа — в setupFailureHint(): первая строка сообщения драйвера и срез до
        // порога. Пароля в тексте драйвера нет.
        return 'Сервер базы отклонил пользователя или пароль: ' . setupFailureHint($exception->getMessage());
    }

    return null;
}

/**
 * Безопасная граница текста отказа: из сообщения драйвера берётся первая строка и срезается до
 * 160 символов. Первая строка — ровно то, по чему оператор судит, какой адрес и какого пользователя
 * он ввёл; секрета в ней нет (пароля в тексте драйвера не бывает). Последующие строки — версия
 * сервера и служебные детали, которые встречаются в других сборках драйвера, — оператору не нужны,
 * поэтому срез по первой строке и по порогу длины держится и на однострочном сообщении этого стенда.
 */
function setupFailureHint(string $driverMessage): string
{
    return excerpt(explode("\n", $driverMessage)[0], 160);
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

// Замок установщика (F9): отметка «сайт установлен» и атомарный захват на шаге 2. Механика живёт
// здесь по той же причине, что зонды шага 1: в документ-корне остаётся только поток ветки, а
// решение по замку становится покрываемым набором `tests/unit`.
const SETUP_LOCK = __DIR__ . '/../public/install.lock';

/**
 * Состояние замка читается с диска, поэтому функция нечистая: между проверкой в начале экрана и
 * попыткой захвата файл реально может появиться чужим запросом. Память о первом ответе здесь
 * запрещена — именно окно между этими двумя вызовами и закрывает атомарный захват.
 *
 * @phpstan-impure
 */
function setupLocked(): bool
{
    return is_file(SETUP_LOCK);
}

// F9: замок занимается атомарно. Проверка is_file() и создание файла двумя шагами оставляют
// окно, во втором запросе которого человек дошёл бы до setupCreateAdmin() и перезаписал
// пароль администратора. Режим 'xb' создаёт файл исключительно когда его нет: из двух
// параллельных POST установку продолжит только победитель.
function setupAcquireLock(): bool
{
    $handle = @fopen(SETUP_LOCK, 'xb');
    if ($handle === false) {
        return false;
    }

    fwrite($handle, utcStamp(nowUtc()) . "\n");
    fclose($handle);

    return true;
}

// Отказ проверки не оставляет замок: повторный POST обязан проходить.
function setupReleaseLock(): void
{
    if (is_file(SETUP_LOCK)) {
        unlink(SETUP_LOCK);
    }
}

/**
 * Вердикт замка для POST-ветки шага 2. 'proceed' — замок наш, установку можно продолжать;
 * 'installed' — замок стоит: сайт уже установлен, экран закрыт; 'busy' — захват проигран, а
 * замка на диске нет: чужой запрос держит замок в момент попытки. Проигравший обязан кончиться
 * отказом (M1): молча продолжать без замка нельзя — замок в этот момент не исключает никого,
 * и двое писали базу, администратора и опции наперегонки.
 *
 * @phpstan-impure
 */
function setupLockVerdict(): string
{
    if (setupAcquireLock()) {
        return 'proceed';
    }

    return setupLocked() ? 'installed' : 'busy';
}
