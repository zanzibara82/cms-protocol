<?php

declare(strict_types=1);

namespace Cms\Tests;

// Тестовая база пересоздаётся перед каждым набором. Защита от ошибки в имени базы обязательна:
// сброс боевой — необратимая операция, поэтому при любом другом имени помощник падает сразу.
final class TestDb
{
    public static function reset(): void
    {
        self::assertTestDatabase();

        foreach (self::tables() as $table) {
            run('SET FOREIGN_KEY_CHECKS = 0');
            run('DROP TABLE IF EXISTS ' . $table);
            run('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach (self::migrationFiles() as $file) {
            foreach (splitSql((string) file_get_contents($file)) as $statement) {
                run($statement);
            }
        }
    }

    /** Создаёт редактора и возвращает его id. Пароль — только тестовый, в коде проекта не живёт. */
    public static function seedUser(string $email, string $password, string $role = 'editor'): string
    {
        $id = newId();
        insert('user', [
            'id' => $id,
            'email' => $email,
            // hashPassword() появится в core/auth.php вместе с задачей 6; здесь — осознанная
            // ранняя ссылка: helper готов заранее, тесты задачи 3 его не вызывают.
            'password_hash' => hashPassword($password), // @phpstan-ignore function.notFound
            'name' => 'Тестовый редактор',
            'role' => $role,
            'session_version' => 0,
            'created_at' => utcStamp(nowUtc()),
        ]);

        return $id;
    }

    public static function clearTable(string $name): void
    {
        self::assertTestDatabase();
        run('DELETE FROM ' . t($name));
    }

    private static function assertTestDatabase(): void
    {
        if ((string) configValue('DB_NAME') !== 'cms_test') {
            throw new \RuntimeException('Сброс разрешён только для cms_test, текущая база: ' . (string) configValue('DB_NAME'));
        }
    }

    /** @return list<string> */
    private static function tables(): array
    {
        $rows = all('SHOW TABLES');
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) array_values($row)[0];
        }

        return $out;
    }

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        // Только базовая схема ядра. Таблицы приложений создаёт `installApp()` — если бы помощник
        // раскладывал их сам, тест установки не увидел бы, что кнопка «Установить» действительно
        // создаёт таблицы (критерий §12.2), а журнал остался бы пустым.
        $files = glob(dirname(__DIR__) . '/sql/*.sql') ?: [];
        sort($files);

        return $files;
    }
}
