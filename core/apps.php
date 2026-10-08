<?php

declare(strict_types=1);

// Порядок регистрации маршрутов задан спецификацией §5.2: ядро, затем приложения по алфавиту id.
// Алфавитный порядок делает таблицу маршрутов одинаковой на любом хостинге: glob() возвращает
// файлы в порядке файловой системы, а он совпадать не обязан.
const APP_MANIFEST_REQUIRED_KEYS = ['id', 'title', 'version', 'requiresCore'];

/** @return list<string> */
function appDirs(): array
{
    $dirs = [dirname(__DIR__) . '/apps'];

    $extra = (string) configValue('EXTRA_APPS_DIR', '');
    if ($extra !== '' && is_dir($extra)) {
        $dirs[] = rtrim($extra, '/');
    }

    return $dirs;
}

// Кеш живёт в ссылке, а не в static-переменной внутри appManifests(): тестам нужно сбрасывать его
// между наборами, а static-переменная функции извне недоступна.
/** @return array<string, array<string, mixed>>|null */
function &appManifestsRef(): ?array
{
    static $manifests = null;

    return $manifests;
}

function appsReset(): void
{
    $manifests = &appManifestsRef();
    $manifests = null;
}

/** @return array<string, array<string, mixed>> */
function appManifests(): array
{
    $manifests = &appManifestsRef();

    if ($manifests !== null) {
        return $manifests;
    }

    $found = [];
    foreach (appDirs() as $dir) {
        foreach (glob($dir . '/*/manifest.php') ?: [] as $file) {
            $manifest = require $file;
            if (!is_array($manifest)) {
                logError('app.manifest_invalid', ['file' => $file]);

                continue;
            }

            $id = (string) ($manifest['id'] ?? '');
            $folder = basename(dirname((string) $file));
            if ($id === '' || $id !== $folder) {
                logError('app.manifest_id_mismatch', ['file' => $file, 'id' => $id, 'folder' => $folder]);

                continue;
            }

            foreach (APP_MANIFEST_REQUIRED_KEYS as $key) {
                if (($manifest[$key] ?? '') === '') {
                    logError('app.manifest_incomplete', ['file' => $file, 'key' => $key]);

                    continue 2;
                }
            }

            $manifest['dir'] = dirname((string) $file);
            $found[$id] = $manifest;
        }
    }

    ksort($found);
    $manifests = $found;

    return $manifests;
}

/** @return array<string, mixed>|null */
function appManifest(string $id): ?array
{
    $manifests = appManifests();

    return $manifests[$id] ?? null;
}

/** @return array<string, array<string, mixed>> */
function appInstallations(): array
{
    $out = [];
    foreach (all('SELECT id, enabled, installed_at, version FROM ' . t('app_installation')) as $row) {
        $out[(string) $row['id']] = $row;
    }

    return $out;
}

/** @return array<string, mixed>|null */
function appInstallation(string $id): ?array
{
    return one(
        'SELECT id, enabled, installed_at, version FROM ' . t('app_installation') . ' WHERE id = ?',
        [$id]
    );
}

function isAppInstalled(string $id): bool
{
    return appInstallation($id) !== null;
}

// Гейт маршрута спрашивает именно «активно ли»: выключенный раздел обязан отвечать 404, а не 403 —
// иначе статус выдаёт существование выключенного раздела.
function isAppActive(string $id): bool
{
    $row = appInstallation($id);

    return $row !== null && (int) $row['enabled'] === 1;
}

function coreVersionSatisfies(string $requires): bool
{
    return version_compare($requires, CORE_VERSION, '<=');
}

/**
 * Состояние раздела для экрана «Приложения» (спецификация §6.3).
 *
 * @return array{state: string, dbVersion: string, manifestVersion: string, reason: string}
 */
