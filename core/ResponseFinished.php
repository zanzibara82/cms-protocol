<?php

declare(strict_types=1);

namespace Cms\Core;

// Исключение-сигнал: тесты ловят его вместо exit, чтобы проверить статус и тело ответа.
final class ResponseFinished extends \RuntimeException
{
}
