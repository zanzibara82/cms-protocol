<?php

declare(strict_types=1);

namespace Cms\Tests\http;

// Поднимаем настоящий php -S и ходим в него curl-ом: так проверяются статус, редиректы,
// заголовки и куки сессии — всё, что юнит-тест не видит.
final class Kernel
{
    private const HOST = '127.0.0.1';
    private const PORT = 8123;

    private static ?string $cookieJar = null;

    /**
     * @param array<string, string> $env переменные окружения сервера сверх обязательных.
     *        Так тест подставляет `CMS_EXTRA_APPS_DIR` с фикстурами приложений: каталог нужен
     *        только проверкам установки и обновления, а в остальных наборах он лишь добавлял бы
     *        в реестр чужие приложения.
     */
    public static function start(array $env = []): void
    {
        $root = dirname(__DIR__, 2);
        self::$cookieJar = tempnam(sys_get_temp_dir(), 'cms-cookies-') ?: '/tmp/cms-cookies';

        $prefix = 'CMS_DB_NAME=cms_test CMS_CONFIG_FILE=' . escapeshellarg($root . '/config.sample.php');
        foreach ($env as $name => $value) {
            $prefix .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $command = sprintf(
            '%s php -S %s:%d -t %s/public %s/public/index.php',
            $prefix,
            self::HOST,
            self::PORT,
            escapeshellarg($root),
            escapeshellarg($root)
        );

        // Дескрипторы задаём позиционной формой ['file', путь, режим]: ассоциативный массив вида
        // ['file' => путь, 'w'] PHP читает как тип дескриптора «w» и падает «not a valid descriptor».
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить php -S');
        }

        self::wait();
    }

    public static function stop(): void
    {
        exec('pkill -f "php -S ' . self::HOST . ':' . self::PORT . '" 2>/dev/null || true');
        if (self::$cookieJar !== null && is_file(self::$cookieJar)) {
            unlink(self::$cookieJar);
        }
    }

    public static function freshCookies(): void
    {
        if (self::$cookieJar !== null && is_file(self::$cookieJar)) {
            file_put_contents(self::$cookieJar, '');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    public static function get(string $path, array $options = []): array
    {
        return self::request('GET', $path, [], $options);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $options
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    public static function post(string $path, array $fields = [], array $options = []): array
    {
        return self::request('POST', $path, $fields, $options);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $options
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    private static function request(string $method, string $path, array $fields, array $options): array
    {
        $url = 'http://' . self::HOST . ':' . self::PORT . $path;
        $handle = curl_init($url);
        $headers = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
            CURLOPT_COOKIEJAR => self::$cookieJar,
            CURLOPT_COOKIEFILE => self::$cookieJar,
            CURLOPT_FOLLOWLOCATION => (bool) ($options['follow'] ?? false),
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
        }

        // Свои заголовки нужны тесту CSRF и тесту «x-forwarded-for не читается».
        $extra = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $extra[] = $name . ': ' . $value;
        }
        if ($extra !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $extra);
        }

        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body, 'headers' => $headers, 'redirects' => []];
    }

    private static function wait(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(200000);
        }

        throw new \RuntimeException('Сервер php -S не поднялся за 10 секунд');
    }
}
