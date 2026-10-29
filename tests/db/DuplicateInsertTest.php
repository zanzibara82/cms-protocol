<?php

declare(strict_types=1);

namespace Cms\Tests\db;

/**
 * Помощник ядра для гонок записи: распознавание приёма уникального ключа. Два одновременных
 * сохранения претендуют на один адрес/название — проигравшая вставка обязана кончиться
 * человеческой причиной, а не HTTP 500 сырого исключения. Распознаватель проверяется
 * настоящим отказом базы: подставные исключения могли бы закрепить фантомную форму сообщения.
 */
final class DuplicateInsertTest extends NewsDbTestCase
{
    public function testДвойнойАдресРаспознаётся(): void
    {
        insert('news_tag', ['id' => newId(), 'slug' => 'povtorniy', 'label' => 'Двойка']);

        $caught = $this->duplicateOf(static function (): void {
            // Тот же label при другом адресе: приём уникального ключа по надписи.
            insert('news_tag', ['id' => newId(), 'slug' => 'drugoy-adres', 'label' => 'Двойка']);
        });

        self::assertNotNull($caught, 'Повторная надпись ловится настоящим отказом базы');
        self::assertTrue(duplicateInsertException($caught), 'Приём уникального ключа распознаётся по коду и тексту');
    }

    public function testОтказВнешнегоКлючаДубликатомНеНазывается(): void
    {
        $tag = newId();
        insert('news_tag', ['id' => $tag, 'slug' => 'vnesoen', 'label' => 'Внешний']);

        // Ссылка на несуществующий материал: MySQL-код 1452 живёт тем же SQLSTATE 23000,
        // но «адрес занят» на чужую причину не переписывается.
        $caught = $this->duplicateOf(static function () use ($tag): void {
            insert('news_item_tag', ['item_id' => newId(), 'tag_id' => $tag]);
        });

        self::assertNotNull($caught, 'Ссылка на несуществующий материал ловится отказом базы');
        self::assertFalse(duplicateInsertException($caught), 'Отказ внешнего ключа — не дубликат: причина честная своя');
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
}
