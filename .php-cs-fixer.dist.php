<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/core', __DIR__ . '/admin', __DIR__ . '/theme', __DIR__ . '/apps', __DIR__ . '/public', __DIR__ . '/tests', __DIR__ . '/scripts'])
    ->notPath('public/assets')
    ->notPath('public/setup.php.lock');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_trailing_whitespace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => false,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);
