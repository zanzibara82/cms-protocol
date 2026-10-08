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

securityHeaders();

function setupLocked(): bool
{
    return is_file(SETUP_LOCK);
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
    file_put_contents(SETUP_LOCK, utcStamp(nowUtc()) . "\n");
    logInfo('setup.finished', ['php' => PHP_VERSION]);
}

if (setupLocked()) {
    notFound('Сайт уже установлен.');
}

startSession();

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

    $values = [
        'site_name' => postString('site_name'),
        'admin_email' => postString('admin_email'),
        'admin_name' => postString('admin_name'),
        'password' => postString('password'),
        'password_again' => postString('password_again'),
    ];
    $errors = setupValidate($values);

    if ($errors === [] && $problems === []) {
        setupApplyBaseline();
        setOption('site_name', trim($values['site_name']));
        setOption('admin_email', mb_strtolower(trim($values['admin_email'])));
        setOption('installed_at', utcStamp(nowUtc()));
        setupCreateAdmin($values);
        setupFinish();

        // Установщик не входит сам: человек вводит пароль ещё раз на /login, как обычно.
        // Ключ 'site-installed' уже есть в CORE_NOTICE_TEXT (задача 7); 'installed' занят
        // под установку приложения и читается на странице «Приложения».
        redirectWithNotice('/login', 'site-installed');
    }
}

render('setup', ['problems' => $problems, 'errors' => $errors, 'values' => $values], [
    'title' => 'Установка сайта',
    'noindex' => true,
]);
