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
        // а не только там, где их просят. CMS_SITE_URL снимаем тем же «env -u»: контейнер разработки
        // выставляет его (docker-compose.dev.yml:21 — http://localhost:8080 без конечного слэша),
        // и без снятия утверждения про абсолютный адрес жили бы за счёт унаследованного окружения, а
        // не заготовки настроек. Фикстура stepOneFields() даёт адрес с конечным слэшем, контейнер —
        // без него; различие видно именно при снятом окружении. Классы, которым нужен свой адрес
        // (EmptySiteUrl, установочные), передают его через $env — присваивание идёт после -u,
        // поэтому для них ключ возвращается. Граница снятия измерена в фикс-раунде 2 (прогон набора
        // http с -e CMS_SITE_URL=http://environ.test:9000): env -u действует на порождаемый php -S,
        // поэтому серверные ответы строятся из заготовки, а помощники самого процесса PHPUnit
        // переменную читают — при внешнем -e остаётся одно падение, SitemapTest.php:68 с
        // absoluteUrl(). Его совпадение с заготовкой держится на равенстве значений, а не на
        // изоляции: ключ не снимает окружение в процессе PHPUnit и больше обещать не вправе.
        $command = sprintf(
            'env -u CMS_EXTRA_APPS_DIR -u CMS_EXTRA_THEME_DIR -u CMS_SITE_URL %s php -S %s:%d -t %s/public %s/public/index.php',
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
     * Идентификатор сессии из файла куки: curl пишет строку вида
     * `#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\tcms_session\t<id>`. Префикс `#HttpOnly_` живёт в
     * нулевом поле (поле домена) и остаётся на месте: поиск идёт по имени на позиции 5, поэтому на
     * результат префикс не влияет.
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
     * Окружение установщика для start(): CMS_CONFIG_FILE с указанным путём плюс пустые строки во
     * всех обязательных CMS_* — ровно тот набор, что передают классы шага 1.
     *
     * Порядок присваивания в start() таков, что этот массив перекрывает CMS_DB_NAME=cms_test из
     * префикса команды (Kernel.php:51) и унаследованный из контейнера CMS_DB_HOST=db. Без обнуления
     * проверка «значение из формы перекрывает окружение» стала бы бутафорской: это не деталь сборки,
     * а причина существования помощника. Пустая строка для ядра — не значение
     * (core/config-loader.php:54), поэтому источниками значений остаются файл и образец.
     *
     * @return array<string, string>
     */
    public static function installerEnv(string $configPath): array
    {
        return [
            'CMS_CONFIG_FILE' => $configPath,
            'CMS_DB_HOST' => '',
            'CMS_DB_PORT' => '',
            'CMS_DB_NAME' => '',
            'CMS_DB_USER' => '',
            'CMS_TABLE_PREFIX' => '',
            'CMS_SITE_URL' => '',
            'CMS_SITE_NAME' => '',
        ];
    }

    /**
     * Эталон корректного POST шага 1 — перенесён дословно из SetupDbStepTest::validPost(). Учётные
     * данные корня с пустым паролем — свойство контейнера (MYSQL_ALLOW_EMPTY_PASSWORD,
     * docker-compose.dev.yml:44): корректный POST использует пустой db_pass, непустой пароль база
     * не примет. Значения, чуждые эталону (несуществующий хост и порт 1, плохой table_prefix, секрет
     * в db_pass), передаются через $overrides на месте вызова — именно так отличие видно читателю.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function stepOneFields(string $token, array $overrides = []): array
    {
        $fields = [
            'db_host' => 'db',
            'db_port' => '3306',
            'db_name' => 'cms_test',
            'db_user' => 'root',
            'db_pass' => '',
            'table_prefix' => 'tbl_setup_test_',
            'site_url' => 'http://localhost:8080/',
            'site_name' => 'Школа с шага первого',
        ];

        return array_merge($fields, $overrides, ['_token' => $token]);
    }

    /**
     * Корректный POST шага 1 на /setup.php?step=db. Возвращает ответ как есть, чтобы вызывающий сам
     * проверил код состояния: успех — 302 на шаг 2, отказ — 200 с формой.
     *
     * @param array<string, string> $overrides
     * @return array{status: int, body: string, headers: array<string, string>, redirects: list<string>}
     */
    public static function passStepOne(string $token, array $overrides = []): array
    {
        return self::post('/setup.php?step=db', self::stepOneFields($token, $overrides));
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
