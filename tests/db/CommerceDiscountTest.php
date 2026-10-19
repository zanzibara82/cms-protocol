<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\http\CommerceHttpSetup;

/**
 * Скидки (M4): купоны и промо-правила в базе. Валидаторы, визиты купона, выбор
 * лучшего правила и возврат визита при отмене заказа.
 */
final class CommerceDiscountTest extends CommerceDbTestCase
{
    public function testВалидаторКупонаПринимаетЧистыйСписок(): void
    {
        $check = commerceValidateCoupon([
            'code' => 'summer-10',
            'kind' => 'PERCENT',
            'value' => '10,5',
            'min_total' => '1 000',
            'expires' => '2099-12-31',
            'max_uses' => '3',
            'active' => '1',
        ], static fn (string $c): bool => false);

        self::assertSame([], $check['errors']);
        self::assertSame('SUMMER-10', $check['values']['code']);
        self::assertSame(10.5, $check['values']['value']);
        self::assertSame(1000.0, $check['values']['min_total']);
        self::assertSame('2099-12-31', $check['values']['expires']);
        self::assertSame(3, $check['values']['max_uses']);
        self::assertSame(1, $check['values']['active']);
    }

    public function testВалидаторКупонаОтбраковываетКучуПолей(): void
    {
        $check = commerceValidateCoupon([
            'code' => '!!',
            'kind' => 'PERCENT',
            'value' => '120',
            'min_total' => '-5',
            'expires' => '2026-02-30',
            'max_uses' => '0',
        ], static fn (string $c): bool => false);

        self::assertArrayHasKey('code', $check['errors']);
        self::assertArrayHasKey('value', $check['errors']);
        self::assertArrayHasKey('min_total', $check['errors']);
        self::assertArrayHasKey('expires', $check['errors']);
        self::assertArrayHasKey('max_uses', $check['errors']);

        $weird = commerceValidateCoupon([
            'code' => 'SUMMER',
            'kind' => 'WEIRDO',
            'value' => '10',
            'min_total' => '',
            'expires' => '',
            'max_uses' => '',
        ], static fn (string $c): bool => false);

        self::assertArrayHasKey('kind', $weird['errors']);
    }

    public function testКодКупонаЗанятБезРегистра(): void
    {
        CommerceHttpSetup::addCoupon('SUMMER', 'PERCENT', 10.0);

        self::assertTrue(commerceCouponCodeTaken('summer'));
        self::assertFalse(commerceCouponCodeTaken('autumn'));
    }

    public function testСкидкаКупонаПроцентИФикс(): void
    {
        $percentId = CommerceHttpSetup::addCoupon('P10', 'PERCENT', 10.0);

        self::assertSame(2000, commerceCouponDiscountCents((array) commerceCouponById($percentId), 20000));

        $fixedId = CommerceHttpSetup::addCoupon('F5', 'FIXED', 5.0);

        // Скидка не уводит корзину ниже нуля: у корзины в одну копейку скидка съедает её целиком.
        self::assertSame(100, commerceCouponDiscountCents((array) commerceCouponById($fixedId), 100));
    }

    public function testКупонОтказываетПриПорогеИСроке(): void
    {
        $moment = nowUtc();

        CommerceHttpSetup::addCoupon('OLD', 'PERCENT', 10.0, null, '2001-01-01');
        $oldId = (string) value('SELECT id FROM ' . t('commerce_coupon') . " WHERE code = 'OLD'");
        $usable = commerceCouponUsable(commerceCouponById($oldId), 5000, 'RUB', $moment);

        self::assertFalse($usable['ok']);
        self::assertSame('Купон истёк.', $usable['error']);

        CommerceHttpSetup::addCoupon('LO', 'PERCENT', 10.0, 100.0);
        $low = (string) value('SELECT id FROM ' . t('commerce_coupon') . " WHERE code = 'LO'");
        $usable = commerceCouponUsable(commerceCouponById($low), 999, 'RUB', $moment);

        self::assertFalse($usable['ok']);
        self::assertStringContainsString('от корзины на', $usable['error']);
        self::assertTrue(commerceCouponUsable(commerceCouponById($low), 10000, 'RUB', $moment)['ok']);
    }

