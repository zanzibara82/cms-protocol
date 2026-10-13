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
 * без файла настроек на диске. Для каждого обязательного ключа приоритет — значение из файла, иначе
 * значение образца; пустое не сидится. Ядро core/config-loader.php при этом не меняется.
 *
 * @param array<string, mixed> $raw
 */
function setupSeedConfig(array $raw): void
{
    $sample = setupSampleConfig();

    foreach (CONFIG_REQUIRED_KEYS as $key) {
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

if (setupLocked()) {
    notFound('Сайт уже установлен.');
}

startSession();

// Шаг 1 отделяется отдельной веткой ДО прежней разметки шага 2: при шаге 1 прежняя POST-обработка
// администратора не выполняется. Здесь параметры только собираются — запись config.php, зонд базы
// и отметка сессии перехода появятся в M6-2 и M6-3, поэтому корректный POST шага 1 возвращает ту же
// форму с прежними значениями из файла и образца, ничего не сохраняя и не трогая базу.
if (setupStep() === SETUP_STEP_DB) {
    $dbErrors = [];

    if (isPost()) {
        csrfCheck();
    }

    render('setup-db', [
        'errors' => $dbErrors,
        'values' => [
            // Белый список значений формы: db_pass сюда не попадает и в разметку уходит пустым.
            'db_host' => (string) configValue('DB_HOST', ''),
            'db_port' => (string) configValue('DB_PORT', ''),
            'db_name' => (string) configValue('DB_NAME', ''),
            'db_user' => (string) configValue('DB_USER', ''),
            'table_prefix' => (string) configValue('TABLE_PREFIX', ''),
            'site_url' => (string) configValue('SITE_URL', ''),
            'site_name' => (string) configValue('SITE_NAME', ''),
        ],
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
// в разметку и не покидают этот скрипт.
render('setup', [
    'problems' => $problems,
    'errors' => $errors,
    'values' => [
        'site_name' => $values['site_name'],
        'admin_email' => $values['admin_email'],
        'admin_name' => $values['admin_name'],
    ],
], [
    'title' => 'Установка сайта',
    'noindex' => true,
]);
