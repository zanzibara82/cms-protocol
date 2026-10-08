### Задача 6: Сессии, вход и право редактора

**Files:**
- Create: `core/session.php`, `core/auth.php`, `core/routes.php`, `theme/login.php`
- Modify: `core/bootstrap.php` (подключение `session.php`, `auth.php`, `routes.php`)
- Create: `tests/unit/PasswordTest.php`, `tests/db/LoginLimitTest.php`, `tests/db/SessionRevocationTest.php`,
  `tests/http/LoginTest.php`, `tests/guard/ClientIpTest.php`

**Interfaces:**
- Consumes: `db()`, `insert()`, `one()`, `run()`, `value()`, `all()` из задачи 3; `nowUtc()`/`utcStamp()`,
  `fromDb()` из задачи 2; `clientIp()` из задачи 5; `render()`, `redirect()`, `forbidden()`; `newId()`.
- Produces:
  - `startSession(): void`, `sessionUserId(): ?string`, `sessionVersion(): int`;
  - `hashPassword(string $plain, ?string $algo = null): string`, `passwordAlgorithm(): string`,
    `verifyPassword(string $plain, string $hash): bool`, `needsRehash(string $hash): bool`;
  - `loginBlocked(string $email, string $ip, ?DateTimeImmutable $now = null): bool`,
    `recordLoginAttempt(string $email, string $ip, ?DateTimeImmutable $now = null): void`;
  - `attemptLogin(string $email, string $password): array` — `{ok: true, user: array}` или
    `{ok: false, error: string}`;
  - `loginUser(array $user): void`, `logoutUser(): void`, `currentUser(): ?array`,
    `roleGrantsManage(?array $account): bool`, `getEditorAccess(): array` —
    `['kind' => 'granted', 'editor' => ['id' => string, 'email' => string]]` или `['kind' => 'denied']`;
  - `registerCoreRoutes(): void` — маршруты `/login` (GET, POST) и `/logout` (POST);
  - `isInternalNextPath(string $next): bool` — куда пускаем после входа: одиночный «/» в начале, второй
    символ не «/» и не «\», внутри нет «://» (иначе `?next=/\evil.com` сводится браузером к чужому хосту);
  - константы `LOGIN_LIMIT_PER_EMAIL = 5`, `LOGIN_WINDOW_EMAIL = 15`, `LOGIN_LIMIT_PER_IP = 20`,
    `LOGIN_WINDOW_IP = 60`, `ROLES = ['admin' => 'admin', 'editor' => 'editor']`.

- [ ] **Шаг 1: Пишем падающие тесты паролей**

`tests/unit/PasswordTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/auth.php';

final class PasswordTest extends TestCase
{
    public function testХешПроверяетсяИНеРавенПаролю(): void
    {
        $hash = hashPassword('проверочный-пароль');

        self::assertNotSame('проверочный-пароль', $hash);
        self::assertTrue(verifyPassword('проверочный-пароль', $hash));
        self::assertFalse(verifyPassword('другой-пароль', $hash));
    }

    public function testВыборАлгоритмаЗависитОтПоддержкиArgon2id(): void
    {
        $supported = defined('PASSWORD_ARGON2ID');

        self::assertSame($supported ? 'argon2id' : 'bcrypt', passwordAlgorithm());
        self::assertStringStartsWith($supported ? '$argon2id$' : '$2y$', hashPassword('проверочный-пароль'));
    }

    public function testЯвныйАлгоритмИспользуетсяДляПроверкиВторойВетки(): void
    {
        // На dev-контейнере Argon2id может не быть: вторая ветка обязана работать всегда.
        $bcrypt = hashPassword('проверочный-пароль', PASSWORD_BCRYPT);

        self::assertStringStartsWith('$2y$', $bcrypt);
        self::assertTrue(verifyPassword('проверочный-пароль', $bcrypt));
    }

    public function testХешСтарогоАлгоритмаПомечаетсяКОбновлению(): void
    {
        $bcrypt = hashPassword('проверочный-пароль', PASSWORD_BCRYPT);

        if (defined('PASSWORD_ARGON2ID')) {
            self::assertTrue(needsRehash($bcrypt), 'bcrypt-хеш нужно пересчитать в argon2id при входе');
        } else {
            self::assertFalse(needsRehash($bcrypt));
        }

        self::assertFalse(needsRehash(hashPassword('проверочный-пароль')));
    }

    public function testРолиАдминистратораИРедактораДаютДоступАУченикаНет(): void
    {
        self::assertTrue(roleGrantsManage(['role' => 'admin', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertTrue(roleGrantsManage(['role' => 'editor', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertFalse(roleGrantsManage(['role' => 'student', 'email' => 'a@b.c', 'blocked' => false]));
        self::assertFalse(roleGrantsManage(['role' => 'admin', 'email' => 'a@b.c', 'blocked' => true]));
        self::assertFalse(roleGrantsManage(null));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter PasswordTest`
Expected: FAIL — `hashPassword()` не определена.

- [ ] **Шаг 2: Реализуем пароли и право**

`core/auth.php`:

