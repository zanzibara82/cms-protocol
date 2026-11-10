<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * Перенос данных портала на тестовой базе: прежний bcrypt-хеш входит своим паролем, строка
 * ложится по идентификатору источника, повторный прогон не дублирует и не затирает правку
 * администратора, а строка без родителя не переносится.
 */
final class ImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();

        // Перенос пишет в таблицы курсов, а их создаёт установка приложения, а не базовая схема ядра.
        if (!self::learnInstalled()) {
            $result = installApp('learn');
            if (($result['ok'] ?? false) !== true) {
                self::fail('Раздел «Курсы» не установился: ' . (string) ($result['error'] ?? ''));
            }
        }
    }

    private static function learnInstalled(): bool
    {
        return all("SHOW TABLES LIKE '" . t('learn_module') . "'") !== [];
    }

    public function testПарольПорталаПроверяетсяБезПересчёта(): void
    {
        // Хеш портала — bcrypt с префиксом $2b$ (Prisma/Node). PHP проверяет его нативно,
        // поэтому люди входят в CMS своими прежними паролями.
        $hash = '$2b$10$bF2Lfe0PrOgDSnraFIgqOelf8dDdb2unLD3/OxYYdfnh.m6ecLLYq';

        self::assertTrue(verifyPassword('secret', $hash), 'Хеш портала обязан проверяться как есть');
        self::assertTrue(needsRehash($hash), 'При первом входе алгоритм обязан подняться до Argon2id');
    }

    public function testСтрокаПереноситсяИПовторныйПрогонНеДублирует(): void
    {
        $stat = ['read' => 0, 'written' => 0, 'skipped' => []];

        $row = [
            'id' => 'cmup3o8x40000vpm42xprwwft',
            'email' => 'admin@school.dev',
            'password_hash' => '$2b$10$bF2Lfe0PrOgDSnraFIgqOelf8dDdb2unLD3/OxYYdfnh.m6ecLLYq',
            'name' => 'admin',
            'role' => 'admin',
            'session_version' => 0,
            'blocked_at' => null,
            'created_at' => '2026-09-28 17:00:27',
        ];

        self::assertTrue(importUpsert('user', $row, $stat));
        self::assertSame(1, $stat['written']);

        // Повторный прогон: строка уже есть и совпадает — «уже есть», а не вторая запись.
        self::assertFalse(importUpsert('user', $row, $stat));
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('user')));

        // Вход прежним паролем портала работает на перенесённой строке.
        self::assertTrue(verifyPassword('secret', (string) value('SELECT password_hash FROM ' . t('user'))));
    }

    public function testПравкаВCMSНеЗатираетсяПовторнымПрогоном(): void
    {
        $stat = ['read' => 0, 'written' => 0, 'skipped' => []];

        importUpsert('user', [
            'id' => 'cmusrc000000000000000001',
            'email' => 'old@school.dev',
            'password_hash' => '$2b$10$bF2Lfe0PrOgDSnraFIgqOelf8dDdb2unLD3/OxYYdfnh.m6ecLLYq',
            'name' => 'Старое имя',
            'role' => 'student',
            'session_version' => 0,
            'blocked_at' => null,
            'created_at' => '2026-09-28 17:00:27',
        ], $stat);

        // Администратор переименовал человека уже в CMS.
        run('UPDATE ' . t('user') . ' SET name = ? WHERE id = ?', ['Новое имя', 'cmusrc000000000000000001']);

        importUpsert('user', [
            'id' => 'cmusrc000000000000000001',
            'email' => 'old@school.dev',
            'password_hash' => '$2b$10$bF2Lfe0PrOgDSnraFIgqOelf8dDdb2unLD3/OxYYdfnh.m6ecLLYq',
            'name' => 'Старое имя',
            'role' => 'student',
            'session_version' => 0,
            'blocked_at' => null,
            'created_at' => '2026-09-28 17:00:27',
        ], $stat);

        self::assertSame('Новое имя', (string) value('SELECT name FROM ' . t('user') . ' WHERE id = ?', ['cmusrc000000000000000001']));
        self::assertSame(1, $stat['skipped']['в базе другое значение'] ?? 0);
    }

    public function testСтрокаБезРодителяНеПереносится(): void
    {
        $stat = ['read' => 0, 'written' => 0, 'skipped' => []];

        // Набор родителей берётся из источника: модуля «mmissing…» в выгрузке нет, и урок
        // переносить некуда — выдумывать родителя нельзя.
        $known = importIds([['id' => 'm1'], ['id' => 'm2']]);

        self::assertFalse(importOrphans($known, 'mmissing0000000000000000', $stat));
        self::assertSame(1, $stat['skipped']['родитель не найден'] ?? 0);
        self::assertTrue(importOrphans($known, 'm1', $stat));
    }
}
