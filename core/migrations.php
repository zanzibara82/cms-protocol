<?php

declare(strict_types=1);

use Cms\Core\MigrationPlan;

// Порядок применения задаёт имя файла, поэтому миграции начинаются с даты: 20261006_0001 раньше
// 20261006_0002. sort() обязателен — glob() возвращает файлы в порядке файловой системы.
function sqlDirOf(string $app): string
{
    if ($app === 'core') {
        return dirname(__DIR__) . '/sql';
    }

    $manifest = appManifest($app);

    return $manifest === null ? '' : rtrim((string) ($manifest['sql'] ?? ''), '/');
}

/** @return list<array{name: string, path: string}> */
function sqlFilesOf(string $app): array
{
    $dir = sqlDirOf($app);
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files);

    return array_map(
        static fn (string $path): array => ['name' => basename($path), 'path' => $path],
        $files
    );
}

function migrationChecksum(string $path): string
{
    return hash('sha256', (string) file_get_contents($path));
}

/** @return array<string, string> имя файла => контрольная сумма */
function appliedMigrations(string $app): array
{
    $rows = all(
        'SELECT name, checksum FROM ' . t('app_migration') . ' WHERE app = ? ORDER BY applied_at, name',
        [$app]
    );

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['name']] = (string) $row['checksum'];
    }

    return $out;
}

function planApp(string $app): MigrationPlan
{
    $applied = appliedMigrations($app);
    $pending = [];
    $changed = [];

    foreach (sqlFilesOf($app) as $file) {
        $name = (string) $file['name'];
        $checksum = migrationChecksum((string) $file['path']);

        if (!array_key_exists($name, $applied)) {
            $pending[] = ['name' => $name, 'path' => (string) $file['path'], 'checksum' => $checksum];

            continue;
        }

        if (!hash_equals($applied[$name], $checksum)) {
            $changed[] = $name;
        }
    }

    $manifest = appManifest($app);
    $target = $app === 'core' ? CORE_VERSION : (string) ($manifest['version'] ?? '0.0.0');

    return new MigrationPlan($app, $pending, $changed, $target);
}

/** @return list<string> */
function coreSqlPending(): array
{
    return planApp('core')->pendingNames();
}

/**
 * Применяет план. Строка журнала пишется сразу после каждого файла, а не одной транзакцией:
 * MySQL неявно коммитит DDL, поэтому откатить наполовину созданную схема транзакция не может.
 *
 * @return array{ok: bool, error: string, applied: list<string>}
 */
function applyApp(MigrationPlan $plan): array
{
    if ($plan->isBlocked()) {
        logWarn('migration.blocked', ['app' => $plan->app, 'changed' => $plan->changed]);

        return ['ok' => false, 'error' => $plan->blockMessage(), 'applied' => []];
    }

    $applied = [];
    foreach ($plan->pending as $file) {
        $name = (string) $file['name'];

        try {
            foreach (splitSql((string) file_get_contents((string) $file['path'])) as $statement) {
                run($statement);
            }
        } catch (Throwable $error) {
            logError('migration.failed', ['app' => $plan->app, 'file' => $name, 'message' => $error->getMessage()]);

            return [
                'ok' => false,
                'error' => 'Не удалось применить файл ' . $name . ': ' . $error->getMessage(),
                'applied' => $applied,
            ];
        }

        insert('app_migration', [
            'id' => newId(),
            'app' => $plan->app,
            'name' => $name,
            'checksum' => (string) $file['checksum'],
            'applied_at' => utcStamp(nowUtc()),
        ]);

        $applied[] = $name;
    }

    if ($plan->app !== 'core') {
        run('UPDATE ' . t('app_installation') . ' SET version = ? WHERE id = ?', [$plan->targetVersion, $plan->app]);
    }

    doAction('app_updated', $plan->app, $applied);
    logInfo('migration.applied', ['app' => $plan->app, 'files' => $applied]);

    return ['ok' => true, 'error' => '', 'applied' => $applied];
}

/** @return array{ok: bool, error: string, applied: list<string>} */
function installApp(string $id): array
{
    $manifest = appManifest($id);
    if ($manifest === null) {
        return ['ok' => false, 'error' => 'Раздел «' . $id . '» не найден в сборке.', 'applied' => []];
    }

    // Критерий §12.3: проверка версии ядра идёт до planApp(), чтобы отказ не зависел от того,
    // что лежит в sql/ раздела.
    if (!coreVersionSatisfies((string) $manifest['requiresCore'])) {
        logWarn('app.install_refused', ['app' => $id, 'reason' => 'requiresCore']);

        return [
            'ok' => false,
            'error' => 'Нужна версия ядра не ниже ' . (string) $manifest['requiresCore'] . ', в сборке ' . CORE_VERSION . '.',
            'applied' => [],
        ];
    }

    if (isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел уже установлен.', 'applied' => []];
    }

    insert('app_installation', [
        'id' => $id,
        'enabled' => 1,
        'installed_at' => utcStamp(nowUtc()),
        'version' => '0.0.0',
    ]);

    $result = applyApp(planApp($id));
    if ($result['ok'] === false) {
        // Строку установки убираем: раздел без таблиц не должен числиться установленным,
        // иначе «Обновить» никогда не появится, а «Установить» будет отказывать.
        run('DELETE FROM ' . t('app_installation') . ' WHERE id = ?', [$id]);

        return $result;
    }

    seedApp($manifest);
    doAction('app_installed', $id);
    logInfo('app.installed', ['app' => $id, 'files' => $result['applied']]);

    return $result;
}

/** @return array{ok: bool, error: string, applied: list<string>} */
function updateApp(string $id): array
{
    if (appManifest($id) === null) {
        return ['ok' => false, 'error' => 'Раздел «' . $id . '» не найден в сборке.', 'applied' => []];
    }

    if (!isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел не установлен — обновлять нечего.', 'applied' => []];
    }

    $plan = planApp($id);

    return $plan->isEmpty() ? ['ok' => true, 'error' => '', 'applied' => []] : applyApp($plan);
}

/** @return array{ok: bool, error: string} */
function setAppEnabled(string $id, bool $enabled): array
{
    if (!isAppInstalled($id)) {
        return ['ok' => false, 'error' => 'Раздел не установлен.'];
    }

    run('UPDATE ' . t('app_installation') . ' SET enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $id]);
    logInfo($enabled ? 'app.enabled' : 'app.disabled', ['app' => $id]);

    return ['ok' => true, 'error' => ''];
}

// Начальная закладка идёт после миграций: она пишет в таблицы, которых до них не существует.
// Идемпотентность — обязанность seed.php, ядро лишь не запускает его при обновлении.
/** @param array<string, mixed> $manifest */
function seedApp(array $manifest): void
{
    $seed = $manifest['seed'] ?? null;
    if (!is_string($seed) || $seed === '' || !is_file($seed)) {
        return;
    }

    require $seed;
}
