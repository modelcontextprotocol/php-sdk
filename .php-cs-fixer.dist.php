<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

if (!file_exists(__DIR__.'/src')) {
    exit("The 'src' directory is missing. Please run this script from the project root.\n");
}

if (!file_exists(__DIR__.'/vendor/autoload.php')) {
    exit("Please run 'composer install' to set up the project dependencies.\n");
}

require __DIR__.'/vendor/autoload.php';

use Ergebnis\License;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$license = License\Type\MIT::text(
    __DIR__.'/LICENSE',
    License\Range::since(
        License\Year::fromString('2025'),
        new DateTimeZone('UTC')
    ),
    License\Holder::fromString('PHP SDK for Model Context Protocol'),
    License\Url::fromString('https://github.com/modelcontextprotocol/php-sdk')
);

$license->save();

$fileHeaderParts = [
    <<<'EOF'
        This file is part of the official PHP MCP SDK.

        A collaboration between Symfony and the PHP Foundation.

        EOF,
    \PHP_EOL.$license->header(),
];

return (new Config())
    // @see https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/pull/7777
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'protected_to_private' => false,
        'declare_strict_types' => false,
        'header_comment' => [
            'header' => trim(implode('', $fileHeaderParts)),
            'comment_type' => 'PHPDoc',
            'location' => 'after_declare_strict',
            'separate' => 'both',
        ],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new Finder())->in(__DIR__)
            ->append(
                array_merge(
                    glob(__DIR__.'/*.php'),
                    glob(__DIR__.'/.*.php')
                )
            )
    )
;
