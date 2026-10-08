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
