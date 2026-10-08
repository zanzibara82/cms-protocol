<?php

declare(strict_types=1);

// Отказ сохранения обязан вернуть человеку набранный текст: форма, которая очищается после
// ошибки, заставляет писать материал заново. Правило одно для всех разделов.

/**
 * @param array<string, mixed> $raw
 * @param list<string> $fields
 * @return array<string, string>
 */
function formValues(array $raw, array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $value = $raw[$field] ?? '';
        $values[$field] = is_string($value) ? $value : '';
    }

    return $values;
}

function isOn(mixed $value): bool
{
    return $value === 'on' || $value === 'true' || $value === '1';
}

/** @param array<string, string> $errors */
function fieldError(array $errors, string $field): string
{
    return (string) ($errors[$field] ?? '');
}

/** @return array{errors: array<string, string>, values: array<string, string>} */
function formError(string $message): array
{
    return ['errors' => ['form' => $message], 'values' => []];
}

/**
 * @param array<string, string> $errors
 * @param array<string, string> $values
 * @return array{errors: array<string, string>, values: array<string, string>}
 */
function errorResult(array $errors, array $values): array
{
    return ['errors' => $errors, 'values' => $values];
}
