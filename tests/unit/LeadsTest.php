<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Чистые правила заявок (§17): контакт, источник, ловушка, пределы полей, цель редиректа и
 * письма. База и почта не нужны — всё считается на строках в памяти.
 */
final class LeadsTest extends TestCase
{
    public function testКонтактПризнаётТелефонИПочтуНоНеСсылку(): void
    {
        foreach (['+7 900 000-00-00', '8900 000 00 00', 'maria@example.ru', 'm@x.io'] as $contact) {
            self::assertMatchesRegularExpression(LEAD_CONTACT_RE, $contact, 'Контакт «' . $contact . '» обязан пройти');
        }

        foreach (['', 'мария', 'mailto:maria@example.ru', 'maria@', '@example.ru', 'https://example.ru'] as $contact) {
            self::assertDoesNotMatchRegularExpression(LEAD_CONTACT_RE, $contact, 'Контакт «' . $contact . '» обязан отсечься');
        }
    }

    public function testПисьмоУходитТолькоНаПочту(): void
    {
        self::assertTrue(leadIsEmail('maria@example.ru'));
        self::assertTrue(leadIsEmail(' M@x.io '));
        self::assertFalse(leadIsEmail('+7 900 000-00-00'));
        self::assertFalse(leadIsEmail('без адреса'));
    }

    public function testСсылкаНаКонтактСтроитсяТолькоДляРаспознанного(): void
    {
        self::assertSame('mailto:maria@example.ru', leadContactHref('maria@example.ru'));
        self::assertSame('tel:+79000000000', leadContactHref('+7 900 000-00-00'));
        self::assertSame('tel:89000000000', leadContactHref('8 900 000-00-00'));
        self::assertNull(leadContactHref('позвоните в школу'));
    }

    public function testИсточникПринимаетТолькоВнутреннийПутьБезСхемы(): void
    {
        self::assertSame('/o-nas#zayavka', leadSourceOrEmpty('/o-nas#zayavka'));
        self::assertSame('/o-nas?draft=1#zayavka', leadSourceOrEmpty('/o-nas?draft=1#zayavka'));
        self::assertSame('/a/b/c/d/e#zayavka', leadSourceOrEmpty('/a/b/c/d/e#zayavka'));
        self::assertSame('', leadSourceOrEmpty('https://example.ru/o-nas'));
        self::assertSame('', leadSourceOrEmpty('//example.ru/o-nas'));
        self::assertSame('', leadSourceOrEmpty('javascript:alert(1)'));
        self::assertSame('', leadSourceOrEmpty(''));
    }

    public function testЛовушкаСрабатываетТолькоНаЗаполненномПолеФирмы(): void
    {
        self::assertTrue(leadIsJunk(['company' => 'ООО Ромашка']));
        self::assertTrue(leadIsJunk(['company' => '  x  ']));
        self::assertFalse(leadIsJunk(['company' => '']));
        self::assertFalse(leadIsJunk(['company' => null]));
    }

    public function testВалидацияНазываетПервуюЧеловеческуюОшибку(): void
    {
        self::assertSame('Напишите, как к вам обращаться.', leadValidationError(['name' => '', 'contact' => 'maria@example.ru']));
        self::assertSame('Напишите, как к вам обращаться.', leadValidationError(['name' => 'А', 'contact' => 'maria@example.ru']));
        self::assertSame('Имя длиннее 80 символов.', leadValidationError(['name' => str_repeat('а', 81), 'contact' => 'maria@example.ru']));
        self::assertSame('Нужен телефон или почта.', leadValidationError(['name' => 'Мария', 'contact' => '']));
        self::assertSame('Контакт длиннее 120 символов.', leadValidationError(['name' => 'Мария', 'contact' => str_repeat('1', 121)]));
        self::assertSame('Оставьте телефон или почту — по ним сможем ответить.', leadValidationError(['name' => 'Мария', 'contact' => 'мария']));
        self::assertSame('Слишком длинное сообщение.', leadValidationError(['name' => 'Мария', 'contact' => 'maria@example.ru', 'message' => str_repeat('а', 1001)]));
        self::assertSame('', leadValidationError(['name' => 'Мария', 'contact' => 'maria@example.ru', 'message' => 'Вопрос']));
    }

    public function testЦельРедиректаОстаётсяВнутренней(): void
    {
        self::assertSame('/', leadRedirectTarget(''));
        self::assertSame('/o-nas', leadRedirectTarget('/o-nas#zayavka'));
        self::assertSame('/o-nas?draft=1', leadRedirectTarget('/o-nas?draft=1#zayavka'));
        self::assertSame('/', leadRedirectTarget('//example.ru/o-nas'));
        self::assertSame('#zayavka', leadSourceFragment('/o-nas#zayavka'));
        self::assertSame('', leadSourceFragment('/o-nas'));
    }

    public function testПисьмаЭкранируютИмяИПереводыСтрок(): void
    {
        self::assertSame('A &amp; B', leadEmailText('A & B'));
        self::assertSame('A &lt;b&gt;', leadEmailText('A <b>'));
        self::assertSame('Строка<br>вторая', leadEmailHtml("Строка\nвторая"));

        $html = leadConfirmHtml('Мария <script>');

        self::assertStringContainsString('Заявку получили.', $html);
        self::assertStringContainsString('Мария &lt;script&gt;', $html, 'имя из формы не становится разметкой письма');
        self::assertStringNotContainsString('Мария <script>', $html);

        $text = leadReplyText('Мария', "Спасибо\nза вопрос");
        self::assertStringContainsString("Спасибо\nза вопрос", $text, 'простое текстовое тело сохраняет перенос строки');
    }

    public function testТемыИЧерновикОтветаЧитаемы(): void
    {
        self::assertStringContainsString('Заявка принята', leadConfirmSubject());
        self::assertStringContainsString('Ответ школы', leadReplySubject());

        $draft = leadReplyDraft('Мария');

        self::assertStringContainsString('Здравствуйте, Мария!', $draft);
        self::assertStringContainsString('первом бесплатном уроке', $draft);
        self::assertStringContainsString('Сколько раз в неделю', $draft);
    }
}
