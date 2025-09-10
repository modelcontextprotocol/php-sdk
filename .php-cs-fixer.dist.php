<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!file_exists(__DIR__ . '/src')) {
    exit(0);
}

use Ergebnis\License;
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$license = License\Type\MIT::text(
    __DIR__ . '/LICENSE',
    License\Range::since(
        License\Year::fromString('2025'),
        new DateTimeZone('UTC')
    )
);
$license->save();


$fileHeaderParts = [
    <<<'EOF'
        This file is part of the official PHP MCP SDK.

        A collaboration between Symfony and the PHP Foundation.

        EOF,
    <<<'EOF'

        For the full copyright and license information, please view the LICENSE
        file that was distributed with this source code.
        EOF,
    trim($license->header())
];

return (new PhpCsFixer\Config())
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
    ->setFinder((new PhpCsFixer\Finder())->in(__DIR__))
;