```php
<?php

declare(strict_types=1);

// Роли пилота: администратор может всё, редактор правит разделы контента. Ученик и гость — нет.
const ROLES = ['admin' => 'admin', 'editor' => 'editor'];

// Лимиты подбора пароля. Числа из спецификации §7: 5 попыток на адрес за 15 минут,
// 20 на IP за час. Окно задаётся в минутах.
const LOGIN_LIMIT_PER_EMAIL = 5;
const LOGIN_WINDOW_EMAIL = 15;
const LOGIN_LIMIT_PER_IP = 20;
const LOGIN_WINDOW_IP = 60;

const LOGIN_ERROR_GENERIC = 'Почта или пароль не подошли. Проверьте раскладку и попробуйте ещё раз.';
const LOGIN_ERROR_BLOCKED = 'Слишком много попыток входа. Подождите немного и попробуйте снова.';

function passwordAlgorithm(): string
{
    return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
}

function passwordAlgoConstant(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

// Алгоритм можно передать явно: так тест проверяет обе ветки выбора, не пересобирая PHP.
function hashPassword(string $plain, ?string $algo = null): string
{
    return password_hash($plain, $algo ?? passwordAlgoConstant());
}

function verifyPassword(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

function needsRehash(string $hash): bool
{
    return password_needs_rehash($hash, passwordAlgoConstant());
}

function roleGrantsManage(?array $account): bool
{
    if ($account === null || (bool) ($account['blocked'] ?? false)) {
        return false;
    }

    return in_array((string) ($account['role'] ?? ''), [ROLES['admin'], ROLES['editor']], true);
}

// Одна формулировка «кто правит контентом»: её читают и гейт маршрута, и POST-обработчики.
// Аноним получает редирект на /login (для GET) или 403 (для приватного пути админки раздела).
/** @return array{kind: 'granted', editor: array{id: string, email: string}}|array{kind: 'denied'} */
function getEditorAccess(): array
{
    $user = currentUser();
    if ($user === null) {
        return ['kind' => 'denied'];
    }

    $account = [
        'role' => (string) $user['role'],
        'email' => (string) $user['email'],
        'blocked' => $user['blocked_at'] !== null,
    ];

    if (!roleGrantsManage($account)) {
        logWarn('access.manage_denied', ['userId' => (string) $user['id'], 'role' => $account['role']]);

        return ['kind' => 'denied'];
    }

    return ['kind' => 'granted', 'editor' => ['id' => (string) $user['id'], 'email' => $account['email']]];
}

function currentUser(): ?array
{
    $id = sessionUserId();
    if ($id === null) {
        return null;
    }

    $row = one(
        'SELECT id, email, name, role, session_version, blocked_at FROM ' . t('user') . ' WHERE id = ?',
        [$id]
    );
    if ($row === null) {
        return null;
    }

    // Отзыв сессии: номер в куке ниже номера в базе — человек разлогинен, даже если кука жива.
    if ((int) $row['session_version'] !== sessionVersion()) {
        logoutUser();

        return null;
    }

    return $row;
}

// Подбор пароля. Попытки пишутся в tbl_login_attempt и читаются только из неё: лимит по IP
// считает REMOTE_ADDR, поэтому за прокси все посетители делят один лимит — это сознательно.
function loginBlocked(string $email, string $ip, ?DateTimeImmutable $now = null): bool
{
    $moment = $now ?? nowUtc();

    $byEmail = (int) value(
        'SELECT COUNT(*) FROM ' . t('login_attempt') . ' WHERE email = ? AND attempted_at > ?',
        [mb_strtolower(trim($email)), utcStamp($moment->sub(new DateInterval('PT' . LOGIN_WINDOW_EMAIL . 'M')))]
    );
    if ($byEmail >= LOGIN_LIMIT_PER_EMAIL) {
        return true;
    }

    $byIp = (int) value(
        'SELECT COUNT(*) FROM ' . t('login_attempt') . ' WHERE ip = ? AND attempted_at > ?',
        [$ip, utcStamp($moment->sub(new DateInterval('PT' . LOGIN_WINDOW_IP . 'M')))]
    );

    return $byIp >= LOGIN_LIMIT_PER_IP;
}

function recordLoginAttempt(string $email, string $ip, ?DateTimeImmutable $now = null): void
{
    insert('login_attempt', [
        'email' => mb_strtolower(trim($email)),
        'ip' => $ip,
        'attempted_at' => utcStamp($now ?? nowUtc()),
    ]);
}

/** @return array{ok: true, user: array<string, mixed>}|array{ok: false, error: string} */
function attemptLogin(string $email, string $password): array
{
    $clean = mb_strtolower(trim($email));
    $ip = clientIp();

    if ($clean === '' || $password === '') {
        return ['ok' => false, 'error' => LOGIN_ERROR_GENERIC];
    }

    if (loginBlocked($clean, $ip)) {
        logWarn('auth.blocked', ['email' => $clean, 'ip' => $ip]);

        return ['ok' => false, 'error' => LOGIN_ERROR_BLOCKED];
    }

    $row = one('SELECT * FROM ' . t('user') . ' WHERE email = ?', [$clean]);
    if ($row === null || !verifyPassword($password, (string) $row['password_hash'])) {
        recordLoginAttempt($clean, $ip);
        logWarn('auth.failed', ['email' => $clean, 'ip' => $ip]);

        return ['ok' => false, 'error' => LOGIN_ERROR_GENERIC];
    }

    if ($row['blocked_at'] !== null) {
        return ['ok' => false, 'error' => 'Аккаунт заблокирован. Напишите администратору.'];
    }

    if (needsRehash((string) $row['password_hash'])) {
        run('UPDATE ' . t('user') . ' SET password_hash = ? WHERE id = ?', [hashPassword($password), $row['id']]);
    }

    loginUser($row);
    logInfo('auth.login', ['userId' => (string) $row['id']]);

    return ['ok' => true, 'user' => $row];
}
```

`core/session.php`:

```php
<?php

declare(strict_types=1);

// Флаги куки из спецификации §7: без JavaScript, только для своего сайта, secure при HTTPS.
// use_strict_mode не даёт серверу принять принесённый извне идентификатор сессии.
const SESSION_LIFETIME_SECONDS = 8 * 60 * 60;

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME_SECONDS);
    session_name('cms_session');
    session_start();
}

function sessionUserId(): ?string
{
    $id = $_SESSION['user_id'] ?? null;

    return is_string($id) && $id !== '' ? $id : null;
}

function sessionVersion(): int
{
    return (int) ($_SESSION['session_version'] ?? 0);
}

function loginUser(array $user): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
}

function logoutUser(): void
{
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// Отзыв всех сессий пользователя: номер в базе поднимается, и живые куки перестают подходить.
function revokeSessions(string $userId): void
{
    run('UPDATE ' . t('user') . ' SET session_version = session_version + 1 WHERE id = ?', [$userId]);
}
```

- [ ] **Шаг 3: Тесты лимитов входа на базе**

