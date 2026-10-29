<?php

declare(strict_types=1);

// Установщик лежит в public/, потому что документ-корень хостинга — именно public/.
// Доступен ровно один раз: после успешной установки появляется install.lock, и любой следующий
// запрос отвечает 404 (критерий §12.1). Файл с хостинга легко удалить, но легко и забыть —
// поэтому замок обязателен, а не желателен.
require dirname(__DIR__) . '/core/bootstrap.php';

const SETUP_REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'gd'];
const SETUP_MIN_PHP = '8.1.0';
const SETUP_PASSWORD_MIN = 10;
const SETUP_STEP_DB = 'db';
const SETUP_STEP_ADMIN = 'admin';
// Белый список полей формы шага 1 (db_pass читается здесь же, но в шаблон не уходит никогда —
// прежнее правило F9). Ключ отметки сессии SETUP_SESSION_DB объявлен в core/setup-probe.php.
const SETUP_DB_FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'table_prefix', 'site_url', 'site_name'];

// Механика шага 1 живёт в `core/` и подключается здесь, до первого вызова: перенесённые функции
// становятся покрываемы набором `tests/unit`, а экрану и ветке шага нужен только их результат.
require dirname(__DIR__) . '/core/setup-config.php';
require dirname(__DIR__) . '/core/setup-probe.php';

/**
 * Помощники сида и шага больше не обязаны быть объявлены до `securityHeaders()`: они объявлены в
 * `core/setup-config.php`, а подключение происходит строкой выше — до первого вызова. Порядок
 * «файл читается до любого `putenv()`» — свойство вызова, а не объявления: `$setupRawConfig` обязан
 * заполниться до `setupSeedConfig()`, и ниже он именно так и стоит.
 */

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

// Замок установщика и вердикт по нему живут в core/setup-probe.php вместе с остальной механикой:
// в документ-корне остаётся поток ветки, а решение по замку покрыто набором tests/unit.

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
                    $assembled = setupCheckAssembled($config);

                    if ($assembled !== []) {
                        // Собранное не записано. Проверка идёт ДО записи, ДО отметки сессии и ДО
                        // редиректа: не-скаляр или пустой обязательный ключ иначе уехал бы на диск
                        // и обнаружился бы только на шаге 2 — после редиректа и с ощущением успеха.
                        // Отказ того же уровня формы, что отказ сети и отказ диска; показывается
                        // первый по порядку обязательных ключей, а список в возврате полный.
                        $dbErrors['form'] = 'Собранные настройки не записаны: ключ ' . $assembled[0] . ' обязан содержать непустое скалярное значение — поправьте его в файле настроек и повторите шаг.';
                    } else {
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
    }

    render('setup-db', [
        'errors' => $dbErrors,
        'values' => $dbValues,
        'notice' => (string) (noticeOfRequest(CORE_NOTICE_TEXT) ?? ''),
    ], [
        'title' => 'Настройка базы данных',
        'noindex' => true,
    ]);

    return;
}

// Гейт шага 2: без отметки перехода с шага 1 второй шаг недоступен. Порядок — предмет решения, а
// не вкуса: стоит ДО csrfCheck() и ДО setupAcquireLock(), поэтому отказ по течению потока не
// тратит токен и не занимается замок — между «проверил отметку» и «создал install.lock» не
// появляется ничего, что пришлось бы откатывать. Проверка одна: наличие отметки, которую ставит
// setupMarkSession(); срока годности (checked_at) в спецификации нет, а непроверяемый порог был бы
// фиктивным свойством. Отметка НЕ снимается ни при отказе шага 2, ни после успеха: закрытие
// установки — дело замка. Прежняя проверка замка (setupLocked()) остаётся выше, до startSession()
// и до разбора шага, — она закрывает оба экрана и здесь не нужна.
if (!isset($_SESSION[SETUP_SESSION_DB])) {
    redirectWithNotice('/setup.php?step=db', 'db-step-required');
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
    // местом успевает пройти ровно один победитель, остальные получают отказ. Отказ обязан
    // случиться при обоих исходах проигравшего: замок стоит — сайт уже установлен; замок
    // держит чужой запрос — продолжать без замка нельзя (M1): замок в этот момент никого
    // не исключает, и установка пошла бы параллельно победителю.
    $verdict = setupLockVerdict();

    if ($verdict === 'installed') {
        notFound('Сайт уже установлен.');
    }

    if ($verdict === 'busy') {
        // 503 с Retry-After: повторная попытка после чужого запроса проходит замок чисто,
        // шаг идемпотентен и ничего не записал до этого места.
        respond(503, 'Установку уже начал другой запрос — подождите минуту и повторите попытку.', [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After' => '60',
            'Cache-Control' => 'no-store',
        ]);
    }

    $lockHeld = $verdict === 'proceed';

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
