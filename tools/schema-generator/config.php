<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Schema\Concern\AnnotationsFactories;
use Mcp\Schema\Concern\AudioContentFactories;
use Mcp\Schema\Concern\BlobResourceContentsFactories;
use Mcp\Schema\Concern\CallToolResultFactories;
use Mcp\Schema\Concern\EmbeddedResourceFactories;
use Mcp\Schema\Concern\IconFactories;
use Mcp\Schema\Concern\ImageContentFactories;
use Mcp\Schema\Concern\SamplingMessageContentBlocks;
use Mcp\Schema\Concern\TextContentFactories;
use Mcp\Schema\Concern\ToolSchemaNormalization;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\JsonRpc\ResultInterface;

return [
    // Every revision the SDK speaks; the generated classes cover the union of all of them.
    'revisions' => ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'],
    'spec_dir' => __DIR__.'/spec',
    'namespace' => 'Mcp\\Schema',
    'directory' => dirname(__DIR__, 2).'/src/Schema',

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
        'PromptMessage' => 'Content\\PromptMessage',
        'ResourceLink' => 'Content\\ResourceLink',
        'Role' => 'Enum\\Role',
        'SamplingMessage' => 'Content\\SamplingMessage',
        'SamplingMessageContentBlock' => 'Content\\SamplingMessageContentBlock',
        'TextContent' => 'Content\\TextContent',
        'TextResourceContents' => 'Content\\TextResourceContents',
        'Tool' => 'Tool',
        'ToolAnnotations' => 'ToolAnnotations',
        'ToolExecution' => 'ToolExecution',
        'ToolResultContent' => 'Content\\ToolResultContent',
        'ToolUseContent' => 'Content\\ToolUseContent',
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

    // Constant discriminators ("type") are exposed as readonly properties next to their constant.
    'discriminator_property' => true,

    // Constructor parameter order by wire name; unlisted properties follow, required ones first, "_meta" last.
    'order' => [
        'Annotations' => ['audience', 'priority', 'lastModified'],
        'BlobResourceContents' => ['uri', 'mimeType', 'blob', '_meta'],
        'CallToolResult' => ['content', 'isError', 'structuredContent', '_meta'],
        'PromptMessage' => ['role', 'content'],
        'ResourceLink' => ['uri', 'name', 'title', 'description', 'mimeType', 'annotations', 'size', 'icons', '_meta'],
        'SamplingMessage' => ['role', 'content', '_meta'],
        'TextResourceContents' => ['uri', 'mimeType', 'text', '_meta'],
        'Tool' => ['name', 'title', 'inputSchema', 'description', 'annotations', 'icons', '_meta', 'outputSchema', 'execution'],
        'ToolAnnotations' => ['title', 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'],
        'ToolResultContent' => ['toolUseId', 'content', 'structuredContent', 'isError', '_meta'],
        'ToolUseContent' => ['id', 'name', 'input', '_meta'],
    ],

    // Non-null defaults; such a property is only sent when it differs from its default, unless listed in "always_emit".
    'defaults' => [
        'CallToolResult.isError' => false,
        'ToolResultContent.isError' => false,
    ],
    'always_emit' => ['CallToolResult.isError'],

    // Extra interfaces implemented by a class, or extended by a union interface.
    'implements' => [
        'BlobResourceContents' => [ResourceContents::class],
        'CallToolResult' => [ResultInterface::class],
        'ContentBlock' => [Content::class],
        'SamplingMessageContentBlock' => [Content::class],
        'TextResourceContents' => [ResourceContents::class],
    ],

    // Hand-written behaviour mixed into generated classes.
    'traits' => [
        'Annotations' => [AnnotationsFactories::class],
        'AudioContent' => [AudioContentFactories::class],
        'BlobResourceContents' => [BlobResourceContentsFactories::class],
        'CallToolResult' => [CallToolResultFactories::class],
        'EmbeddedResource' => [EmbeddedResourceFactories::class],
        'Icon' => [IconFactories::class],
        'ImageContent' => [ImageContentFactories::class],
        'SamplingMessage' => [SamplingMessageContentBlocks::class],
        'TextContent' => [TextContentFactories::class],
        'Tool' => [ToolSchemaNormalization::class],
    ],

    // Static methods (usually from a trait) every constructor applies to the value: "method" or [method, parameter type].
    'transforms' => [
        'TextContent.text' => ['stringifyText', 'mixed'],
        'Tool.inputSchema' => 'normalizeInputSchema',
        'Tool.outputSchema' => 'normalizeSchema',
    ],

    // Instance methods (usually from a trait) every constructor calls last.
    'validators' => [
        'Icon' => ['validateIcon'],
        'SamplingMessage' => ['validateContentNotEmpty'],
        'Tool' => ['validateMcpHeaderAnnotations'],
    ],

    // Extra phpstan type aliases exported by a generated class.
    'phpstan_types' => [
        'Tool' => ['ToolInputSchema' => "array{type: 'object', properties?: array<mixed>|\\stdClass, required?: array<mixed>|null}"],
    ],
];
