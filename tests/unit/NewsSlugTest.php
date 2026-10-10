<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

final class NewsSlugTest extends TestCase
{
    public function testРусскийЗаголовокСтановитсяАдресом(): void
    {
        $cases = [
            'Жизнь школы' => 'zhizn-shkoly',
            'Поступление в 10 класс' => 'postuplenie-v-10-klass',
            'Конкурс «Ученик года»' => 'konkurs-uchenik-goda',
            'Итоги, итоги и ещё раз итоги' => 'itogi-itogi-i-eschyo-raz-itogi',
            'Щедрый вечер' => 'schedryy-vecher',
            'Объект №5' => 'obekt-5',
            '  Пробелы  вокруг  ' => 'probely-vokrug',
            'A1-B2_test.pdf' => 'a1-b2-test-pdf',
            'Без пробелов' => 'bez-probelov',
            'Ёлка' => 'yolka',
        ];

        foreach ($cases as $input => $expected) {
            self::assertSame($expected, slugify((string) $input), (string) $input);
        }
    }

    public function testАдресОбрезаетсяДоПределаИНеКончаетсяДефисом(): void
    {
        $long = str_repeat('слово ', 40);
        $slug = slugify($long);

        self::assertLessThanOrEqual(NEWS_SLUG_MAX, mb_strlen($slug));
        self::assertStringNotContainsString('--', $slug);
        self::assertStringEndsNotWith('-', $slug);
        self::assertSame(NEWS_SLUG_MAX, mb_strlen(slugify(str_repeat('а', 200))));
    }

    public function testПустойИНечитаемыйЗаголовокДаютПустойАдрес(): void
    {
        self::assertSame('', slugify(''));
        self::assertSame('', slugify('   '));
        self::assertSame('', slugify('!!! ???'));
    }

    public function testКоллизияРешаетсяНумерацией(): void
    {
        $taken = static fn (string $slug): bool => in_array($slug, ['novost', 'novost-2', 'novost-3'], true);

        self::assertSame('novost-4', ensureUniqueSlug('novost', $taken));
        self::assertSame('svobodnyj', ensureUniqueSlug('svobodnyj', $taken));
    }

    public function testПустаяОсноваЗаменяетсяЗапаснымСловом(): void
    {
        self::assertSame('novost', ensureUniqueSlug('', static fn (): bool => false));
        self::assertSame('zagolovok', ensureUniqueSlug('', static fn (): bool => false, 'zagolovok'));
    }

    public function testВписанныйАдресНеНумеруетсяПриЗанятости(): void
    {
        $plan = newsSlugPlan('zanyatyj-adres', 'Заголовок', static fn (): bool => true);

        self::assertSame('', $plan['slug']);
        self::assertSame('По такому адресу уже есть материал — придумайте другой.', $plan['error']);
    }

    public function testСвободныйВписанныйАдресПроходитБезИзменений(): void
    {
        $plan = newsSlugPlan('Vypusk 2026!', 'Другой заголовок', static fn (): bool => false);

        self::assertSame('vypusk-2026', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testСлужебныйСегментРазделаЗанят(): void
    {
        foreach (NEWS_RESERVED_SLUGS as $reserved) {
            $plan = newsSlugPlan((string) $reserved, 'Заголовок', static fn (): bool => false);

            self::assertSame('', $plan['slug'], (string) $reserved);
            self::assertSame(
                'Адрес «' . $reserved . '» занят служебной страницей раздела — выберите другой.',
                $plan['error']
            );
        }
    }

    public function testКороткийВписанныйАдресОтклоняется(): void
    {
        $plan = newsSlugPlan('ab', 'Заголовок', static fn (): bool => false);

        self::assertSame('', $plan['slug']);
        self::assertSame('Адрес короче 3 символов — по нему материал открывают по ссылке.', $plan['error']);
    }

    public function testПустойАдресСобираетсяИзЗаголовка(): void
    {
        $plan = newsSlugPlan('', 'Жизнь школы', static fn (): bool => false);

        self::assertSame('zhizn-shkoly', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testПустойАдресИзНечитаемогоЗаголовкаОтклоняется(): void
    {
        $plan = newsSlugPlan('', '!!!', static fn (): bool => false);

        self::assertSame('', $plan['slug']);
        self::assertSame(
            'Адрес не получился: из такого заголовка его не собрать (3 символов минимум). Впишите адрес сами.',
            $plan['error']
        );
    }

    public function testАдресИзЗаголовкаПриКоллизииНумеруется(): void
    {
        $plan = newsSlugPlan('', 'Жизнь школы', static fn (string $slug): bool => $slug === 'zhizn-shkoly');

        self::assertSame('zhizn-shkoly-2', $plan['slug']);
        self::assertSame('', $plan['error']);
    }

    public function testПрежнийАдресЗапоминаетсяПервым(): void
    {
        self::assertSame(['staryj'], rememberOldSlug([], 'staryj', 'novyj'));
        self::assertSame(['staryj', 'proshlyj'], rememberOldSlug(['proshlyj'], 'staryj', 'novyj'));
    }

    public function testПрежнийАдресНеЗапоминаетсяЕслиОнНеМенялся(): void
    {
        self::assertSame(['a'], rememberOldSlug(['a'], '', 'novyj'));
        self::assertSame(['a'], rememberOldSlug(['a'], 'novyj', 'novyj'));
    }

    public function testНовыйАдресИПовторыУбираютсяИзСпискаПрежних(): void
    {
        $slugs = ['novyj', 'a', 'a', 'b'];

        self::assertSame(['staryj', 'a', 'b'], rememberOldSlug($slugs, 'staryj', 'novyj'));
        self::assertSame(['staryj', 'a', 'b'], rememberOldSlug(['a', '', 'a', 'staryj', 'b'], 'staryj', 'novyj'));
    }

    public function testСписокПрежнихАдресовОбрезаетсяПоПределу(): void
    {
        $slugs = [];
        for ($i = 1; $i <= OLD_SLUGS_MAX; $i++) {
            $slugs[] = 'adres-' . $i;
        }

        $result = rememberOldSlug($slugs, 'samyj-novyj', 'tekushchij');

        self::assertCount(OLD_SLUGS_MAX, $result);
        self::assertSame('samyj-novyj', $result[0]);
        self::assertNotContains('adres-' . OLD_SLUGS_MAX, $result);
    }
}
