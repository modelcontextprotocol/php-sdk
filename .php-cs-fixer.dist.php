<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

if (!file_exists(__DIR__.'/src')) {
    exit(0);
}

$fileHeader = <<<'EOF'
    This file is part of the official PHP MCP SDK.

    A collaboration between Symfony and the PHP Foundation.

    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.
    EOF;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'protected_to_private' => false,
        'declare_strict_types' => false,
        'header_comment' => ['header' => $fileHeader],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder((new Finder())->in(__DIR__))
;