function appState(string $id): array
{
    $manifest = appManifest($id);
    if ($manifest === null) {
        return [
            'state' => 'missing',
            'dbVersion' => '',
            'manifestVersion' => '',
            'reason' => 'Файлы раздела не найдены в сборке.',
        ];
    }

    $manifestVersion = (string) $manifest['version'];
    $row = appInstallation($id);

    if ($row === null) {
        if (!coreVersionSatisfies((string) $manifest['requiresCore'])) {
            return [
                'state' => 'incompatible',
                'dbVersion' => '',
                'manifestVersion' => $manifestVersion,
                'reason' => 'Нужна версия ядра не ниже ' . (string) $manifest['requiresCore'] . ', в сборке ' . CORE_VERSION . '.',
            ];
        }

        return ['state' => 'missing', 'dbVersion' => '', 'manifestVersion' => $manifestVersion, 'reason' => 'Раздел не установлен.'];
    }

    $dbVersion = (string) $row['version'];

    if ((int) $row['enabled'] !== 1) {
        return ['state' => 'disabled', 'dbVersion' => $dbVersion, 'manifestVersion' => $manifestVersion, 'reason' => 'Раздел выключен.'];
    }

    if (version_compare($dbVersion, $manifestVersion, '<')) {
        return [
            'state' => 'outdated',
            'dbVersion' => $dbVersion,
            'manifestVersion' => $manifestVersion,
            'reason' => 'Есть обновление до ' . $manifestVersion . '.',
        ];
    }

    // F9: база новее сборки — отдельное состояние, а не «готово»: отката схемы ядро не умеет,
    // и зелёный свет такому разделу вводил бы в заблуждение.
    if (version_compare($dbVersion, $manifestVersion, '>')) {
        return [
            'state' => 'ahead',
            'dbVersion' => $dbVersion,
            'manifestVersion' => $manifestVersion,
            'reason' => 'В базе версия ' . $dbVersion . ', в сборке — ' . $manifestVersion . '.',
        ];
    }

    return ['state' => 'ready', 'dbVersion' => $dbVersion, 'manifestVersion' => $manifestVersion, 'reason' => ''];
}

/**
 * Русские метки состояний экрана «Приложения». Словарь живёт в ядре, а не в подключаемом
 * шаблоне: const внутри шаблона при повторном подключении падает с «const уже объявлен»,
 * а каждое состояние из appState() обязано иметь здесь метку — латиница в интерфейсе запрещена.
 *
 * @return array<string, string>
 */
function appStateLabels(): array
{
    return [
        'missing' => 'Не установлен',
        'incompatible' => 'Нужно более новое ядро',
        'outdated' => 'Требует обновления',
        'disabled' => 'Выключен',
        'ready' => 'Установлен',
        'ahead' => 'Версия в базе новее сборки',
    ];
}

// Приватные префиксы нужны не только гейту: задача 9 закрывает их в robots.txt,
// а страж сверяет этот список с тем, что действительно не индексируется.
/** @return list<string> */
function appPrivatePaths(): array
{
    $paths = [];
    foreach (appManifests() as $manifest) {
        foreach ((array) ($manifest['privatePaths'] ?? []) as $path) {
            $clean = '/' . trim((string) $path, '/');
            if ($clean !== '/' && !in_array($clean, $paths, true)) {
                $paths[] = $clean;
            }
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Снимок маршрутов разделов: какие файлы уже исполнены и что каждый из них добавил в таблицу.
 * Нужен потому, что routes.php исполняется в процессе один раз (require_once), а таблицы маршрутов
 * между наборами тестов сбрасываются. Снимок хранит обработчик как есть (string|Closure) — приведение
 * к строке убило бы замыкание-обработчик при повторной регистрации.
 *
 * @return array{files: list<string>, routes: list<array{method: string, pattern: string, handler: string|Closure, meta: array<string, mixed>, static: bool}>}
 */
function &appRoutesSnapshot(): array
{
    static $state = ['files' => [], 'routes' => []];

    return $state;
}

// Маршруты регистрируются для всех приложений из сборки, включая неустановленные: dispatch()
// отвечает 404 по метке app, а таблица маршрутов остаётся полной для стража.
function registerAppRoutes(): void
{
    $snapshot = &appRoutesSnapshot();

    // Файлы уже исполнены — функции контроллеров определены, а таблицу маршрутов заполняем снимком.
    if ($snapshot['files'] !== []) {
        foreach ($snapshot['routes'] as $route) {
            addRoute($route['method'], $route['pattern'], $route['handler'], $route['meta']);
        }

        return;
    }

    $before = count(routeTable());

    foreach (appManifests() as $manifest) {
        $routes = rtrim((string) $manifest['dir'], '/') . '/routes.php';

        if (is_file($routes)) {
            require_once $routes;
            $snapshot['files'][] = $routes;
        }
    }

    // В снимок попадает только то, что добавили разделы: маршруты ядра регистрируются отдельно
    // и на повторе не должны удваиваться.
    $snapshot['routes'] = array_slice(routeTable(), $before);
}
