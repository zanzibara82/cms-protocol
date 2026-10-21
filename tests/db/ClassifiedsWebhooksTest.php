<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use PHPUnit\Framework\Attributes\Test;

/**
 * Вебхуки доски (C6): справочник приёмников событий объявлений и переписки, подпись
 * HMAC, отдача события через отделённый фильтром транспорт и честная запись последней
 * доставки. Сеть в тесте не поднимается: транспорт подменяется подпиской
 * classifieds_webhook_transport — тем же приёмом, каким раздел обещает расширяемость.
 */
final class ClassifiedsWebhooksTest extends ClassifiedsDbTestCase
{
    /** @var list<array{id: string, url: string, secret: string, event: string, payload: string}> */
    private static array $delivered = [];

    /** @var array<string, list<array{fn: callable, priority: int, kind: string, order: int}>> */
    private static array $savedHooks = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::registerTransport();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Редактор набора — свой: чужие наборы тоже заводят редактора, а почта одна.
        run('DELETE FROM ' . t('user') . " WHERE email = 'editor-webhooks@example.ru'");
        self::$delivered = [];
    }

    protected function tearDown(): void
    {
        // Подписки других разделов не трогаем: восстанавливаем реестр целиком и заново
        // ставим доставляющий транспорт — следующий тест не должен зависеть от порядка.
        $registry = &hookRegistry();
        $registry = self::$savedHooks;
        self::registerTransport();
    }

    /**
     * Транспорт подменяется с подпиской: собранное остаётся до конца набора. Живой curl
     * на тестовом стенде не поднимается — риск ухода наружу исчезает.
     */
    private static function registerTransport(): void
    {
        $registry = &hookRegistry();
        self::$delivered = [];

        if (!isset($registry['classifieds_webhook_transport'])) {
            self::$savedHooks = $registry;
        }

        addFilter('classifieds_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static function (array $receiver, string $event, string $payload): bool {
                self::$delivered[] = [
                    'id' => (string) $receiver['id'],
                    'url' => (string) $receiver['url'],
                    'secret' => (string) $receiver['secret'],
                    'event' => $event,
                    'payload' => $payload,
                ];

                return true;
            };
        });
    }

    // ------------------------------------------------------------------
    // Валидация и CRUD справочника
    // ------------------------------------------------------------------

    #[Test]
    public function validatorAsksAddressSecretAndEvent(): void
    {
        $empty = classifiedsValidateWebhook([]);
        self::assertSame('Укажите адрес приёмника.', (string) $empty['errors']['url']);
        self::assertSame('Укажите секрет — он подписывает события.', (string) $empty['errors']['secret']);
        self::assertSame(
            'Выберите событие — на неизвестное подписаться нельзя.',
            (string) $empty['errors']['event']
        );

        $notUrl = classifiedsValidateWebhook([
            'url' => 'ftp://пример.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
        ]);
        self::assertSame('Адрес начинается с http:// или https://.', (string) $notUrl['errors']['url']);

        $short = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'короткий',
            'event' => 'listing.published',
        ]);
        self::assertSame(
            'Секрет не короче ' . CLASSIFIEDS_WEBHOOK_SECRET_MIN . ' символов.',
            (string) $short['errors']['secret']
        );

        $long = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/' . str_repeat('а', CLASSIFIEDS_WEBHOOK_URL_MAX),
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
        ]);
        self::assertSame(
            'Адрес не длиннее ' . CLASSIFIEDS_WEBHOOK_URL_MAX . ' символов.',
            (string) $long['errors']['url']
        );

        $unknownEvent = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.exploded',
        ]);
        self::assertSame(
            'Выберите событие — на неизвестное подписаться нельзя.',
            (string) $unknownEvent['errors']['event']
        );

        $good = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-xyz',
            'event' => 'listing.published',
            'active' => '1',
        ]);
        self::assertSame([], $good['errors'], 'Валидная тройка обязана пройти без причин');
        self::assertSame(1, $good['values']['active'], 'Флажок активности читается из набора формы');
    }

    #[Test]
    public function webhookIsCreatedChangedAndDeleted(): void
    {
        $check = classifiedsValidateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 'on',
        ]);
        $created = classifiedsCreateWebhook($check['values']);
        self::assertTrue($created['ok'], 'Создание валидного вебхука обязано пройти');
        self::assertSame('webhook_saved', $created['notice']);

        $row = classifiedsWebhookReceivers('listing.published')[0] ?? null;
        self::assertIsArray($row, 'Созданный активный приёмник виден списку события');
        self::assertSame('https://приёмник.рф/board', (string) $row['url']);

        $updated = classifiedsUpdateWebhook((string) $row['id'], [
            'url' => 'https://второй.рф/board',
            'secret' => 'second-secret-20-sym',
            'event' => 'listing.hidden',
            'active' => 0,
        ]);
        self::assertTrue($updated['ok'], 'Правка существующего приёмника обязана пройти');

        $kept = one('SELECT url, event, active FROM ' . t('classifieds_webhook') . ' WHERE id = ?', [$row['id']]);
        self::assertIsArray($kept);
        self::assertSame('https://второй.рф/board', (string) $kept['url']);
        self::assertSame('listing.hidden', (string) $kept['event']);
        self::assertSame(0, (int) $kept['active'], 'Снятие флажка выключает приёмник');

        $deleted = classifiedsDeleteWebhook((string) $row['id']);
        self::assertTrue($deleted['ok'], 'Удаление существующего приёмника проходит');
        self::assertSame('webhook_deleted', $deleted['notice']);
        self::assertNull(one('SELECT id FROM ' . t('classifieds_webhook')), 'Строка приёмника ушла из таблицы');

        $again = classifiedsDeleteWebhook((string) $row['id']);
        self::assertFalse($again['ok'], 'Второе удаление того же идентификатора красит причиной');
        self::assertSame('Вебхука уже нет — обновите список.', (string) $again['error']);
    }

    #[Test]
    public function suggestedSecretIsLongEnough(): void
    {
        foreach (range(1, 5) as $i) {
            $secret = classifiedsWebhookSecretSuggest();
            $check = classifiedsValidateWebhook([
                'url' => 'https://приёмник.рф/board',
                'secret' => $secret,
                'event' => 'listing.published',
            ]);
            self::assertSame([], $check['errors'], 'Машинный секрет обязателен не хуже набранного (' . $i . ' прогон)');
        }
    }

    // ------------------------------------------------------------------
    // События и транспорт
    // ------------------------------------------------------------------

    #[Test]
    public function eventReachesReceiverWithFactsAndSignature(): void
    {
        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        classifiedsWebhookEvent('listing.published', [
            'listing' => ['id' => 'abc', 'title' => 'Велосипед'],
            'seller_id' => 'seller-1',
        ]);

        self::assertCount(1, self::$delivered, 'Активный приёмник получает ровно одно письмо события');
        $letter = self::$delivered[0];
        self::assertSame('listing.published', $letter['event']);
        self::assertSame('https://приёмник.рф/board', $letter['url']);
        self::assertSame('secret-20-symbols-x', $letter['secret'], 'Транспорт получает секрет в своих руках');

        $payload = (array) json_decode($letter['payload'], true);
        self::assertSame('listing.published', (string) ($payload['event'] ?? ''));
        self::assertSame('abc', (string) ($payload['listing']['id'] ?? ''));
        self::assertSame('Велосипед', (string) ($payload['listing']['title'] ?? ''));
        self::assertSame('seller-1', (string) ($payload['seller_id'] ?? ''));
        self::assertNotSame('', (string) ($payload['at'] ?? ''), 'Время события кладётся в тело');

        self::assertSame(
            classifiedsWebhookSignature($letter['payload'], 'secret-20-symbols-x'),
            'sha256=' . hash_hmac('sha256', $letter['payload'], 'secret-20-symbols-x'),
            'Фабрика подписи и известная формула HMAC сходятся'
        );
    }

    #[Test]
    public function unknownEventGoesNowhere(): void
    {
        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        classifiedsWebhookEvent('listing.exploded', ['listing' => ['id' => 'abc']]);

        self::assertSame([], self::$delivered, 'Неизвестное событие никому не отправляется');
    }

    #[Test]
    public function disabledReceiverKeepsSilent(): void
    {
        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 0,
        ]);

        classifiedsWebhookEvent('listing.published', ['listing' => ['id' => 'abc']]);

        self::assertSame([], self::$delivered, 'Выключенный приёмник не получает событий');
        $row = one('SELECT last_state FROM ' . t('classifieds_webhook'));
        self::assertIsArray($row);
        self::assertNull($row['last_state'], 'Выключенному не пишется «доставлено» — доставки не было');
    }

    #[Test]
    public function successIsWrittenToLastState(): void
    {
        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        classifiedsWebhookEvent('listing.published', ['listing' => ['id' => 'abc']]);

        $row = one('SELECT last_state, last_at FROM ' . t('classifieds_webhook'));
        self::assertIsArray($row);
        self::assertSame('Доставлено', (string) $row['last_state']);
        self::assertNotNull($row['last_at']);
    }

    #[Test]
    public function failedDeliveryIsWrittenToLastState(): void
    {
        $registry = &hookRegistry();
        $registry = self::$savedHooks;
        self::$delivered = [];
        addFilter('classifieds_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static fn (): bool => false;
        });

        classifiedsCreateWebhook([
            'url' => 'https://мёртвый.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        classifiedsWebhookEvent('listing.published', ['listing' => ['id' => 'abc']]);

        $row = one('SELECT last_state, last_at FROM ' . t('classifieds_webhook'));
        self::assertIsArray($row, 'Приёмник остался в таблице');
        self::assertSame(
            'Не доставлено — проверьте адрес приёмника.',
            (string) $row['last_state'],
            'Отказ доставки печатается словами, а не кодом'
        );
        self::assertNotNull($row['last_at'], 'Отметка времени доставки пишется и при отказе');
    }

    #[Test]
    public function brokenTransportDoesNotBreakTheAction(): void
    {
        $registry = &hookRegistry();
        $registry = self::$savedHooks;
        self::$delivered = [];
        addFilter('classifieds_webhook_transport', static function (mixed $value, array $receiver, string $event, string $payload): \Closure {
            return static function (): bool {
                throw new \RuntimeException('транспорт огрызнулся');
            };
        });

        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        classifiedsWebhookEvent('listing.published', ['listing' => ['id' => 'abc']]);

        $row = one('SELECT last_state FROM ' . t('classifieds_webhook'));
        self::assertIsArray($row, 'Исключение транспорта гасится — приёмник и доска остаются на месте');
        self::assertNull($row['last_state'], 'Состояние доставки не рисуется из-за поломки транспорта');
    }

    // ------------------------------------------------------------------
    // События боевых действий
    // ------------------------------------------------------------------

    #[Test]
    public function moderationDecisionSendsEvent(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['status' => 'PENDING_REVIEW']);
        $editorId = TestDb::seedUser('editor-webhooks@example.ru', 'secret-pass-1', 'editor');

        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.published',
            'active' => 1,
        ]);

        $result = classifiedsModerationDecide($listingId, $editorId, 'approve', '');
        self::assertTrue($result['ok'], 'Решение проверки обязано пройти');

        self::assertCount(1, self::$delivered, 'Публикация объявления доходит до приёмника');
        $payload = (array) json_decode(self::$delivered[0]['payload'], true);
        self::assertSame('listing.published', (string) ($payload['event'] ?? ''));
        self::assertSame($listingId, (string) ($payload['listing']['id'] ?? ''));
        self::assertSame($seller, (string) ($payload['seller_id'] ?? ''));
    }

    #[Test]
    public function rejectedListingSendsItsOwnEvent(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['status' => 'PENDING_REVIEW']);
        $editorId = TestDb::seedUser('editor-webhooks@example.ru', 'secret-pass-1', 'editor');

        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.rejected',
            'active' => 1,
        ]);

        classifiedsModerationDecide($listingId, $editorId, 'reject', 'Цена завышена');

        self::assertCount(1, self::$delivered, 'Отклонение объявления доходит до приёмника');
        $payload = (array) json_decode(self::$delivered[0]['payload'], true);
        self::assertSame('listing.rejected', (string) ($payload['event'] ?? ''));
    }

    #[Test]
    public function sellerHidingListingSendsEvent(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $listingId = self::makeListing($seller, $category, ['status' => 'PUBLISHED']);

        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'listing.hidden',
            'active' => 1,
        ]);

        $result = classifiedsListingSetStatus($listingId, $seller, 'DRAFT');
        self::assertTrue($result['ok'], 'Снятие с публикации обязано пройти');

        self::assertCount(1, self::$delivered, 'Снятие объявления доходит до приёмника');
        $payload = (array) json_decode(self::$delivered[0]['payload'], true);
        self::assertSame('listing.hidden', (string) ($payload['event'] ?? ''));
    }

    #[Test]
    public function messageToSellerSendsEvent(): void
    {
        $category = self::makeCategory('velo', 'Велосипеды');
        $seller = self::makeSeller();
        $buyer = self::makeSeller('buyer@example.ru');
        $listingId = self::makeListing($seller, $category, ['status' => 'PUBLISHED']);

        classifiedsCreateWebhook([
            'url' => 'https://приёмник.рф/board',
            'secret' => 'secret-20-symbols-x',
            'event' => 'message.received',
            'active' => 1,
        ]);

        $result = classifiedsMessageSend($listingId, $buyer, $seller, 'Здравствуйте, ещё в наличии?');
        self::assertTrue($result['ok'], 'Сообщение продавцу обязано уйти');

        self::assertCount(1, self::$delivered, 'Письмо продавцу доходит до приёмника');
        $payload = (array) json_decode(self::$delivered[0]['payload'], true);
        self::assertSame('message.received', (string) ($payload['event'] ?? ''));
        self::assertSame($buyer, (string) ($payload['sender']['id'] ?? ''));
        self::assertSame($listingId, (string) ($payload['listing']['id'] ?? ''));
        self::assertStringNotContainsString(
            'ещё в наличии',
            $letter = (string) self::$delivered[0]['payload'],
            'Текст письма в теле события не уходит: письмо живёт в кабинете'
        );
    }
}
