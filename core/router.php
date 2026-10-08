<?php

declare(strict_types=1);

// Таблица маршрутов собирается каждый запрос: ядро регистрирует свои, затем приложения по
// алфавиту id. Статические сегменты (/news/admin/items) обязаны идти раньше параметров
// (/news/{slug}) — иначе новость с адресом «admin» спрятала бы админку.
/** @return list<array{method: string, pattern: string, handler: string|Closure, meta: array<string, mixed>, static: bool}> */
function &routeRegistry(): array
{
    static $routes = [];

    return $routes;
}

/**
 * Собирает регулярку маршрута из нормализованного шаблона: сегменты {имя} становятся именованными
 * группами «[^/]+», прочие символы уходят в паттерн как есть. Общий код для регистрации (где проверка
 * на валидность) и сопоставления — иначе два места могли бы расходиться и собирать разные регулярки.
 */
function routeRegex(string $pattern): string
{
    return '#^' . preg_replace_callback(
        '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
        static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
        $pattern
    ) . '$#u';
}

/** @param array<string, mixed> $meta */
function addRoute(string $method, string $pattern, string|Closure $handler, array $meta = []): void
{
    $normalized = '/' . trim($pattern, '/');

    // Шаблон пишет разработчик, но опечатка в спецсимволе — «#» у нас разделитель — рвёт паттерн:
    // preg_match тогда молча вернул бы false, и маршрут исчез бы без единой ошибки. Собираем регулярку
    // и проверяем её на регистрации, а не на сопоставлении, чтобы упасть громко и назвать виновника.
    // Предупреждение PCRE гасим: о невалидности сигналят false и preg_last_error(), а не шум в выводе.
    if (@preg_match(routeRegex($normalized), '') === false && preg_last_error() !== PREG_NO_ERROR) {
        throw new \LogicException(sprintf('Некорректный шаблон маршрута «%s»: регулярка не собралась.', $normalized));
    }

    $registry = &routeRegistry();
    $registry[] = [
        'method' => strtoupper($method),
        'pattern' => $normalized,
        'handler' => $handler,
        'meta' => $meta,
        'static' => !str_contains($pattern, '{'),
    ];
}

/** @return list<array{method: string, pattern: string, handler: string|Closure, meta: array<string, mixed>, static: bool}> */
function routeTable(): array
{
    $registry = &routeRegistry();

    return $registry;
}

function routesReset(): void
{
    $registry = &routeRegistry();
    $registry = [];
}

/** @return array{handler: string|Closure, params: array<string, string>, meta: array<string, mixed>}|null */
function matchRoute(string $method, string $path): ?array
{
    $clean = '/' . trim(strtok($path, '?') ?: '', '/');
    $method = strtoupper($method);

    // Точные пути идут раньше параметрических: иначе /news/admin/items попал бы в /news/{slug}.
    $registry = &routeRegistry();
    usort($registry, static fn (array $a, array $b): int => ($b['static'] ? 1 : 0) <=> ($a['static'] ? 1 : 0));

    foreach ($registry as $route) {
        if ($route['method'] !== $method) {
            continue;
        }

        // Шаблон пишется в коде ядра или приложения, а не приходит из запроса, поэтому подстановка
        // сегментов в регулярку безопасна: значение {slug} ограничено «[^/]+». Регулярку собирает тот
        // же routeRegex, что и проверка на регистрации, — сопоставление видит ровно тот паттерн, что одобрен.
        $regex = routeRegex($route['pattern']);

        if (preg_match($regex, $clean, $matches) === 1) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (string) $value;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'meta' => $route['meta']];
        }
    }

    return null;
}

// Единственное место, где маршрут превращается в вызов. Гейты идут до действия, а не внутри него:
// приватный путь обязан отвечать 403, даже если обработчик забыл проверить право.
function dispatch(): void
{
    $method = requestMethod();
    $match = matchRoute($method, requestPath());

    if ($match === null) {
        notFound();
    }

    $app = (string) ($match['meta']['app'] ?? '');
    // isAppActive() появится в задаче 8, getEditorAccess() — в задаче 6: это осознанная ранняя ссылка,
    // до них dispatch() вызывают только маршруты без меток app и private.
    if ($app !== '' && !isAppActive($app)) { // @phpstan-ignore function.notFound
        notFound();
    }

    if (($match['meta']['private'] ?? false) === true) {
        $access = getEditorAccess(); // @phpstan-ignore function.notFound
        if ($access['kind'] !== 'granted') {
            if ($method === 'GET' && ($match['meta']['permission'] ?? 'editor') === 'login') {
                redirect('/login?next=' . rawurlencode(requestPath()));
            }
            forbidden();
        }
    }

    doAction('before_route', $match);

    $handler = $match['handler'];
    if (!is_callable($handler)) {
        logError('route.handler_missing', ['handler' => $handler, 'path' => requestPath()]);
        respond(500, partial('error'), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    $handler($match['params']);

    doAction('after_route');
}