`tests/db/LoginLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginLimitTest extends TestCase
{
    private const NOW = '2026-10-06 06:00:00';

    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
    }

    private function now(): DateTimeImmutable
    {
        return fromDb(self::NOW) ?? nowUtc();
    }

    public function testПятьНеудачНаАдресЗакрываютВход(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $this->now());
        }

        self::assertTrue(loginBlocked('redaktor@school.dev', '203.0.113.99', $this->now()), 'Лимит по адресу действует с любого IP');
    }

    public function testЧетыреНеудачиЕщёНеБлокируют(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL - 1; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $this->now());
        }

        self::assertFalse(loginBlocked('redaktor@school.dev', '203.0.113.7', $this->now()));
    }

    public function testПопыткиСтаршеПятнадцатиМинутНеСчитаются(): void
    {
        $old = $this->now()->sub(new DateInterval('PT16M'));
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            recordLoginAttempt('redaktor@school.dev', '203.0.113.7', $old);
        }

        self::assertFalse(loginBlocked('redaktor@school.dev', '203.0.113.7', $this->now()));
    }

    public function testДвадцатьПопытокСОдногоАйпиЗакрываютВходДляВсех(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_IP; $i++) {
            recordLoginAttempt('kto-to-' . $i . '@school.dev', '203.0.113.7', $this->now());
        }

        self::assertTrue(loginBlocked('drugoy@school.dev', '203.0.113.7', $this->now()));
        self::assertFalse(loginBlocked('drugoy@school.dev', '203.0.113.8', $this->now()));
    }

    public function testНеверныйПарольПишетПопыткуИОтдаётОдинИТотЖеТекст(): void
    {
        TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        $wrong = attemptLogin('redaktor@school.dev', 'неверный');
        $absent = attemptLogin('net-takogo@school.dev', 'неверный');

        self::assertFalse($wrong['ok']);
        self::assertSame($wrong['error'], $absent['error'], 'Текст не должен выдавать, есть ли такой адрес');
        self::assertSame(2, (int) value('SELECT COUNT(*) FROM ' . t('login_attempt'), []));
    }

    public function testВерныйПарольВпускаетРедактора(): void
    {
        TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        // Адрес отличается от сидового только регистром и пробелами по краям: перед сверкой с
        // базой он нормализуется, и вход обязан выпустить того же редактора.
        $result = attemptLogin('  Redaktor@School.Dev  ', 'верный-пароль');
        $clean = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertTrue($result['ok'], 'Регистр и краевые пробелы не мешают входу');
        self::assertSame('redaktor@school.dev', $result['user']['email']);
        self::assertFalse($clean['ok'] === false);
        self::assertSame('redaktor@school.dev', $clean['user']['email']);
    }

    public function testЗаблокированныйАккаунтНеВпускаетДажеСВернымПаролем(): void
    {
        $id = TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');
        run('UPDATE ' . t('user') . ' SET blocked_at = ? WHERE id = ?', [utcStamp(nowUtc()), $id]);

        $result = attemptLogin('redaktor@school.dev', 'верный-пароль');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('заблокирован', $result['error']);
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite db`
Expected: PASS. Тест «Неверный пароль…» ожидает 2 строки: `attemptLogin()` с пустым адресом не пишет попытку,
а две неудачи — пишут.

Тем же набором проверяется и ветка отзыва сессий. Пока `session_version` везде ноль, сравнение версий в
`currentUser()` не выполняется ни в одном прогоне, а это security-ветка: её надо закрыть здесь, а не ждать
задачи 8, которая подключит вызов `revokeSessions()`.

`tests/db/SessionRevocationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

// Отзыв сессий. currentUser() сверяет номер версии из сессии с номером в базе и разлогинивает,
// если они разошлись. Пока версия везде нулевая, ветка не выполнялась ни в одном прогоне,
// поэтому этот тест закрывает именно её: живая кука после revokeSessions() не даёт доступа.
final class SessionRevocationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
        $_SESSION = [];
    }

    public function testОтзывСессийВыкидываетВошедшегоРанее(): void
    {
        $id = TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');

        // Раскладываем сессию ровно так, как это делает loginUser(): id вошедшего и его версия.
        startSession();
        $_SESSION['user_id'] = $id;
        $_SESSION['session_version'] = 0;

        self::assertSame(0, sessionVersion(), 'В сессии лежит нулевая версия аккаунта');
        self::assertNotNull(currentUser(), 'До отзыва вошедший редактор виден');

        revokeSessions($id);

        self::assertSame(
            1,
            (int) value('SELECT session_version FROM ' . t('user') . ' WHERE id = ?', [$id]),
            'Отзыв поднимает номер версии в базе'
        );
        self::assertNull(currentUser(), 'Кука со старой версией разлогинена');
        self::assertNull(sessionUserId(), 'Разлогинивание очищает сессию');
    }

    public function testСовпавшаяВерсияВсёЕщёВпускаетРедактора(): void
    {
        $id = TestDb::seedUser('redaktor@school.dev', 'верный-пароль', 'editor');
        revokeSessions($id);

        // Тот же самый шаг, но версия в сессии актуальна: ветка отзыва срабатывает по числам,
        // а не выкидывает всякого вошедшего.
        startSession();
        $_SESSION['user_id'] = $id;
        $_SESSION['session_version'] = 1;

        self::assertNotNull(currentUser(), 'Актуальная версия пускает редактора');
        self::assertSame('granted', getEditorAccess()['kind'], 'Гейт правки после совпавшей версии открыт');
    }
}
```

Второй тест — контрольный: он обязателен, иначе первый можно было бы пройти «разлогиниваем всех подряд».

- [ ] **Шаг 4: Форма входа и маршруты**

`core/routes.php`:

