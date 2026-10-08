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

/** @param array<string, string> $params */
function coreHome(array $params = []): void
{
    render('home', [], [
        'title' => (string) configValue('SITE_NAME', 'Школа'),
        'description' => 'Новости, курсы и объявления школы английского языка.',
    ]);
}

/** @param array<string, string> $params */
function coreLoginPage(array $params = []): void
{
    if (currentUser() !== null) {
        redirect('/admin');
    }

    render('login', ['error' => '', 'email' => queryString('email', '')], ['title' => 'Вход', 'noindex' => true]);
}

/** @param array<string, string> $params */
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

/** @param array<string, string> $params */
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
