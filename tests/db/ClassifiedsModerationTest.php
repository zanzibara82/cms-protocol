<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;

/**
 * C4: очередь проверки, жалобы, переписка и уведомления на уровне рабочего слоя.
 */
final class ClassifiedsModerationTest extends ClassifiedsDbTestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function values(string $categoryId, array $overrides = []): array
    {
        $check = classifiedsValidateListing(array_merge([
            'title' => 'Объявление на проверку',
            'slug' => 'obyavlenie-na-proverku',
            'category' => $categoryId,
            'body' => 'Подробное описание товара для проверки.',
            'price' => '1500',
            'location' => 'Москва',
            'status' => 'PENDING_REVIEW',
        ], $overrides));
        self::assertSame([], $check['errors']);

        return $check['values'];
    }

    public function testОчередьПоказываетОтправленноеИОдобрениеПубликует(): void
    {
        $seller = self::makeSeller('prodavec@example.ru');
        $editor = $this->seedEditor();
        $category = self::makeCategory('tehnika', 'Техника');
        $created = classifiedsCreateListing($seller, $this->values($category));
        self::assertTrue($created['ok']);

        $queue = classifiedsModerationQueue();
        self::assertCount(1, $queue);
        self::assertSame($created['id'], (string) $queue[0]['id']);
        self::assertSame(1, classifiedsModerationPendingCount());

        $approved = classifiedsModerationDecide($created['id'], $editor, 'approve', '');
        self::assertTrue($approved['ok']);
        self::assertSame('moderation_approved', $approved['notice']);
        self::assertSame(0, classifiedsModerationPendingCount());

        $listing = classifiedsListingById($created['id']);
        self::assertNotNull($listing);
        self::assertSame('PUBLISHED', $listing['status']);
        self::assertNotNull($listing['expires_at']);
        self::assertNull($listing['moderation_note']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [$seller, 'moderation']
        ));

        self::assertFalse(classifiedsModerationDecide($created['id'], $editor, 'approve', '')['ok']);
    }

    public function testОтклонениеТребуетПричинуИСОхраняетЕё(): void
    {
        $seller = self::makeSeller('prodavec@example.ru');
        $editor = $this->seedEditor();
        $category = self::makeCategory('tehnika', 'Техника');
        $created = classifiedsCreateListing($seller, $this->values($category));

        $empty = classifiedsModerationDecide($created['id'], $editor, 'reject', '   ');
        self::assertFalse($empty['ok']);
        self::assertSame('PENDING_REVIEW', value(
            'SELECT status FROM ' . t('classifieds_listing') . ' WHERE id = ?',
            [$created['id']]
        ));

        $rejected = classifiedsModerationDecide($created['id'], $editor, 'reject', 'Фото не соответствует товару.');
        self::assertTrue($rejected['ok']);
        self::assertSame('moderation_rejected', $rejected['notice']);
        $listing = classifiedsListingById($created['id']);
        self::assertNotNull($listing);
        self::assertSame('REJECTED', $listing['status']);
        self::assertSame('Фото не соответствует товару.', $listing['moderation_note']);
        self::assertNull($listing['expires_at']);
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND body LIKE ?',
            [$seller, '%Фото не соответствует товару.%']
        ));
    }

    public function testЖалобаОднаНаЧеловекаИЗакрываетсяСУведомлением(): void
    {
        $seller = self::makeSeller('prodavec@example.ru');
        $buyer = self::makeSeller('pokupatel@example.ru');
        $editor = $this->seedEditor();
        $category = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($seller, $category, ['slug' => 'kofemashina', 'title' => 'Кофемашина']);

        $own = classifiedsReportCreate($listingId, $seller, 'spam', '');
        self::assertFalse($own['ok']);

        $sent = classifiedsReportCreate($listingId, $buyer, 'spam', 'Повторяется в ленте.');
        self::assertTrue($sent['ok']);
        self::assertSame('report_sent', $sent['notice']);
        $again = classifiedsReportCreate($listingId, $buyer, 'fraud', '');
        self::assertTrue($again['ok']);
        self::assertSame('report_duplicate', $again['notice']);
        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_report')));
        self::assertSame(1, classifiedsReportNewCount());

        $reportId = (string) value('SELECT id FROM ' . t('classifieds_report'));
        $resolved = classifiedsReportResolve($reportId, $editor);
        self::assertTrue($resolved['ok']);
        self::assertSame('report_resolved', $resolved['notice']);
        self::assertSame('RESOLVED', value('SELECT status FROM ' . t('classifieds_report') . ' WHERE id = ?', [$reportId]));
        self::assertSame(0, classifiedsReportNewCount());
        self::assertSame(1, (int) value(
            'SELECT COUNT(*) FROM ' . t('classifieds_notification') . ' WHERE user_id = ? AND kind = ?',
            [$buyer, 'report']
        ));
        self::assertFalse(classifiedsReportResolve($reportId, $editor)['ok']);
    }

    public function testПерепискаИдётЧерезВладельцаИЧитаетсяВТеме(): void
    {
        $seller = self::makeSeller('prodavec@example.ru');
        $buyer = self::makeSeller('pokupatel@example.ru');
        $stranger = self::makeSeller('tretiy@example.ru');
        $category = self::makeCategory('tehnika', 'Техника');
        $listingId = self::makeListing($seller, $category, ['slug' => 'kofemashina', 'title' => 'Кофемашина']);

        $sent = classifiedsMessageSend($listingId, $buyer, $seller, 'Ещё продаёте?');
        self::assertTrue($sent['ok']);
        self::assertSame(1, classifiedsUnreadMessages($seller));
        self::assertSame(1, classifiedsUnreadNotifications($seller));
        self::assertCount(1, classifiedsMessageThreads($buyer));
        self::assertCount(1, classifiedsMessageThreads($seller));

        $wrongRecipient = classifiedsMessageSend($listingId, $buyer, $stranger, 'Привет');
        self::assertFalse($wrongRecipient['ok']);

        $thread = classifiedsMessageThread($listingId, $seller, $buyer);
        self::assertNotNull($thread);
        self::assertCount(1, $thread['messages']);
        self::assertSame(0, classifiedsUnreadMessages($seller));

        $reply = classifiedsMessageSend($listingId, $seller, $buyer, 'Да, в продаже.');
        self::assertTrue($reply['ok']);
        self::assertSame(1, classifiedsUnreadMessages($buyer));
        $noExchange = classifiedsMessageSend($listingId, $seller, $stranger, 'Вам не писал.');
        self::assertFalse($noExchange['ok']);

        $buyerThread = classifiedsMessageThread($listingId, $buyer, $seller);
        self::assertNotNull($buyerThread);
        self::assertCount(2, $buyerThread['messages']);
        self::assertSame(0, classifiedsUnreadMessages($buyer));

        $marked = classifiedsNotificationsMarkRead($buyer);
        self::assertTrue($marked['ok']);
        self::assertSame(0, classifiedsUnreadNotifications($buyer));
    }

    public function testСообщениеТолькоПоОткрытомуОбъявлениюИТолькоВладельцу(): void
    {
        $seller = self::makeSeller('prodavec@example.ru');
        $buyer = self::makeSeller('pokupatel@example.ru');
        $category = self::makeCategory('tehnika', 'Техника');
        $draftId = self::makeListing($seller, $category, ['slug' => 'chernovik', 'status' => 'DRAFT']);

        $closed = classifiedsMessageSend($draftId, $buyer, $seller, 'Здравствуйте');
        self::assertFalse($closed['ok']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('classifieds_message')));

        $publishedId = self::makeListing($seller, $category, ['slug' => 'zhivoe', 'title' => 'Живое']);
        $empty = classifiedsMessageSend($publishedId, $buyer, $seller, '   ');
        self::assertFalse($empty['ok']);
        $long = classifiedsMessageSend($publishedId, $buyer, $seller, str_repeat('а', CLASSIFIEDS_MESSAGE_MAX + 1));
        self::assertFalse($long['ok']);
    }

    private function seedEditor(): string
    {
        run('DELETE FROM ' . t('user') . ' WHERE email = ?', ['redaktor@example.ru']);

        return TestDb::seedUser('redaktor@example.ru', 'redaktorskiy-parol-1', 'editor');
    }
}
