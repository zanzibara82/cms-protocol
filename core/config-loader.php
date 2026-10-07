<?php

declare(strict_types=1);

// Настройки читаются один раз за запрос: на shared-хостинге нет долгоживущего процесса,
// поэтому кеш в памяти бессмыслен, а повторное чтение файла — лишний диск.
const CONFIG_REQUIRED_KEYS = [
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'TABLE_PREFIX', 'SITE_URL', 'SITE_NAME', 'TIMEZONE',
];

const CONFIG_ENV_PREFIX = 'CMS_';

function configPath(): string
{
    $override = getenv('CMS_CONFIG_FILE');

    return is_string($override) && $override !== '' ? $override : dirname(__DIR__) . '/config.php';
}

/** @return array<string, mixed> */
function config(): array
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $path = configPath();
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) {
        fail('config.php обязан возвращать массив настроек.');
    }

    foreach (CONFIG_REQUIRED_KEYS as $key) {
        if (!array_key_exists($key, $config) || $config[$key] === '') {
            $fromEnv = getenv(CONFIG_ENV_PREFIX . $key);
            if ($fromEnv === false || $fromEnv === '') {
                fail('В настройках нет обязательного ключа ' . $key . '.');
            }
            $config[$key] = $fromEnv;
        }
    }

    // Окружение перекрывает файл: так тесты подставляют cms_test, не трогая config.php.
    foreach (array_keys($config) as $key) {
        $fromEnv = getenv(CONFIG_ENV_PREFIX . $key);
        if ($fromEnv !== false) {
            $config[$key] = $fromEnv;
        }
    }

    $loaded = $config;

    return $loaded;
}

function configValue(string $key, mixed $default = null): mixed
{
    $config = config();

    return array_key_exists($key, $config) && $config[$key] !== '' ? $config[$key] : $default;
}

function fail(string $message): never
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось запустить сайт: ' . $message;
    exit(1);
}