```php
<?php

declare(strict_types=1);

// Маршруты ядра. Разделы добавляют свои в apps/<id>/routes.php — ядро их не знает.
function registerCoreRoutes(): void
{
    addRoute('GET', '/', 'coreHome');
    addRoute('GET', '/login', 'coreLoginPage');
    addRoute('POST', '/login', 'coreLoginSubmit');
    addRoute('POST', '/logout', 'coreLogout');
}

function coreHome(array $params = []): void
{
    render('home', [], [
        'title' => (string) configValue('SITE_NAME', 'Школа'),
        'description' => 'Новости, курсы и объявления школы английского языка.',
    ]);
}

function coreLoginPage(array $params = []): void
{
    if (currentUser() !== null) {
        redirect('/admin');
    }

    render('login', ['error' => '', 'email' => queryString('email', '')], ['title' => 'Вход', 'noindex' => true]);
}

function coreLoginSubmit(array $params = []): void
{
    $email = postString('email');
    $result = attemptLogin($email, postString('password'));

    if ($result['ok'] === false) {
        // Введённый адрес возвращается в форму, текст ошибки — общий: он не выдаёт, есть ли аккаунт.
        render('login', ['error' => (string) $result['error'], 'email' => $email], ['title' => 'Вход', 'noindex' => true]);

        return;
    }

    $next = queryString('next', '');
    redirect(is_string($next) && isInternalNextPath($next) ? $next : '/admin');
}

function coreLogout(array $params = []): void
{
    logoutUser();
    redirectWithNotice('/', 'signed-out');
}

// Куда пускаем после входа: только внутренний путь, который начинается с одного «/» и дальше не
// содержит ни «/», ни обратного слэша — браузер сводит «//host» и «/\host» к адресу чужого сайта.
// «://» внутри строки — прямая ссылка наружу, её тоже отклоняем целиком.
function isInternalNextPath(string $next): bool
{
    if ($next === '' || !str_starts_with($next, '/')) {
        return false;
    }

    $second = substr($next, 1, 1);
    if ($second === '/' || $second === '\\') {
        return false;
    }

    return !str_contains($next, '://');
}
```

CSRF здесь намеренно не подключён: `core/csrf.php` появляется в задаче 7, и шаблон входа с `csrfField()` до неё
не открылся бы — страница падала бы на неопределённой функции. Шаг 5 задачи 7 вставит `csrfCheck()` первым
обращением в оба обработчика и добавит поле токена в форму.

`theme/login.php`:

```php
<?php

declare(strict_types=1);

/** @var string $error */
/** @var string $email */
?>
<section class="mx-auto max-w-md py-10">
    <h1 class="text-2xl font-bold">Вход для редактора</h1>
    <p class="mt-2 text-sm text-ink/70">Раздел открыт администратору и редактору контента.</p>

    <?php if ($error !== ''): ?>
        <p class="mt-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="mt-6 space-y-4" method="post" action="/login">
        <label class="block text-sm">
            <span class="font-medium">Почта</span>
            <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="email" name="email"
                   value="<?= e($email) ?>" autocomplete="username" required maxlength="190">
        </label>
        <label class="block text-sm">
            <span class="font-medium">Пароль</span>
            <input class="mt-1 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" type="password" name="password"
                   autocomplete="current-password" required>
        </label>
        <button class="min-h-[44px] w-full rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90" type="submit">Войти</button>
    </form>
</section>
```

Зоны нажатия: класс `min-h-[44px]` на обоих полях и на кнопке — требование глобального ограничения
(≥44 px) и сложившаяся конвенция темы (`theme/partials/header.php`). Utility уже скомпилирован в
`public/assets/app.css` со задачи 4, поэтому пересборки CSS эта задача не требует; http-тест шага 5
проверяет класс в отдаваемой разметке.

`public/index.php` в этой задаче дорабатывается: к строкам задачи 5 (`securityHeaders()`, `addRoute('GET', '/')`,
`dispatch()`) добавляются `startSession()` и `registerCoreRoutes()` — перед `dispatch()`, иначе обработчик `/login`
не увидит сессию. Готовый блок на этот момент приведён в задаче 5 («К концу задачи 6»): здесь он просто вставляется
целиком. Временная строка `addRoute('GET', '/', …)` из задачи 5 удаляется: домашний маршрут объявляет
`registerCoreRoutes()`, иначе дубль перекрывал бы `coreHome()`. `registerAppRoutes()` появится в задаче 8.

`core/bootstrap.php` дополняется подключениями (после `core/id.php`) — только теми файлами, которые существуют
к концу этой задачи:

```php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/router.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/routes.php';
```

`csrf.php`, `notice.php` и `form.php` появляются только в задаче 7 — её шаг 2 добавит три подключения сразу после
`session.php`. Подключить их здесь значило бы получить `require_once: file not found` на первом же запросе.

Порядок внутри этой задачи важен в одном месте: `routes.php` обязан идти после `auth.php`, `request.php` и
`router.php`, потому что его top-level-блок регистрирует обработчики через `addRoute()`.

- [ ] **Шаг 5: HTTP-тесты входа**

