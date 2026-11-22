<?php

declare(strict_types=1);

// Реестр тем. До этой работы «тема» была ровно одним каталогом `theme/`: второй вариант
// некуда было положить, а переключение означало правку файлов на сервере. Здесь появляется
// каталог `themes/` с манифестами и одна настройка `active_theme`, которая выбирает тему.
//
// Тема — не новый движок шаблонов, а каталог с тем же устройством, что и `theme/`: манифест
// описывает её для администратора, а каркас `layout.php` и партиалы остаются PHP-файлами,
// которые читает resolveTemplate(). Пользовательский PHP в тему не загружается: установка
// темы — это копирование каталога, а не выполнение кода из архива.

/** Каталог, где лежат установленные темы. */
function themesRoot(): string
{
    return dirname(__DIR__) . '/themes';
}

// Разбор манифеста темы. Возвращает массив с ключом `error`: пустая строка — манифест годен.
// Ошибка возвращается значением, а не исключением: испорченный манифест одной темы не должен
// ронять каталог целиком — остальные темы остаются доступны, а причина видна администратору.
/** @return array{error: string, slug: string, name: string, version: string, description: string, author: string, license: string, preview: string, colors: array<string, string>, layouts: list<string>, templates: list<string>} */
function themeManifestParse(string $json): array
{
    $empty = [
        'error' => '',
        'slug' => '',
        'name' => '',
        'version' => '',
        'description' => '',
        'author' => '',
        'license' => '',
        'preview' => '',
        'colors' => [],
        'layouts' => [],
        'templates' => [],
    ];

    try {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        // Обрыв JSON раньше читался бы как пустой массив, и тема без названия молча
        // попала бы в каталог — поэтому здесь именно ошибка, а не значение по умолчанию.
        return ['error' => 'Манифест темы не разбирается как JSON: ' . $e->getMessage()] + $empty;
    }

    if (!is_array($decoded)) {
        return ['error' => 'Манифест темы обязан быть объектом JSON'] + $empty;
    }

    $slug = is_string($decoded['slug'] ?? null) ? trim((string) $decoded['slug']) : '';
    if ($slug === '') {
        return ['error' => 'В манифесте нет обязательного поля slug'] + $empty;
    }
    // Slug — это имя каталога. Слэши и «..» вывели бы чтение за пределы `themes/`, а заглавные
    // буквы и кириллица разошлись бы с именем каталога на регистронезависимой файловой системе.
    if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug) !== 1) {
        return ['error' => 'Поле slug обязано состоять из строчных латинских букв, цифр и дефисов'] + $empty;
    }

    $name = is_string($decoded['name'] ?? null) ? trim((string) $decoded['name']) : '';
    if ($name === '') {
        return ['error' => 'В манифесте нет обязательного поля name'] + $empty;
    }

    $version = is_string($decoded['version'] ?? null) ? trim((string) $decoded['version']) : '';
    if ($version === '') {
        return ['error' => 'В манифесте нет обязательного поля version'] + $empty;
    }

    // Цвета и макеты — необязательные части манифеста: тема без них работает на значениях
    // встроенной темы. Приводим к строкам, чтобы шаблон не зависел от типа значения в JSON.
    $colors = [];
    if (is_array($decoded['colors'] ?? null)) {
        foreach ($decoded['colors'] as $key => $value) {
            if (is_string($key) && is_string($value) && $key !== '') {
                $colors[$key] = $value;
            }
        }
    }

    $layouts = [];
    if (is_array($decoded['layouts'] ?? null)) {
        foreach ($decoded['layouts'] as $layout) {
            if (is_string($layout) && $layout !== '') {
                $layouts[] = $layout;
            }
        }
    }

    $templates = [];
    if (is_array($decoded['templates'] ?? null)) {
        foreach ($decoded['templates'] as $template) {
            if (is_string($template) && $template !== '') {
                $templates[] = $template;
            }
        }
    }

    return [
        'error' => '',
        'slug' => $slug,
        'name' => $name,
        'version' => $version,
        'description' => is_string($decoded['description'] ?? null) ? (string) $decoded['description'] : '',
        'author' => is_string($decoded['author'] ?? null) ? (string) $decoded['author'] : '',
        'license' => is_string($decoded['license'] ?? null) ? (string) $decoded['license'] : '',
        'preview' => is_string($decoded['preview'] ?? null) ? (string) $decoded['preview'] : '',
        'colors' => $colors,
        'layouts' => $layouts,
        'templates' => $templates,
    ];
}

