<?php

declare(strict_types=1);

// Установщик лежит в public/, потому что документ-корень хостинга — именно public/.
// Доступен ровно один раз: после успешной установки появляется install.lock, и любой следующий
// запрос отвечает 404 (критерий §12.1). Файл с хостинга легко удалить, но легко и забыть —
// поэтому замок обязателен, а не желателен.
require dirname(__DIR__) . '/core/bootstrap.php';

const SETUP_LOCK = __DIR__ . '/install.lock';
const SETUP_REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'gd'];
const SETUP_MIN_PHP = '8.1.0';
const SETUP_PASSWORD_MIN = 10;
const SETUP_STEP_DB = 'db';
const SETUP_STEP_ADMIN = 'admin';
// Ключ отметки сессии перехода и белый список полей формы шага 1 (db_pass читается здесь же,
// но в шаблон не уходит никогда — прежнее правило F9).
const SETUP_SESSION_DB = 'setup_db';
const SETUP_DB_FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'table_prefix', 'site_url', 'site_name'];

/**
 * Помощники сида и шага объявлены в начале файла, а не в общем списке ниже: их вызов обязан
 * состояться до securityHeaders() и до любого config(), поэтому и определение держится рядом —
 * без надежды на hoisting читатель видит весь механизм заготовки настроек одним блоком.
 */

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
        // пересилил бы боевое значение (например CMS_DB_NAME=cms_test из http-харнеса) и
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
 * Шаг установщика читается только из GET: admin даёт шаг 2, всё остальное (пусто, мусор, ADMIN) —
 * шаг 1. Из POST шаг не берётся, иных шагов нет.
 */
function setupStep(): string
{
    $step = $_GET['step'] ?? '';

    return is_string($step) && $step === SETUP_STEP_ADMIN ? SETUP_STEP_ADMIN : SETUP_STEP_DB;
}

// Сид обязан предшествовать первому вызову config(): она кэшируется статически
// (core/config-loader.php), и вызванная без заготовки экран шага 1 не открылся бы вовсе.
$setupRawConfig = setupReadRawConfig();
setupSeedConfig($setupRawConfig);

securityHeaders();

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

/** @return list<string> */
function setupProblems(): array
{
    $problems = [];

    if (version_compare(PHP_VERSION, SETUP_MIN_PHP, '<')) {
        $problems[] = 'Нужен PHP не ниже ' . SETUP_MIN_PHP . ', сейчас ' . PHP_VERSION . '.';
    }

    foreach (SETUP_REQUIRED_EXTENSIONS as $extension) {
        if (!extension_loaded($extension)) {
            $problems[] = 'Не хватает расширения ' . $extension . '.';
        }
    }

    if (!is_writable(__DIR__)) {
        $problems[] = 'Каталог public/ недоступен для записи — не получится создать install.lock.';
    }

    // Журнал пишется вне документ-корня; недоступный каталог журнала означал бы, что штатные
    // logWarn()/logError() молча теряются, а сбой миграции стало бы невозможно разобрать.
    $logDir = dirname(logPath());
    if (!is_dir($logDir) || !is_writable($logDir)) {
        $problems[] = 'Каталог журнала недоступен для записи — ошибки установки не запишутся.';
    }

    return $problems;
}

/**
 * @param array<string, string> $values
 * @return array<string, string>
 */
