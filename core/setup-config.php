<?php

declare(strict_types=1);

// Механика первого шага установщика живёт вне скрипта в документ-корне (спецификация §3 «Механика
// первого шага живёт вне скрипта в документ-корне»): экран и поток ветки остаются в
// `public/setup.php`, а чистые помощники подключаются оттуда. Причина переноса — покрываемость:
// через реальный тестовый сервер помощник проверяется ровно в одном сценарии, а набор `tests/unit`
// видит границы длин, числа в выводе и отказ записи напрямую.

/**
 * Источник значений по умолчанию для шага 1: тот же файл, который оператор копирует в config.php
 * на хостинге. Возврат не-массива трактуется как пустой набор, чтобы сид не падал на битом образце.
 *
 * @return array<string, mixed>
 */
function setupSampleConfig(): array
{
    $sample = require dirname(__DIR__) . '/config.sample.php';

    if (!is_array($sample)) {
        return [];
    }

    /** @var array<string, mixed> $sample */
    return $sample;
}

/**
 * Сырой файл настроек с диска. Вызывается строго до любого putenv(): после сида тот же config()
 * вернул бы значения окружения, и будущая запись «сохранила» бы подставленное образцом, а не боевые
 * значения. Нет файла или require отдал не массив — пустой набор.
 *
 * @return array<string, mixed>
 */
function setupReadRawConfig(): array
{
    $path = configPath();

    if (!is_file($path)) {
        return [];
    }

    $raw = require $path;

    if (!is_array($raw)) {
        return [];
    }

    /** @var array<string, mixed> $raw */
    return $raw;
}

/**
 * Кормит загрузчик через CMS_* до первого обращения к config(): экран шага 1 обязан открыться даже
 * без файла настроек на диске. Для каждого обязательного ключа приоритет — непустое значение
 * окружения, иначе непустое значение из файла, иначе непустое значение образца; пустой ключ
 * не сидится. Проверка непустоты повторяет ядро (core/config-loader.php): значение задано,
 * только когда getenv() вернуло строку и она не пуста. Само ядро не меняется.
 *
 * @param array<string, mixed> $raw
 */
function setupSeedConfig(array $raw): void
{
    $sample = setupSampleConfig();

    foreach (CONFIG_REQUIRED_KEYS as $key) {
        // Непустое окружение первее файла и образца: без этого пропуска сид внутри установщика
        // пересилил бы боевое значение (например CMS_DB_NAME=cms_test из http-тестов) и
        // приоритет ядра перевёрнулся бы. Пустая строка в окружении — не значение, ключ сидится дальше.
        $fromEnv = getenv(CONFIG_ENV_PREFIX . $key);
        if ($fromEnv !== false && $fromEnv !== '') {
            continue;
        }

        $value = '';

        if (configHasKey($raw, (string) $key)) {
            $value = (string) $raw[$key];
        } elseif (configHasKey($sample, (string) $key)) {
            $value = (string) $sample[$key];
        }

        if ($value !== '') {
            putenv(CONFIG_ENV_PREFIX . $key . '=' . $value);
        }
    }
}

/**
 * Нормализация полей шага 1: trim() у всего, что не пароль, и снятие конечного слэша у адреса.
 * db_pass остаётся как есть: пробелы в пароле легальны, и нормализация изменила бы реальный пароль.
 *
 * @param array<string, string> $values
 * @return array<string, string>
 */
function setupNormalizeDb(array $values): array
{
    $normalized = [];

    foreach (['db_host', 'db_port', 'db_name', 'db_user', 'table_prefix', 'site_name'] as $field) {
        $normalized[$field] = trim($values[$field]);
    }

    // Адрес сайта хранится без слэша в конце: из него строят абсолютные ссылки OG и карту сайтов.
    $normalized['site_url'] = rtrim($values['site_url'], '/');
    $normalized['db_pass'] = $values['db_pass'];

    return $normalized;
}

/**
 * Проверка нормализованных значений шага 1: «прошло проверку» и «записалось» — один и тот же набор.
 * Пустой пароль — валидное значение, правил у db_pass нет.
 *
 * @param array<string, string> $values
 * @return array<string, string>
 */
