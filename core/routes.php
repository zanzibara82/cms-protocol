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

    // Глубинная ссылка переживает страницу входа: она уезжает в форме скрытым полем,
    // поэтому гейт из задачи 8 досылает её до редиректа после входа.
    $queryNext = queryString('next', '');
    $next = is_string($queryNext) && isInternalNextPath($queryNext) ? $queryNext : '';

    render('login', ['error' => '', 'email' => queryString('email', ''), 'next' => $next], ['title' => 'Вход', 'noindex' => true]);
}

/** @param array<string, string> $params */
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

/** @param array<string, string> $params */
function coreLogout(array $params = []): void
{
    csrfCheck();
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
