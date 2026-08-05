<?php

/**
 * PHP CS Fixer configuration for this ILIAS instance.
 *
 * These are exactly the rules ILIAS ships in
 * scripts/PHP-CS-Fixer/code-format.php_cs, but stored under the modern
 * ".php-cs-fixer.dist.php" file name so that IDE integrations (VS Code /
 * PhpStorm) and the php-cs-fixer 3.x CLI pick them up automatically.
 *
 * PhpStorm: Settings > PHP > Quality Tools > PHP CS Fixer -> point to this file.
 * VS Code : configured via .vscode/settings.json (junstyle.php-cs-fixer).
 */

$finder = PhpCsFixer\Finder::create()
    ->exclude([
        __DIR__ . '/components/ILIAS/Database/sql',
        __DIR__ . '/scripts/PHP-CS-Fixer/example',
    ])
    ->in([
        __DIR__ . '/cli',
        __DIR__ . '/components/ILIAS',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'strict_param' => false,
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'type_declaration_spaces' => true,
        'function_declaration' => ['closure_fn_spacing' => 'none'],
        'binary_operator_spaces' => ['default' => 'single_space'],
    ])
    ->setFinder($finder);