    public function testКупонИсчерпанИСнят(): void
    {
        CommerceHttpSetup::addCoupon('USED', 'PERCENT', 10.0, null, null, 1, 1);
        CommerceHttpSetup::addCoupon('OFF', 'PERCENT', 10.0, null, null, null, 0, false);

        $usedId = (string) value('SELECT id FROM ' . t('commerce_coupon') . " WHERE code = 'USED'");
        $offId = (string) value('SELECT id FROM ' . t('commerce_coupon') . " WHERE code = 'OFF'");

        $used = commerceCouponUsable(commerceCouponById($usedId), 10000, 'RUB', nowUtc());
        self::assertFalse($used['ok']);
        self::assertSame('Купон исчерпан: его визиты уже потрачены.', $used['error']);

        $off = commerceCouponUsable(commerceCouponById($offId), 10000, 'RUB', nowUtc());
        self::assertFalse($off['ok']);
        self::assertSame('Купон снят с продаж.', $off['error']);

        $none = commerceCouponUsable(null, 10000, 'RUB', nowUtc());
        self::assertFalse($none['ok']);
        self::assertSame('Купона с таким кодом нет.', $none['error']);
    }

    public function testСделкаКупонаСжигаетВизитИВозвращаетПриОтмене(): void
    {
        CommerceHttpSetup::addCoupon('ONCE', 'PERCENT', 10.0, null, null, 1);
        $moment = nowUtc();

        $first = commerceConsumeCoupon('ONCE', 'RUB', 20000, $moment);
        self::assertTrue($first['ok']);

        $second = commerceConsumeCoupon('ONCE', 'RUB', 20000, $moment);
        self::assertFalse($second['ok']);
        self::assertSame('Купон исчерпан: его визиты уже потрачены.', $second['error']);

        // Отмена возвращает визит: купон снова принимается.
        commerceReturnCouponCount('ONCE');

        $third = commerceConsumeCoupon('ONCE', 'RUB', 20000, $moment);
        self::assertTrue($third['ok']);
    }

    public function testЛучшееПравилоПроживаетПорогИПериоды(): void
    {
        CommerceHttpSetup::addPromo('Истёкшее', 'PERCENT', 30.0, null, '2020-01-01', '2020-02-01');
        CommerceHttpSetup::addPromo('Малое правило', 'PERCENT', 10.0, null, '2020-01-01', null);
        CommerceHttpSetup::addPromo('Большое правило', 'PERCENT', 25.0, 500.0, null, '2099-12-31');

        $best = commerceBestPromoDiscount(commerceActivePromos(nowUtc()), 200000);
        self::assertNotNull($best);
        self::assertSame('Большое правило', $best['title']);
        self::assertSame(50000, $best['discount_cents']);

        // Порог большого правила не набран — лучшее малое.
        $best = commerceBestPromoDiscount(commerceActivePromos(nowUtc()), 10000);
        self::assertNotNull($best);
        self::assertSame('Малое правило', $best['title']);
        self::assertSame(1000, $best['discount_cents']);

        // Фиксированная скидка не уводит корзину в минус.
        CommerceHttpSetup::addPromo('Фикс-правило', 'FIXED', 999.0, null, null, null);
        $best = commerceBestPromoDiscount(commerceActivePromos(nowUtc()), 200);
        self::assertNotNull($best);
        self::assertSame('Фикс-правило', $best['title']);
        self::assertSame(200, $best['discount_cents']);
    }

    public function testВалидаторПравилаСверяетПериоды(): void
    {
        $check = commerceValidatePromo([
            'title' => 'П',
            'value' => '5',
            'starts' => '2026-01-02',
            'ends' => '2026-01-01',
        ]);

        self::assertArrayHasKey('ends', $check['errors'] ?? []);

        $broken = commerceValidatePromo(['title' => '', 'value' => 'nope']);
        self::assertArrayHasKey('title', $broken['errors']);
        self::assertArrayHasKey('value', $broken['errors']);

        $clean = commerceValidatePromo(['title' => 'Окей', 'kind' => 'FIXED', 'value' => '300']);
        self::assertSame([], $clean['errors']);
    }

    public function testОтменаЗаказаБезКупонаЖивёт(): void
    {
        CommerceHttpSetup::addOrder();
        $orderId = (string) value('SELECT id FROM ' . t('commerce_order') . ' LIMIT 1');

        $result = commerceSetOrderStatus($orderId, 'CANCELLED');

        self::assertTrue((bool) $result['ok']);
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('commerce_coupon')));
    }
}