function setupValidate(array $values): array
{
    $errors = [];

    if (mb_strlen(trim($values['site_name'] ?? '')) < 2) {
        $errors['site_name'] = 'Название сайта — не короче двух символов.';
    }

    $email = mb_strtolower(trim($values['admin_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
        $errors['admin_email'] = 'Проверьте адрес почты.';
    }

    if (mb_strlen(trim($values['admin_name'] ?? '')) < 2) {
        $errors['admin_name'] = 'Укажите имя администратора.';
    }

    $password = (string) ($values['password'] ?? '');
    if (mb_strlen($password) < SETUP_PASSWORD_MIN) {
        $errors['password'] = 'Пароль — не короче ' . SETUP_PASSWORD_MIN . ' символов.';
    } elseif ($password !== (string) ($values['password_again'] ?? '')) {
        $errors['password_again'] = 'Пароли не совпадают.';
    }

    return $errors;
}

// Таблицы ядра создаёт установщик, а не planApp('core'): журнала tbl_app_migration до них
// не существует, поэтому строку журнала и установку ядра пишем вручную сразу после схемы.
// Замок ставится только в самом конце, поэтому между неудачной попыткой и повторным запуском
// этот шаг обязан быть повторяемым: схема — через IF NOT EXISTS (отклонение 9), записи — только
// если их ещё нет. Иначе второй прогон упирался бы в migration_app_name (app, name) и в
// PRIMARY KEY id = 'core', и установить сайт после сбоя было бы нельзя.
function setupApplyBaseline(): void
{
    $file = dirname(__DIR__) . '/sql/0001_core_baseline.sql';

    foreach (splitSql((string) file_get_contents($file)) as $statement) {
        run($statement);
    }

    $alreadyLogged = one('SELECT id FROM ' . t('app_migration') . ' WHERE app = ? AND name = ?', ['core', basename($file)]);
    if ($alreadyLogged === null) {
        insert('app_migration', [
            'id' => newId(),
            'app' => 'core',
            'name' => basename($file),
            'checksum' => migrationChecksum($file),
            'applied_at' => utcStamp(nowUtc()),
        ]);
    }

    $installed = one('SELECT id FROM ' . t('app_installation') . ' WHERE id = ?', ['core']);
    if ($installed === null) {
        insert('app_installation', [
            'id' => 'core',
            'enabled' => 1,
            'installed_at' => utcStamp(nowUtc()),
            'version' => CORE_VERSION,
        ]);
    }
}

/** @param array<string, string> $values */
function setupCreateAdmin(array $values): void
{
    $email = mb_strtolower(trim($values['admin_email']));

    // Повторный прогон после сбоя (замок пишется только в самом конце) упирается в
    // user_email (email), поэтому существовавший адрес не вставляется второй раз, а обновляется:
    // человек входит паролем, который ввёл в последней попытке.
    $existing = one('SELECT id FROM ' . t('user') . ' WHERE email = ?', [$email]);
    if ($existing === null) {
        insert('user', [
            'id' => newId(),
            'email' => $email,
            'password_hash' => hashPassword($values['password']),
            'name' => trim($values['admin_name']),
            'role' => ROLES['admin'],
            'session_version' => 0,
            'created_at' => utcStamp(nowUtc()),
        ]);

        return;
    }

    run(
        'UPDATE ' . t('user') . ' SET password_hash = ?, name = ?, role = ? WHERE id = ?',
        [hashPassword($values['password']), trim($values['admin_name']), ROLES['admin'], (string) $existing['id']]
    );
}

function setupFinish(): void
{
    // Замок занят в начале POST-ветки и остаётся на месте — здесь только журнал.
    logInfo('setup.finished', ['php' => PHP_VERSION]);
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
        // Префикс читает t() (core/db.php): она ждёт именно такой узор и иначе падает в fail()
        // уже на установленном сайте, поэтому ограничение строже, чем у хоста.
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
 * отказ по имени цели происходит ДО любого обращения к диску.
 *
 * @return array{error: string, warning: string}
 */
function setupWriteConfig(string $content, string $target): array
{
    $result = ['error' => '', 'warning' => ''];

    // Тестовый харнес передаёт подпроцессу CMS_CONFIG_FILE=<корень>/config.sample.php, и без отказа
    // первый же тест стёр бы версионированный образец. Выход ДО любого обращения к диску.
    if (basename($target) === 'config.sample.php') {
        $result['error'] = 'Настройки не записаны: цель установки называется config.sample.php — это образец, а не рабочий файл. Укажите верный путь в CMS_CONFIG_FILE.';

        return $result;
    }

    // Именно так, а не tempnam(): tempnam сам создаёт файл с 0600, и проверка chmod стала бы
    // непроверяемой.
    $tmp = dirname($target) . '/.cms-config-' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $content) === false) {
        $result['error'] = 'Не удалось подготовить файл настроек: каталог ' . dirname($target) . ' недоступен для записи.';
        // Временный файл удаляем, если он всё-таки появился на диске.
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        return $result;
    }

    // Права выставляются до rename(): между созданием и закрытием окна виден файл 0644.
    $chmodOk = @chmod($tmp, 0600);

    if (@rename($tmp, $target) === false) {
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

if (setupLocked()) {
    notFound('Сайт уже установлен.');
}

startSession();

// Шаг 1 отделяется отдельной веткой ДО прежней разметки шага 2: при шаге 1 прежняя POST-обработка
// администратора не выполняется. Успешный POST проверяет поля, зондирует базу, атомарно пишет
// config.php и уводит на шаг 2; отказ возвращает ту же форму с прежними значениями из окружения,
// файла и образца — на диск при отказе не пишется ничего.
if (setupStep() === SETUP_STEP_DB) {
    // Предзаполнение без POST: шаг 1 не гейтится setupProblems() — проверка полей и зонд не требуют
    // gd и не пишут в public/. Значения берутся в приоритете «окружение, файл, образец» через
    // уже накормленный config(); db_pass сюда не попадает и в разметку уходит пустым (F9).
    $dbValues = [
        'db_host' => (string) configValue('DB_HOST', ''),
        'db_port' => (string) configValue('DB_PORT', ''),
        'db_name' => (string) configValue('DB_NAME', ''),
        'db_user' => (string) configValue('DB_USER', ''),
        'table_prefix' => (string) configValue('TABLE_PREFIX', ''),
        'site_url' => (string) configValue('SITE_URL', ''),
        'site_name' => (string) configValue('SITE_NAME', ''),
    ];
    $dbErrors = [];

    if (isPost()) {
        csrfCheck();

        $posted = formValues($_POST, SETUP_DB_FIELDS);
        $values = setupNormalizeDb($posted);

        // Введённые значения сохраняются в форме при отказе — кроме пароля базы (прежнее правило F9).
        $dbValues = $values;
        unset($dbValues['db_pass']);

        // Порядок общий: валидация полей → зонд сети → зонд базы → сборка → запись. Отказ любого
        // шага не пишет ни на диск, ни в сессию, не редиректит; install.lock шаг 1 не трогает
        // (замок относится к шагу 2, M6-3).
        $dbErrors = setupValidateDb($values);

        if ($dbErrors === []) {
            $networkError = setupProbeNetwork($values['db_host'], (int) $values['db_port']);

            if ($networkError !== null) {
                $dbErrors['form'] = $networkError;
            } else {
                $dbError = setupProbeDatabase($values);

                if ($dbError !== null) {
                    $dbErrors['form'] = $dbError;
                } else {
                    $config = setupBuildConfig($setupRawConfig, setupSampleConfig(), $values);
                    $written = setupWriteConfig(setupRenderConfig($config), configPath());

                    if ($written['error'] !== '') {
                        $dbErrors['form'] = $written['error'];
                    } else {
                        setupMarkSession($values);

                        // Предупреждение о правах человек видит плашкой на шаге 2 (PRG).
                        if ($written['warning'] !== '') {
                            redirectWithNotice('/setup.php?step=admin', 'config-permissions');
                        }

                        redirect('/setup.php?step=admin');
                    }
                }
            }
        }
    }

    render('setup-db', [
        'errors' => $dbErrors,
        'values' => $dbValues,
    ], [
        'title' => 'Настройка базы данных',
        'noindex' => true,
    ]);

    return;
}

$problems = setupProblems();
$errors = [];
$values = [
    'site_name' => (string) configValue('SITE_NAME', ''),
    'admin_email' => '',
    'admin_name' => '',
    'password' => '',
    'password_again' => '',
];

if (isPost()) {
    csrfCheck();

    // Замок — до любой записи и атомарно (F9): между верхней проверкой setupLocked() и этим
    // местом успевает пройти ровно один победитель, остальные получают «уже установлен».
    $lockHeld = setupAcquireLock();
    if (!$lockHeld && setupLocked()) {
        notFound('Сайт уже установлен.');
    }

    $values = [
        'site_name' => postString('site_name'),
        'admin_email' => postString('admin_email'),
        'admin_name' => postString('admin_name'),
        'password' => postString('password'),
        'password_again' => postString('password_again'),
    ];
    $errors = setupValidate($values);

    if ($errors === [] && $problems === []) {
        try {
            setupApplyBaseline();
            setOption('site_name', trim($values['site_name']));
            setOption('admin_email', mb_strtolower(trim($values['admin_email'])));
            setOption('installed_at', utcStamp(nowUtc()));
            setupCreateAdmin($values);
            setupFinish();
        } catch (Throwable $error) {
            // Срыв установки — не отказ проверки: снимаем занятый замок, чтобы человек
            // повторил попытку (шаг идемпотентен, см. комментарий над setupApplyBaseline).
            if ($lockHeld) {
                setupReleaseLock();
            }

            throw $error;
        }

        // Установщик не входит сам: человек вводит пароль ещё раз на /login, как обычно.
        // Ключ 'site-installed' уже есть в CORE_NOTICE_TEXT (задача 7); 'installed' занят
        // под установку приложения и читается на странице «Приложения».
        redirectWithNotice('/login', 'site-installed');
    }

    // Отказ проверки: замок не должен помнить неудачную попытку — повторный POST проходит.
    if ($lockHeld) {
        setupReleaseLock();
    }
}

// В шаблон уходит белый список полей (F9): пароли — не значение формы, они не возвращаются
// в разметку и не покидают этот скрипт. Плашка уведомления читается из адресной строки: на шаге 2
// она показывает предупреждение о правах 600, пришедшее редиректом с шага 1.
render('setup', [
    'problems' => $problems,
    'errors' => $errors,
    'values' => [
        'site_name' => $values['site_name'],
        'admin_email' => $values['admin_email'],
        'admin_name' => $values['admin_name'],
    ],
    'notice' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? ''),
], [
    'title' => 'Установка сайта',
    'noindex' => true,
]);