`tests/http/LoginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\http;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
        Kernel::start();
    }

    public static function tearDownAfterClass(): void
    {
        Kernel::stop();
    }

    protected function setUp(): void
    {
        Kernel::freshCookies();

        // Каждый тест начинается сам: журнал попыток очищается, аккаунт сидируется заново.
        // Без этого тесты зависели бы друг от друга — например, «подставной заголовок» проходил
        // только потому, что до него пять неудач записал предыдущий тест.
        TestDb::clearTable('login_attempt');
        TestDb::clearTable('user');
        TestDb::seedUser('admin@school.dev', 'верный-пароль', 'admin');
    }

    private function token(): string
    {
        return $this->tokenFrom(Kernel::get('/login')['body']);
    }

    /** Пять неудач своими запросами: лимит по адресу исчерпан независимо от порядка тестов. */
    private function exhaustLoginLimit(): void
    {
        for ($i = 0; $i < LOGIN_LIMIT_PER_EMAIL; $i++) {
            Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        }
    }

    public function testСтраницаВходаОткрытаАнониму(): void
    {
        $page = Kernel::get('/login');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Вход для редактора', $page['body']);
        self::assertStringContainsString('method="post"', $page['body'], 'Форма с паролем обязана быть POST');
        self::assertStringContainsString('noindex, nofollow', $page['body']);
        // Минимум проекта — зона нажатия 44 px: каждый контрол экрана обязан нести этот класс.
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg border border-ink/20', $page['body'], 'Поля входа не меньше 44 px');
        self::assertStringContainsString('min-h-[44px] w-full rounded-lg bg-ink', $page['body'], 'Кнопка входа не меньше 44 px');
    }

    public function testВерныйПарольПереноситВАдминку(): void
    {
        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location']);
    }

    public function testНеверныйПарольПоказываетТотЖеТекстЧтоИЧужойАдрес(): void
    {
        $wrong = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'мимо']);
        $absent = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'net@school.dev', 'password' => 'мимо']);

        self::assertSame(200, $wrong['status']);
        self::assertStringContainsString('Почта или пароль не подошли', $wrong['body']);
        self::assertStringContainsString('Почта или пароль не подошли', $absent['body']);
    }

    public function testПослеПятиНеудачВходЗакрывается(): void
    {
        $this->exhaustLoginLimit();

        $response = Kernel::post('/login', ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']);

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    public function testВраждебныйNextНеУводитНаЧужойДомен(): void
    {
        // Обратный слэш сразу после одиночного «/» браузер сводит к «//evil.com», то есть к чужому
        // хосту. После входа в Location обязан остаться внутренний путь, а не адрес из ссылки.
        $response = Kernel::post(
            '/login?next=/\\evil.com',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/admin', $response['headers']['location'], 'Открытый редирект закрыт: пускаем только внутренний путь');
        self::assertStringNotContainsString('evil.com', $response['headers']['location']);
    }

    public function testВнутреннийNextПослеВходаСохраняется(): void
    {
        // Фильтр не должен выкидывать и законную глубинную ссылку на свой сайт.
        $response = Kernel::post(
            '/login?next=/news',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/news', $response['headers']['location']);
    }

    public function testПодставнойЗаголовокАдресаНеСнимаетЛимит(): void
    {
        $this->exhaustLoginLimit();

        // Даже с «другим» заголовком переадресации сервер считает адрес из серверного массива: лимит остаётся.
        $response = Kernel::post(
            '/login',
            ['_token' => $this->token(), 'email' => 'admin@school.dev', 'password' => 'верный-пароль'],
            ['headers' => ['X-Forwarded-For' => '198.51.100.4']]
        );

        self::assertStringContainsString('Слишком много попыток входа', $response['body']);
    }

    // Задачей 8 этот помощник пригодится тесту выхода: там токен берётся со страницы админки.
    private function tokenFrom(string $body): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $body, $m);

        return $m[1] ?? '';
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter LoginTest`
Expected: PASS. Красным этот набор был до шага 4: маршрутов `/login` и `/logout` в реестре ещё нет, страница входа
не собрана.

Здесь семь тестов, и все они работают без `/admin` (задача 8) и без CSRF (задача 7):
- тест «Выход гасит сессию» добавляется шагом 13 задачи 8 — он читает токен со страницы админки;
- тест «Глубинная ссылка переживает POST формы» добавляется шагом 5 задачи 7 — до него скрытого поля `next`
  в форме нет, и восьмым тестом набор становится вместе с токеном;
- `token()` до задачи 7 возвращает пустую строку, потому что поля `_token` в форме ещё нет. Пока `coreLoginSubmit()`
  не вызывает `csrfCheck()`, лишний ключ в POST безвреден, а после шага 5 задачи 7 токен становится настоящим —
  тесты править не нужно, они продолжают проверять ровно то же;
- каждый тест пересоздаёт своё состояние в `setUp()`: порядок выполнения (в том числе `--order=random`) не влияет
  на результат, а лимит по общему IP не накапливается через класс — на одном тесте максимум пять попыток;
- два теста на `next` существуют потому, что формулировка guards из первоначального текста плана
  (`str_starts_with($next, '/') && !str_starts_with($next, '//')`) пропускала `/\evil.com`: после входа такой
  адрес оказывался в `Location:` и уводил пользователя на чужой домен.

- [ ] **Шаг 6: Страж чтения адреса клиента**

`tests/guard/ClientIpTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Лимиты входа и журнал читают адрес клиента. Если взять его из заголовка, любой посетитель
// обойдёт блокировку одной строчкой в curl — правило из спецификации §7.
final class ClientIpTest extends TestCase
{
    private const FORBIDDEN_HEADERS = ['x-forwarded-for', 'x-real-ip', 'client-ip', 'x-client-ip', 'forwarded'];

    public function testКодНеЧитаетАдресИзПодставляемыхЗаголовков(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2);

        foreach (['core', 'admin', 'theme', 'apps', 'public'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }
                $code = strtolower((string) file_get_contents($item->getPathname()));
                foreach (self::FORBIDDEN_HEADERS as $header) {
                    if (str_contains($code, $header)) {
                        $violations[] = $item->getPathname() . ': ' . $header;
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Адрес клиента берётся только из REMOTE_ADDR');
    }

    public function testЕдинственныйИсточникАдресаЭтоRemoteAddr(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/core/request.php');

        self::assertStringContainsString("REMOTE_ADDR", $code);
        self::assertSame(1, preg_match_all('/REMOTE_ADDR/', $code), 'clientIp() читает REMOTE_ADDR один раз');
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS.

- [ ] **Шаг 7: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: зелёные все наборы. Из `http/LoginTest.php` до задачи 8 не добавляется только тест «Выход гасит сессию» —
он читает `/admin`, которого ещё нет (шаг 5).

```bash
git add core/session.php core/auth.php core/routes.php core/bootstrap.php theme/login.php public/index.php tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "Вход и право редактора: сессии с безопасными флагами, лимиты подбора пароля, отзыв по session_version"
```

---

### Задача 7: CSRF, уведомления и помощники форм

**Files:**
- Create: `core/csrf.php`, `core/notice.php`, `core/form.php`, `core/ResponseFinished.php`
- Create: `tests/unit/FormValuesTest.php`, `tests/db/CsrfTest.php`, `tests/guard/CsrfCoverageTest.php`
- Modify: `core/bootstrap.php` (подключение трёх файлов), `core/routes.php` (`csrfCheck()` во вход и выход,
  глубинная ссылка через скрытое поле), `theme/login.php` (скрытые поля токена и `next`),
  `core/response.php` и `tests/bootstrap.php` (сигнал вместо `exit` под PHPUnit),
  `tests/http/LoginTest.php` (тест, что `?next=` переживает POST формы)

**Interfaces:**
- Consumes: `startSession()`, `postString()`, `respond()`, `logWarn()`, `redirectWithNotice()`,
  `isInternalNextPath()` из задачи 6, обработчики `coreLoginSubmit()` и `coreLogout()` из задачи 6.
- Produces:
  - `csrfToken(): string`, `csrfField(): string`, `csrfCheck(): void`;
  - `Cms\Core\ResponseFinished extends \RuntimeException` — сигнал завершённого ответа для тестов,
    константа `PHPUNIT_RUNNING` определена только в `tests/bootstrap.php`;
  - `noticeText(string $key, array $dictionary): ?string`, `noticeOfRequest(array $dictionary): ?string`,
    `NOTICE_UNKNOWN_KEY` — не ключ, а правило: неизвестный ключ отдаёт `null`;
  - `formValues(array $raw, array $fields): array`, `isOn(mixed $value): bool`,
    `fieldError(array $errors, string $field): string`, `formError(string $message): array`,
    `errorResult(array $errors, array $values): array`;
  - словарь ядра `CORE_NOTICE_TEXT` с ключами `signed-out` и `site-installed` (последний нужен
    установщику из задачи 9, чтобы не печатать текст «приложение установлено»).

- [ ] **Шаг 1: Пишем падающие тесты помощников формы**

`tests/unit/FormValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/form.php';
require_once dirname(__DIR__, 2) . '/core/notice.php';

