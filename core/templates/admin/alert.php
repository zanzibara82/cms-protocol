<?php

declare(strict_types=1);

/** @var string $title */
/** @var string $reason */

if ($title === '' && $reason === '') {
    return;
}

echo '<div class="mb-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3" role="alert">';

if ($title !== '') {
    echo '<p class="text-sm font-medium text-bad">' . e($title) . '</p>';
}

if ($reason !== '') {
    echo '<p class="mt-1 text-sm text-bad">' . e($reason) . '</p>';
}

echo '</div>';
