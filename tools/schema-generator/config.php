<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Schema\Concern\CallToolResultFactories;
use Mcp\Schema\Concern\TextContentFactories;
use Mcp\Schema\Concern\ToolSchemaNormalization;

return [
    // Every revision the SDK speaks; the generated classes cover the union of all of them.
    'revisions' => ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'],
    'spec_dir' => __DIR__.'/spec',
    'namespace' => 'Mcp\\Schema\\Generated',
    'directory' => dirname(__DIR__, 2).'/src/Schema/Generated',

    // Spec definition => class (relative to "namespace"). References to anything else fail the generation.
    'types' => [
        'Annotations' => 'Annotations',
        'AudioContent' => 'Content\\AudioContent',
        'BlobResourceContents' => 'Content\\BlobResourceContents',
        'CallToolResult' => 'Result\\CallToolResult',
        'ContentBlock' => 'Content\\ContentBlock',
        'EmbeddedResource' => 'Content\\EmbeddedResource',
        'Icon' => 'Icon',
        'ImageContent' => 'Content\\ImageContent',
        'ResourceLink' => 'Content\\ResourceLink',
        'Role' => 'Enum\\Role',
        'TextContent' => 'Content\\TextContent',
        'TextResourceContents' => 'Content\\TextResourceContents',
        'Tool' => 'Tool',
        'ToolAnnotations' => 'ToolAnnotations',
        'ToolExecution' => 'ToolExecution',
    ],

    // Inline enums that get a named enum class instead of staying a validated string.
    'inline_enums' => [
        'Icon.theme' => 'Enum\\IconTheme',
        'ToolExecution.taskSupport' => 'Enum\\TaskSupport',
    ],

    // Open-ended objects kept as array<string, mixed>.
    'maps' => ['MetaObject', 'RequestMetaObject', 'ResultMetaObject', 'NotificationMetaObject', 'JSONObject'],

    // Arbitrary JSON values kept as mixed.
    'any' => ['JSONValue'],

    // Fields owned by the wire codecs (Rev2025Codec/Rev2026Codec), not by the value objects.
    'omit' => ['CallToolResult.resultType'],

    // Constructor parameter order by wire name; unlisted properties follow, required ones first.
    'order' => [
        'CallToolResult' => ['content', 'isError', 'structuredContent', '_meta'],
        'Tool' => ['name', 'title', 'inputSchema', 'description', 'annotations', 'icons', '_meta', 'outputSchema', 'execution'],
    ],

    // Hand-written behaviour mixed into generated classes.
    'traits' => [
        'CallToolResult' => [CallToolResultFactories::class],
        'TextContent' => [TextContentFactories::class],
        'Tool' => [ToolSchemaNormalization::class],
    ],

    // Static methods (usually from a trait) that every constructor applies to the property value.
    'transforms' => [
        'Tool.inputSchema' => 'normalizeInputSchema',
        'Tool.outputSchema' => 'normalizeSchema',
    ],
];
