<?php

declare(strict_types=1);

namespace Cms\Core;

// План — значение, а не состояние: его можно построить, показать в админке и передать в applyApp()
// отдельными шагами. Так тест проверяет план, ничего не применяя.
final class MigrationPlan
{
    /**
     * @param list<array{name: string, path: string, checksum: string}> $pending ещё не применённые файлы
     * @param list<string> $changed имена применённых файлов, чей текст изменился
     */
    public function __construct(
        public readonly string $app,
        public readonly array $pending,
        public readonly array $changed,
        public readonly string $targetVersion,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->pending === [];
    }

    // Изменившийся применённый файл — отказ, а не тихая перезапись (спецификация §6.3):
    // журнал обязан описывать ту схему, которая действительно лежит в базе.
    public function isBlocked(): bool
    {
        return $this->changed !== [];
    }

    public function blockMessage(): string
    {
        return 'Файл миграции ' . implode(', ', $this->changed) . ' изменён после применения. Обновите его новой миграцией.';
    }

    /** @return list<string> */
    public function pendingNames(): array
    {
        return array_map(static fn (array $file): string => (string) $file['name'], $this->pending);
    }
}