final class FormValuesTest extends TestCase
{
    public function testВозвратВведённогоСодержитВсеПоляСписка(): void
    {
        $values = formValues(['title' => 'Заголовок', 'lead' => null], ['title', 'lead', 'cover']);

        self::assertSame(['title' => 'Заголовок', 'lead' => '', 'cover' => ''], $values);
    }

    public function testЛишниеКлючиНеПопадаютВВозврат(): void
    {
        $values = formValues(['title' => 'Да', '_token' => 'секрет', 'password' => 'чужое'], ['title']);

        self::assertSame(['title' => 'Да'], $values);
    }

    public function testЧекбоксСчитаетсяВключённымПоТрёмЗначениям(): void
    {
        self::assertTrue(isOn('on'));
        self::assertTrue(isOn('1'));
        self::assertTrue(isOn('true'));
        self::assertFalse(isOn(''));
        self::assertFalse(isOn('off'));
        self::assertFalse(isOn(null));
    }

    public function testОшибкаПоляПустаЕслиОшибкиНет(): void
    {
        self::assertSame('Заголовок короче 3 символов.', fieldError(['title' => 'Заголовок короче 3 символов.'], 'title'));
        self::assertSame('', fieldError([], 'title'));
    }

    public function testОтказФормыНесётТекстИПустыеЗначения(): void
    {
        self::assertSame(['errors' => ['form' => 'Не получилось'], 'values' => []], formError('Не получилось'));
    }

    public function testОтказПоПолямНесётИОшибкиИВведённое(): void
    {
        $result = errorResult(['slug' => 'Занято'], ['slug' => 'novost']);

        self::assertSame('Занято', $result['errors']['slug']);
        self::assertSame('novost', $result['values']['slug']);
    }

    public function testСловарьУведомленийОтдаётТекстПоКлючу(): void
    {
        self::assertSame('Вы вышли из аккаунта.', noticeText('signed-out', CORE_NOTICE_TEXT));
        self::assertNull(noticeText('несуществующий', CORE_NOTICE_TEXT));
    }

