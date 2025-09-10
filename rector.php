<?php

declare(strict_types=1);

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

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/examples',
    ])
    ->withPhpSets(php83: true)
    // For cleaner imports
    ->withImportNames(removeUnusedImports: true)
    // Common code quality improvements
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::TYPE_DECLARATION,
    ])
    ->withRules([
        ClassPropertyAssignToConstructorPromotionRector::class,
    ])
    ->withSkip([
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/tests/Capability/Discovery/HandlerResolverTest.php',
        ],
        RemoveUnusedPrivateMethodParameterRector::class => [
            __DIR__.'/tests/Capability/Discovery/HandlerResolverTest.php',
        ],
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/tests/Capability/Discovery/HandlerResolverTest.php',
        ],
        RemoveUnusedPrivateMethodRector::class => [
            __DIR__.'/tests/Capability/Discovery/HandlerResolverTest.php',
        ],
        __DIR__.'/vendor',
    ]);
