<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Помощник человеческой причины для гонок записи: приём уникального ключа разыменовывается
 * проверками «какое поле занято», первая сработавшая формулировка становится ответом, чужие
 * отказы (внешний ключ и прочие) перебрасываются, а не переписываются в «занято». Проверяется
 * настоящим отказом базы: подставные исключения могли бы закрепить фантомную форму
 * распознавания (см. DuplicateInsertTest).
 */
final class DuplicateReasonTest extends NewsDbTestCase
{
    public function testПерваяСработавшаяПроверкаСтановитсяПричиной(): void
    {
        insert('news_tag', ['id' => newId(), 'slug' => 'pervaya-prichina', 'label' => 'Двойка']);

        $caught = $this->duplicateOf(static function (): void {
            // Тот же label при другом адресе: приём уникального ключа по надписи.
            insert('news_tag', ['id' => newId(), 'slug' => 'drugoy-adres', 'label' => 'Двойка']);
        });

        self::assertNotNull($caught, 'Повторная надпись ловится настоящим отказом базы');

        $lateRuns = 0;
        $reason = duplicateReason(
            $caught,
            static fn (): string => '',
            static fn (): string => 'Такой адрес уже занят — предложите свой.',
            static function () use (&$lateRuns): string {
                $lateRuns++;

                return 'Занято что-то ещё.';
            }
        );

        self::assertSame('Такой адрес уже занят — предложите свой.', $reason);
        self::assertSame(0, $lateRuns, 'После сработавшей проверки остальные не спрашиваются');
    }

    public function testЗапаснаяСтрокаОтвечаетПослеПустыхПроверок(): void
    {
        insert('news_tag', ['id' => newId(), 'slug' => 'zapasnaya-prichina', 'label' => 'Двойка']);

        $caught = $this->duplicateOf(static function (): void {
            insert('news_tag', ['id' => newId(), 'slug' => 'drugoy-adres-2', 'label' => 'Двойка']);
        });

        self::assertNotNull($caught, 'Повторная надпись ловится настоящим отказом базы');

        // Проба сказала «не оно», запасная строка — безусловная последняя проверка: контракт
        // вызывающего в том, чтобы она всегда была, иначе человеку уйдёт пустая причина.
        $reason = duplicateReason(
            $caught,
            static fn (): string => '',
            'Такой адрес уже занят другим материалом — предложите свой.'
        );

        self::assertSame('Такой адрес уже занят другим материалом — предложите свой.', $reason);
    }

    public function testЧужойОтказПеребрасываетсяНаМесте(): void
    {
        $tag = newId();
        insert('news_tag', ['id' => $tag, 'slug' => 'vnesoen-prichina', 'label' => 'Внешний']);

        // Отказ берётся один раз и сверяется по экземпляру: перебросить обязан его самого,
        // а не любое исключение — иначе отсутствие помощника маскировалось бы чужим Error.
        $foreign = $this->foreignCatch(static function () use ($tag): void {
            // Ссылка на несуществующий материал: MySQL-код 1452 живёт тем же
            // SQLSTATE 23000, но «занято» на чужую причину не переписывается.
            insert('news_item_tag', ['item_id' => newId(), 'tag_id' => $tag]);
        });

        $probes = 0;

        try {
            duplicateReason(
                $foreign,
                static function () use (&$probes): string {
                    $probes++;

                    return 'Причина о занятости.';
                }
            );
            self::fail('Чужой отказ обязан переброситься, а не переписаться в «занято»');
        } catch (\Throwable $error) {
            self::assertSame($foreign, $error, 'Переброшен тот самый чужой отказ');
            self::assertSame(0, $probes, 'Страж срабатывает раньше проб');
        }
    }

    public function testПустыеПроверкиОтдаютПустуюСтроку(): void
    {
        insert('news_tag', ['id' => newId(), 'slug' => 'pustaya-prichina', 'label' => 'Двойка']);

        $caught = $this->duplicateOf(static function (): void {
            insert('news_tag', ['id' => newId(), 'slug' => 'drugoy-adres-3', 'label' => 'Двойка']);
        });

        self::assertNotNull($caught, 'Повторная надпись ловится настоящим отказом базы');

        // '' — сигнал нарушения контракта (нет запасной формулировки), а не человеческий ответ.
        self::assertSame('', duplicateReason($caught, static fn (): string => ''));
    }

    /** @return \Throwable|null */
    private function duplicateOf(callable $action): ?\Throwable
    {
        try {
            $action();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    /** @return \Throwable */
    private function foreignCatch(callable $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $error) {
            return $error;
        }

        self::fail('Ссылка на несуществующий материал обязана кончиться отказом базы');
    }
}