    public function testНеизвестныйКлючНеПечатаетТекстАдреснойСтроки(): void
    {
        // Иначе ?notice=<script> стал бы дырой в разметке.
        self::assertNull(noticeText('<script>alert(1)</script>', CORE_NOTICE_TEXT));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter FormValuesTest`
Expected: FAIL — функции не определены.

- [ ] **Шаг 2: Реализуем помощники форм и уведомления**

`core/form.php`:

```php
<?php

declare(strict_types=1);

// Отказ сохранения обязан вернуть человеку набранный текст: форма, которая очищается после
// ошибки, заставляет писать материал заново. Правило одно для всех разделов.

/**
 * @param array<string, mixed> $raw
 * @param list<string> $fields
 * @return array<string, string>
 */
function formValues(array $raw, array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $value = $raw[$field] ?? '';
        $values[$field] = is_string($value) ? $value : '';
    }

    return $values;
}

function isOn(mixed $value): bool
{
    return $value === 'on' || $value === 'true' || $value === '1';
}

/** @param array<string, string> $errors */
function fieldError(array $errors, string $field): string
{
    return (string) ($errors[$field] ?? '');
}

/** @return array{errors: array<string, string>, values: array<string, string>} */
function formError(string $message): array
{
    return ['errors' => ['form' => $message], 'values' => []];
}

/** @return array{errors: array<string, string>, values: array<string, string>} */
function errorResult(array $errors, array $values): array
{
    return ['errors' => $errors, 'values' => $values];
}
```

`core/notice.php`:

```php
<?php

declare(strict_types=1);

// Ключ уведомления приходит из адресной строки, а текст — из словаря раздела. Неизвестный ключ
// не печатается: иначе ?notice=<script> стал бы дырой. Фильтр notice_text даёт приложению
// подменить формулировку, не трогая словарь.
const CORE_NOTICE_TEXT = [
    'signed-out' => 'Вы вышли из аккаунта.',
    // Ключ установки сайта отличается от 'installed' (установлено приложение): словари один,
    // а смыслы разные, и тексты должны различаться.
    'site-installed' => 'Сайт установлен. Войдите адресом администратора.',
    'installed' => 'Приложение установлено.',
    'updated' => 'Приложение обновлено.',
    'enabled' => 'Приложение включено.',
    'disabled' => 'Приложение выключено.',
    'settings-saved' => 'Настройки сохранены.',
];

function noticeText(string $key, array $dictionary): ?string
{
    $text = $dictionary[$key] ?? null;
    if (!is_string($text)) {
        return null;
    }

    $filtered = applyFilters('notice_text', $text, $key);

    return is_string($filtered) ? $filtered : null;
}

function noticeOfRequest(array $dictionary): ?string
{
    $key = queryString('notice');

    return is_string($key) && $key !== '' ? noticeText($key, $dictionary) : null;
}
```

`core/csrf.php`:

```php
<?php

declare(strict_types=1);

// Токен живёт в сессии и возвращается скрытым полем. Проверяется в каждом POST-обработчике:
// страж tests/guard/CsrfCoverageTest.php следит, что обработчик без csrfCheck() не появляется.
function csrfToken(): string
{
    startSession();
    $token = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function csrfField(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): void
{
    startSession();
    $expected = $_SESSION['csrf_token'] ?? '';
    $given = postString('_token');

    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
        logWarn('csrf.rejected', ['path' => requestPath(), 'ip' => clientIp()]);
        forbidden('Проверка формы не прошла. Откройте страницу заново и повторите действие.');
    }
}
```

`core/bootstrap.php` — три подключения сразу после `session.php` (файлы создали шагом выше; до этого шага
подключать их было нельзя, и страница входа падала бы на отсутствующем `csrf.php`):

```php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notice.php';
require_once __DIR__ . '/form.php';
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter FormValuesTest`
Expected: PASS (8/8).

- [ ] **Шаг 3: Тест отказа при чужом токене**

Отказ рисует экран 403 и завершает запрос через `exit`. Для PHP это конец запроса, а для PHPUnit — конец процесса:
остальной набор просто не досчитался бы. Поэтому `respond()` в тестовом окружении бросает сигнал-исключение вместо
выхода, и тест ждёт именно его.

`tests/db/CsrfTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use PHPUnit\Framework\TestCase;

// CSRF проверяется без HTTP: токен в сессии против строки из формы.
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    public function testТокенСоздаётсяОдинРазИПовторяется(): void
    {
        $first = csrfToken();

        self::assertSame(64, strlen($first));
        self::assertSame($first, csrfToken());
    }

    public function testПолеНесётТокенВРазметке(): void
    {
        $field = csrfField();

        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString('value="' . csrfToken() . '"', $field);
    }

    public function testСовпадающийТокенПроходит(): void
    {
        $token = csrfToken();
        $_POST['_token'] = $token;

        csrfCheck();
        self::assertTrue(true, 'Проверка не отвергла свой токен');
    }