function setupValidateDb(array $values): array
{
    $errors = [];

    $host = $values['db_host'];
    if ($host === '') {
        $errors['db_host'] = 'Укажите хост базы данных.';
    } elseif (preg_match('/^[a-z0-9._-]+$/i', $host) !== 1 || strlen($host) > 190) {
        // Регулярка хоста взята из scripts/hosting-probe.php — тот же приём.
        $errors['db_host'] = 'Хост базы — адрес вида db.example.com или 127.0.0.1, без схемы, слэшей и пробелов.';
    }

    $port = $values['db_port'];
    if ($port === '') {
        $errors['db_port'] = 'Укажите порт базы данных — обычно 3306.';
    } elseif (preg_match('/^\d+$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
        $errors['db_port'] = 'Порт базы — целое число от 1 до 65535.';
    }

    $dbName = $values['db_name'];
    if ($dbName === '') {
        $errors['db_name'] = 'Укажите имя базы данных.';
    } elseif (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dbName) !== 1) {
        // Имя базы ограничено строже хоста: оно попадает в DSN без экранирования.
        $errors['db_name'] = 'Имя базы — до 64 символов: латинские буквы, цифры, подчёркивание и дефис.';
    }

    $user = $values['db_user'];
    if ($user === '') {
        $errors['db_user'] = 'Укажите пользователя базы данных.';
    } elseif (strlen($user) > 190) {
        $errors['db_user'] = 'Имя пользователя — не длиннее 190 символов.';
    }

    $prefix = $values['table_prefix'];
    if ($prefix === '') {
        $errors['table_prefix'] = 'Укажите префикс таблиц, например tbl_.';
    } elseif (preg_match('/^[a-z][a-z0-9_]*$/', $prefix) !== 1 || strlen($prefix) > 30) {
        // Префикс читает t() (core/db.php): она ждёт именно такой узор и иначе бросает
        // InvalidArgumentException («Недопустимое имя таблицы», core/db.php:41-43) уже на
        // установленном сайте — в fail() падает только db() (core/db.php:31), поэтому
        // ограничение строже, чем у хоста.
        $errors['table_prefix'] = 'Префикс таблиц — строчные латинские буквы, цифры и подчёркивание, начинается с буквы, до 30 символов.';
    }

    $siteUrl = $values['site_url'];
    if ($siteUrl === '') {
        $errors['site_url'] = 'Укажите адрес сайта, например https://example.com.';
    } elseif (filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
        $errors['site_url'] = 'Адрес сайта — полный URL со схемой http или https.';
    } else {
        $scheme = parse_url($siteUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            $errors['site_url'] = 'Адрес сайта — полный URL со схемой http или https.';
        }
    }

    $siteName = $values['site_name'];
    if (mb_strlen($siteName) < 2) {
        $errors['site_name'] = 'Название сайта — не короче двух символов.';
    } elseif (mb_strlen($siteName) > 120) {
        $errors['site_name'] = 'Название сайта — не длиннее 120 символов.';
    }

    return $errors;
}

/**
 * Сборка записываемого набора: сначала ключи образца (в его порядке), затем ключи файла, которых
 * в образце нет, затем оверлей восьми полей формы. Пустая строка в существующем файле — выбор
 * оператора, а не отсутствие ключа, поэтому array_key_exists, а не ??.
 *
 * @param array<string, mixed> $raw
 * @param array<string, mixed> $sample
 * @param array<string, string> $posted
 * @return array<string, mixed>
 */
function setupBuildConfig(array $raw, array $sample, array $posted): array
{
    $out = [];

    foreach ($sample as $key => $value) {
        $out[$key] = array_key_exists($key, $raw) ? $raw[$key] : $value;
    }

    foreach ($raw as $key => $value) {
        if (!array_key_exists($key, $out)) {
            $out[$key] = $value;
        }
    }

    // Оверлей введённого: восемь полей формы. DB_PASS живёт ровно здесь, в записанном файле.
    $out['DB_HOST'] = $posted['db_host'];
    $out['DB_PORT'] = $posted['db_port'];
    $out['DB_NAME'] = $posted['db_name'];
    $out['DB_USER'] = $posted['db_user'];
    $out['DB_PASS'] = $posted['db_pass'];
    $out['TABLE_PREFIX'] = $posted['table_prefix'];
    $out['SITE_URL'] = $posted['site_url'];
    $out['SITE_NAME'] = $posted['site_name'];

    return $out;
}

/**
 * Проверка собранного набора перед записью: каждый обязательный ключ обязан быть скаляром и
 * непустым. Возврат — список имён нарушенных ключей в порядке CONFIG_REQUIRED_KEYS; пустой список
 * значит, что набор годится к записи.
 *
 * @param array<string, mixed> $config
 * @return list<string>
 */
