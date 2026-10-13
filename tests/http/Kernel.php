<?php

declare(strict_types=1);

namespace Cms\Tests\http;

// Поднимаем настоящий php -S и ходим в него curl-ом: так проверяются статус, редиректы,
// заголовки и куки сессии — всё, что юнит-тест не видит.
final class Kernel
{
    private const HOST = '127.0.0.1';
    private const PORT = 8123;

    // Сколько раз и с каким шагом пробуем порт, прежде чем признать его чужим: освобождение после
    // stop() прошлого класса наступает не в тот же миг, а 10 проб по 0,1 с закрывают эту паузу.
    private const PORT_FREE_PROBES = 10;
    private const PORT_FREE_RETRY_MICROSECONDS = 100000;

    private static ?string $cookieJar = null;

    /** @var resource|null ресурс php -S, который подняли именно мы: им гасит stop() */
    private static $process = null;

    /**
     * @param array<string, string> $env переменные окружения сервера сверх обязательных.
     *        Так тест подставляет `CMS_EXTRA_APPS_DIR` с фикстурами приложений: каталог нужен
     *        только проверкам установки и обновления, а в остальных наборах он лишь добавлял бы
     *        в реестр чужие приложения.
     */
    public static function start(array $env = []): void
    {
        // Порт обязан быть свободен до запуска. Иначе wait() подключится к зависшему серверу прошлого
        // прогона (возможно, с другим окружением), а наш процесс упадёт с «Address already in use» в
        // /dev/null — и проверки молча пойдут не в тот сервер. Освобождение после stop() приходит с
        // задержкой, поэтому порт сначала опрашивается до ~1 секунды, а уже потом падаем громко и
        // называем виновника: чужой слушатель, который не уходит, обязан остаться находкой.
        self::waitUntilPortFree();

        if (self::portBusy()) {
            throw new \RuntimeException(sprintf(
                'Порт %d занят чужим процессом (%s:%d): остановите висящий php -S перед запуском http-набора.',
                self::PORT,
                self::HOST,
                self::PORT
            ));
        }

        $root = dirname(__DIR__, 2);
        self::$cookieJar = tempnam(sys_get_temp_dir(), 'cms-cookies-') ?: '/tmp/cms-cookies';

        $prefix = 'CMS_DB_NAME=cms_test CMS_CONFIG_FILE=' . escapeshellarg($root . '/config.sample.php');
        foreach ($env as $name => $value) {
            $prefix .= ' ' . $name . '=' . escapeshellarg($value);
        }

        // Изоляция окружения подпроцесса (F6): bootstrap.php выставляет CMS_EXTRA_APPS_DIR/
        // CMS_EXTRA_THEME_DIR для процессов PHPUnit, и без явного снятия proc_open передал бы их
        // в php -S по наследованию — тогда фикстурные «demo»/«future» жили бы во ВСЕХ http-классах,
        // а не только там, где их просят. «env -u» снимает унаследованные ключи; классы, которым
        // нужны фикстуры (AdminApps, Robots), передают их через $env — присваивание идёт после -u,
        // поэтому для них ключ возвращается.
        $command = sprintf(
            'env -u CMS_EXTRA_APPS_DIR -u CMS_EXTRA_THEME_DIR %s php -S %s:%d -t %s/public %s/public/index.php',
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
        self::$process = $process;

        self::wait();
    }

    public static function stop(): void
    {
        // Гасим прежде всего собственный процесс: proc_terminate закрывает того ребёнка, что мы
        // породили. Но `php -S` живёт внуком за оболочкой `sh -c`, а SIGTERM оболочке не
        // передаётся детям: оболочка умирает, сервер осиротевает на порту, и следующий класс
        // падает «порт занят». Поэтому страховка снимает детей породившего процесса ДО его
        // закрытия: пока оболочка жива, `pkill -P <pid>` видит `php -S` своим ребёнком. Сузили
        // именно до `-P`, а не `-f`: `-P` отбирает детей указанного родителя, тогда как `-f`
        // сверяет строку запуска любого процесса и задел бы чужой сервер на том же порту.
        // `pid` читается без проверки на `false`: вне ресурса `proc_get_status()` и не вызывается
        // — ветка `is_resource()` уже гарантирует живой процесс, а с ним статус всегда массив.
        if (is_resource(self::$process)) {
            $pid = (int) proc_get_status(self::$process)['pid'];

            if ($pid > 0) {
                exec('pkill -P ' . $pid . ' 2>/dev/null || true');
            }

            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }

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
     * Идентификатор сессии из jar: curl пишет строку вида
     * `#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\tcms_session\t<id>`, поэтому префикс `#HttpOnly_`
     * снимается, а поле ищется по имени на позиции 5.
     */
    public static function sessionId(): string
    {
        if (self::$cookieJar === null || !is_file(self::$cookieJar)) {
            return '';
        }

        $lines = file(self::$cookieJar, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $fields = explode("\t", $line);
            if (count($fields) >= 7 && $fields[5] === 'cms_session') {
                return $fields[6];
            }
        }

        return '';
    }

    /**
     * CSRF-токен из печатаемой формы. Разбор один на весь набор: три частные копии regex
     * однажды разошлись бы опечаткой — и проверка токена стала бы бутафорской.
     */
    public static function tokenFrom(string $body): string
    {
        return preg_match('/name="_token" value="([^"]+)"/', $body, $matches) === 1 ? $matches[1] : '';
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
     * Значением поля может быть `\CURLFile`: `CURLOPT_POSTFIELDS` принимает его нативно и сам
     * переключает запрос на `multipart/form-data`.
     *
     * @param array<string, string|\CURLFile> $fields
     * @param array<string, mixed> $options
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    public static function post(string $path, array $fields = [], array $options = []): array
    {
        return self::request('POST', $path, $fields, $options);
    }

    /**
     * @param array<string, string|\CURLFile> $fields
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

    /** Проба порта до спавна: если на нём уже кто-то слушает, это чужой сервер, а не наш. */
    private static function portBusy(): bool
    {
        $socket = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    /**
     * Ждём освобождения порта (до ~1 секунды): между stop() одного http-класса и start() другого
     * слушающий процесс исчезает асинхронно, и одиночная проба ловила собственную гонку.
     * Чужой процесс, который не уходит, цикл не маскирует — порог проб всё равно кончается падением.
     */
    private static function waitUntilPortFree(): void
    {
        for ($attempt = 0; $attempt < self::PORT_FREE_PROBES && self::portBusy(); $attempt++) {
            usleep(self::PORT_FREE_RETRY_MICROSECONDS);
        }
    }
}
