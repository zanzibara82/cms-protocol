<?php

declare(strict_types=1);

// Весь вывод проходит через e(): шаблон, который напечатал переменную напрямую, — находка стража.
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
