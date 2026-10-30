<?php

declare(strict_types=1);

/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */
/** @var list<array{kind: string, name: string}> $kinds */
/** @var list<array<string, mixed>> $sections */

// Блоки главной: секции живут с page_id = '' и рисуются на «/» без строки страницы.
$sectionsCard = partial('admin/pages-sections', ['sections' => $sections, 'target' => '/admin/pages/home', 'kinds' => $kinds]);

$alert = partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]);

/* @safe html */ echo adminShell('Блоки главной', $alert . $sectionsCard, $notice);