// Чтение каталога тем. Годные темы попадают в `themes`, испорченные — в `broken` с причиной.
// Отсутствующий каталог — не ошибка: сайт без собственных тем работает на встроенной `theme/`.
/** @return array{themes: list<array<string, mixed>>, broken: list<array{slug: string, error: string}>, error: string} */
function themeRegistryRead(string $root): array
{
    if (!is_dir($root)) {
        return ['themes' => [], 'broken' => [], 'error' => ''];
    }

    $themes = [];
    $broken = [];

    $entries = @scandir($root);
    if (!is_array($entries)) {
        return ['themes' => [], 'broken' => [], 'error' => 'Каталог тем не читается'];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($dir)) {
            continue;
        }

        $manifestFile = $dir . DIRECTORY_SEPARATOR . 'theme.json';
        if (!is_file($manifestFile)) {
            $broken[] = ['slug' => $entry, 'error' => 'В каталоге темы нет theme.json'];
            continue;
        }

        $json = @file_get_contents($manifestFile);
        if (!is_string($json)) {
            $broken[] = ['slug' => $entry, 'error' => 'theme.json не читается'];
            continue;
        }

        $manifest = themeManifestParse($json);
        if ($manifest['error'] !== '') {
            $broken[] = ['slug' => $entry, 'error' => $manifest['error']];
            continue;
        }

        // Каркас — единственный обязательный файл сверх манифеста: без него тему нечем
        // отрисовать, и активация дала бы белый экран вместо понятного отказа.
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'layout.php')) {
            $broken[] = ['slug' => $entry, 'error' => 'В каталоге темы нет layout.php'];
            continue;
        }

        $manifest['path'] = $dir;
        $themes[] = $manifest;
    }

    usort($themes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

    return ['themes' => $themes, 'broken' => $broken, 'error' => ''];
}

// Активная тема хранится настройкой, а не файлом: смена темы — одна запись в базе, поэтому
// откат после неудачной активации не требует правки файлов на сервере.
function themeActiveSlug(): string
{
    // Установщик объявляет SETUP_FLOW до первого рендера, и базы у него ещё нет: его экран
    // шага 1 строится из заготовки настроек, а подключение к базе кончается не исключением, а
    // `fail()` → `exit(1)` (core/config-loader.php). Поэтому до чтения настройки спрашиваем
    // константу — единственный признак «сайта ещё нет», доступный без базы. Без этой проверки
    // установщик падал пятисотым: его экран рендерится через тему, а тема читала настройку.
    if (defined('SETUP_FLOW')) {
        return '';
    }

    try {
        return getOption('active_theme', '');
    } catch (Throwable) {
        // Схема без таблицы настроек (страж на неустановленной базе) — встроенная тема.
        return '';
    }
}

// Каталог активной темы либо пустая строка. Проверяется каждый раз: тему могли удалить с
// диска, и тогда сайт обязан вернуться к встроенной теме, а не отдать ошибку.
function themeActiveRoot(): string
{
    $slug = themeActiveSlug();
    if ($slug === '') {
        return '';
    }

    $root = themesRoot();
    $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $slug;
    if (!is_dir($dir) || !is_file($dir . DIRECTORY_SEPARATOR . 'layout.php')) {
        return '';
    }

    return $dir;
}

// Активация: тема должна быть в реестре и иметь каркас. Возвращает результат значением —
// экран показывает причину отказа, а не белый экран.
/** @return array{ok: bool, error: string} */
function themeActivate(string $slug): array
{
    $registry = themeRegistryRead(themesRoot());
    foreach ($registry['themes'] as $theme) {
        if ((string) $theme['slug'] === $slug) {
            setOption('active_theme', $slug);

            return ['ok' => true, 'error' => ''];
        }
    }

    return ['ok' => false, 'error' => 'Тема не найдена или повреждена'];
}

// Возврат к встроенной теме: пустое значение настройки означает «theme/».
/** @return array{ok: bool, error: string} */
function themeDeactivate(): array
{
    setOption('active_theme', '');

    return ['ok' => true, 'error' => ''];
}