function setupCheckAssembled(array $config): array
{
    $violations = [];

    // Пустого пароля базы здесь нет нарочно: ключа DB_PASS в CONFIG_REQUIRED_KEYS нет, и это не
    // недосмотр, а свойство (правило F9) — пустой пароль базы является валидным значением.
    // Исключение для него добавлять нельзя.
    foreach (CONFIG_REQUIRED_KEYS as $key) {
        $value = $config[$key] ?? null;

        // Порядок операндов значим: configHasKey() внутри делает (string) $config[$key], а для
        // массива или объекта это предупреждение, которое обработчик ошибок ядра
        // (core/bootstrap.php:58-63) превращает в ErrorException. Проверка типа стоит первой и
        // коротко замыкает ||, поэтому до приведения типа не-скаляр не доходит.
        if (!is_scalar($value) || !configHasKey($config, $key)) {
            $violations[] = $key;
        }
    }

    return $violations;
}

/**
 * Рендер файла настроек: по строке на ключ, var_export() на ключе и значении (тип сохраняется —
 * в образце SMTP_PORT это число). Никакой интерполяции текста в PHP-исходник: var_export() обязан
 * экранировать и кавычки, и переносы строк — это защита от записи исполняемого кода через поле.
 *
 * @param array<string, mixed> $config
 */
function setupRenderConfig(array $config): string
{
    $lines = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '// Файл создан установщиком. Содержит пароль базы данных: права 600, в репозиторий не попадает.',
        '',
        'return [',
    ];

    foreach ($config as $key => $value) {
        $lines[] = '    ' . var_export((string) $key, true) . ' => ' . var_export($value, true) . ',';
    }

    $lines[] = '];';

    return implode("\n", $lines) . "\n";
}

/**
 * Атомарная запись: временный файл рядом с целью → chmod 600 → rename. Порядок строгий; ранний
 * отказ по имени цели происходит ДО любого обращения к диску. Причина отказа уровня диска уходит
 * в журнал, содержимое файла и пароли — никогда.
 *
 * @return array{error: string, warning: string}
 */
function setupWriteConfig(string $content, string $target): array
{
    $result = ['error' => '', 'warning' => ''];

    // Общий помощник http-тестов передаёт подпроцессу CMS_CONFIG_FILE=<корень>/config.sample.php, и
    // без отказа здесь первый же тест стёр бы версионированный образец. Выход ДО любого обращения к диску.
    if (basename($target) === 'config.sample.php') {
        $result['error'] = 'Настройки не записаны: цель установки называется config.sample.php — это образец, а не рабочий файл. Укажите верный путь в CMS_CONFIG_FILE.';

        return $result;
    }

    // Именно так, а не tempnam(): tempnam сам создаёт файл с 0600, и проверка chmod стала бы
    // непроверяемой.
    $tmp = dirname($target) . '/.cms-config-' . bin2hex(random_bytes(6));

    // umask(0o077) поднят ровно на время создания файла и сразу восстановлен: на виртуальном
    // хостинге файл с паролем базы иначе был бы читаем другим локальным пользователям (0644)
    // в окне между созданием и закрытием прав.
    $previousUmask = umask(0o077);
    $writtenBytes = @file_put_contents($tmp, $content);
    umask($previousUmask);

    if ($writtenBytes === false) {
        // Подавленное предупреждение иначе теряется без следа: человек видит только «каталог
        // недоступен для записи», а причину знает лишь журнал. Ни содержимого файла, ни паролей.
        logWarn('setup.config_write_failed', ['path' => dirname($target), 'reason' => (string) (error_get_last()['message'] ?? '')]);
        $result['error'] = 'Не удалось подготовить файл настроек: каталог ' . dirname($target) . ' недоступен для записи.';
        // Временный файл удаляем, если он всё-таки появился на диске.
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        return $result;
    }

    // umask уже закрыл окно видимости файла; chmod остаётся явным требованием §3 (права задаются
    // явно, а не наследуются от окружения процесса), а не единственной защитой.
    $chmodOk = @chmod($tmp, 0600);

    if (@rename($tmp, $target) === false) {
        // Причина та же, что и выше: без журнала отказ уровня диска неотличим от сотен других.
        logWarn('setup.config_write_failed', ['path' => dirname($target), 'reason' => (string) (error_get_last()['message'] ?? '')]);
        $result['error'] = 'Не удалось записать файл настроек по адресу ' . $target . ': проверьте права на каталог.';
        // Временный файл обязан удалиться — иначе повторный заход оставляет мусор.
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        return $result;
    }

    if (!$chmodOk) {
        // Установка продолжается: на части панелей 600 запрещает жёстче, чем позволяет владелец, и
        // молчаливый отказ здесь хуже предупреждения.
        $result['warning'] = 'Настройки записаны, но права 600 выставить не удалось — поправьте их в панели хостинга.';
    }

    return $result;
}