    public function testЧужойТокенОтвергаетсяСтатусомЧетырестаТри(): void
    {
        csrfToken();
        $_POST['_token'] = str_repeat('0', 64);
        $_SERVER['REQUEST_URI'] = '/login';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->expectException(\Cms\Core\ResponseFinished::class);
        $this->expectExceptionMessage('403');
        // Тело отказа — собранная страница theme/403.php, поэтому проверка по вхождению текста, а не по строке целиком.
        $this->expectOutputRegex('/Проверка формы не прошла/');

        csrfCheck();
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter CsrfTest`
Expected: FAIL — класса `Cms\Core\ResponseFinished` нет, а `respond()` обрывает процесс через `exit`, поэтому
тест до проверок не доходит.

- [ ] **Шаг 4: Отвечаем тесту — сигнал вместо выхода**

`core/ResponseFinished.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Core;

// Исключение-сигнал: тесты ловят его вместо exit, чтобы проверить статус и тело ответа.
final class ResponseFinished extends \RuntimeException
{
}
```

`core/response.php` — `respond()` целиком заменяется версией с проверкой окружения (порядок внутри не меняется:
статус и заголовки идут до тела, иначе `expectOutput*` не увидит текст):

```php
function respond(int $status, string $body, array $headers = []): never
{
    if (!headers_sent()) {
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    echo $body;

    // Под php -S и в вебе запрос завершается здесь; в PHPUnit процесс нужен живым.
    if (defined('PHPUNIT_RUNNING')) {
        throw new \Cms\Core\ResponseFinished((string) $status);
    }

    exit;
}
```

В `tests/bootstrap.php` константа обязана появиться до подключения ядра — иначе первый же `respond()`
в тестах успеет выйти:

```php
define('PHPUNIT_RUNNING', true);
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite db`
Expected: PASS.

- [ ] **Шаг 5: Включаем CSRF во вход и выход**

Три правки, которые делают задача 6 рабочими с этим помощником. Страж из следующего шага краснеет без любой из
них: он требует токен и в обработчике, и в форме.

`core/bootstrap.php` подключён шагом 2 — здесь проверяем, что `csrf.php` стоит после `session.php` (токен живёт
в сессии, и без стартовавшей сессии `csrfToken()` вернул бы пустую строку).

`core/routes.php` — `csrfCheck()` первым обращением в оба обработчика; тела обоих заменяются целиком,
второго метода с тем же именем в файле быть не должно:

```php
function coreLoginSubmit(array $params = []): void
{
    csrfCheck();

    $email = postString('email');
    $result = attemptLogin($email, postString('password'));

    // Скрытое поле формы — основной путь для глубинной ссылки, строка адреса остаётся запасным;
    // фильтр тот же, что и на странице входа: чужой домен не проходит ни одним способом.
    $queryNext = queryString('next', '');
    $rawNext = postString('next') !== '' ? postString('next') : (is_string($queryNext) ? $queryNext : '');
    $next = isInternalNextPath($rawNext) ? $rawNext : '';

    if ($result['ok'] === false) {
        // Введённый адрес возвращается в форму, текст ошибки — общий: он не выдаёт, есть ли аккаунт.
        render('login', ['error' => (string) $result['error'], 'email' => $email, 'next' => $next], ['title' => 'Вход', 'noindex' => true]);

        return;
    }

    redirect($next !== '' ? $next : '/admin');
}
```

Здесь же закрывается дыра, которую задача 6 оставила открытой: `?next=` приходил только в строке адреса,
а форма отправляет на `/login` без строки — значит при отказе входа (и вообще на POST) глубинная ссылка
терялась. Заодно гейт из задачи 8 сможет дослать `/admin?next=` до самого конца пути. Поэтому в
`coreLoginPage()` путь тоже фильтруется и уезжает в шаблон отдельной переменной, а не остаётся в URL.

```php
function coreLogout(array $params = []): void
{
    csrfCheck();
    logoutUser();
    redirectWithNotice('/', 'signed-out');
}
```

`theme/login.php` — скрытое поле токена первой строкой формы, следом глубинная ссылка (она попадает в
вид переменной `$next`, шаблон обязан объявить `/** @var string $next */`):

```php
    <form class="mt-6 space-y-4" method="post" action="/login">
        <?= csrfField() /* @safe html */ ?>
        <?php if ($next !== ''): ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>
        <label class="block text-sm">
```

В `tests/http/LoginTest.php` — тест, что скрытое поле действительно везёт ссылку до редиректа
(он же страхует от молчаливого возврата к «только строка адреса»):

```php
    public function testГлубиннаяСсылкаПереживаетПостФормы(): void
    {
        // Гейт редиректит на /login?next=/…; форма отправляет на /login без адреса в строке,
        // поэтому next обязан доехать до обработчика скрытым полем, а не потеряться в редиректе.
        $page = Kernel::get('/login?next=/news');
        self::assertStringContainsString('name="next" value="/news"', $page['body'], 'Страница входа прячет глубинную ссылку в форме');

        $response = Kernel::post(
            '/login',
            ['_token' => $this->tokenFrom($page['body']), 'email' => 'admin@school.dev', 'password' => 'верный-пароль', 'next' => '/news']
        );

        self::assertSame(302, $response['status']);
        self::assertSame('/news', $response['headers']['location'], 'Скрытое поле next доживает до редиректа');
    }
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --filter LoginTest`
Expected: PASS — `token()` начинает получать настоящий токен, и проверка формы проходит.

- [ ] **Шаг 6: Страж покрытия CSRF**

`tests/guard/CsrfCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Каждый POST-обработчик обязан начинать с проверки токена. Страж читает таблицу маршрутов и
// ищет вызов csrfCheck() в теле функции-обработчика.
final class CsrfCoverageTest extends TestCase
{
    public function testВсеPostОбработчикиПроверяютТокен(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = $this->handlerSources($root);
        $violations = [];

        foreach ($this->postHandlers($root) as $handler) {
            $body = $sources[$handler] ?? null;
            if ($body === null) {
                $violations[] = $handler . ': обработчик не найден в исходниках';
                continue;
            }
            if (!str_contains($body, 'csrfCheck()')) {
                $violations[] = $handler . ': POST без csrfCheck()';
            }
        }

        self::assertSame([], $violations, 'Найдены POST-обработчики без проверки CSRF');
    }

    public function testКаждаяФормаНесётСкрытоеПолеТокена(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->templates($root) as $file) {
            $code = (string) file_get_contents($file);
            $forms = preg_match_all('/<form\b[^>]*method="post"[^>]*>/i', $code, $matches);
            if ($forms === 0) {
                continue;
            }
            // Форма может быть одна, а поле — общим partial(); считаем вхождения csrfField().
            $fields = preg_match_all('/csrfField\(\)/', $code);
            if ($fields < $forms) {
                $violations[] = $this->relative($root, $file) . ': форм ' . $forms . ', полей токена ' . $fields;
            }
        }

        self::assertSame([], $violations, 'Форма method="post" обязана нести csrfField()');
    }

    /** @return list<string> */
    private function postHandlers(string $root): array
    {
        $out = [];
        $pattern = '/addRoute\(\s*\'POST\'\s*,\s*\'([^\']+)\'\s*,\s*\'([a-zA-Z0-9_\\\\:]+)\'/';

        foreach (['core/routes.php', 'apps/news/routes.php'] as $file) {
            if (!is_file($root . '/' . $file)) {
                continue;
            }
            preg_match_all($pattern, (string) file_get_contents($root . '/' . $file), $matches);
            foreach ($matches[2] as $handler) {
                $out[] = $handler;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> */
    private function handlerSources(string $root): array
    {
        $out = [];
        foreach (['core', 'apps', 'admin'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if (!$item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($item->getPathname());
                preg_match_all('/function\s+([a-zA-Z0-9_\\\\]+)\s*\(/', $code, $matches);
                foreach ($matches[1] as $name) {
                    $out[$name] = $this->functionBody($code, $name);
                }
            }
        }

        return $out;
    }

    private function functionBody(string $code, string $name): string
    {
        $start = strpos($code, 'function ' . $name . '(');
        if ($start === false) {
            return '';
        }
        $open = strpos($code, '{', $start);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($code);
        for ($i = $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        return substr($code, $open);
    }

    /** @return list<string> */
    private function templates(string $root): array
    {
        $out = [];
        foreach (['theme', 'admin', 'apps'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
```

Run: `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpunit --testsuite guard`
Expected: PASS — `coreLoginSubmit()` и `coreLogout()` начинаются с `csrfCheck()`.

- [ ] **Шаг 7: Ворота и коммит**

Run: `bash scripts/gate.sh`
Expected: всё зелёное.

```bash
git add core/csrf.php core/notice.php core/form.php core/response.php core/ResponseFinished.php \
  core/bootstrap.php core/routes.php theme/login.php tests/
GIT_AUTHOR_NAME='Алексей' GIT_AUTHOR_EMAIL='zanzibaradevil@gmail.com' \
GIT_COMMITTER_NAME='Алексей' GIT_COMMITTER_EMAIL='zanzibaradevil@gmail.com' \
  git commit -m "CSRF, уведомления по ключу и возврат введённого текста при отказе формы"
```

---
