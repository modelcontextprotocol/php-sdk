<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Apps;

use Mcp\Schema\Resource;

/**
 * Constants and helpers for the MCP Apps extension (io.modelcontextprotocol/ui).
 *
 * MCP Apps allows servers to expose interactive HTML UI applications as resources.
 * Clients that support the extension render these in sandboxed iframes.
 *
 * @see https://github.com/modelcontextprotocol/ext-apps
 */
final class McpApps
{
    /**
     * The extension identifier used in capabilities negotiation.
     */
    public const EXTENSION_ID = 'io.modelcontextprotocol/ui';

    /**
     * The MIME type for MCP App HTML resources.
     */
    public const MIME_TYPE = 'text/html;profile=mcp-app';

    /**
     * The URI scheme for MCP App resources.
     */
    public const URI_SCHEME = 'ui';

    /**
     * Returns the extension capability payload for capabilities negotiation.
     *
     * Use this when building ServerCapabilities or checking ClientCapabilities:
     *
     *     new ServerCapabilities(extensions: [
     *         McpApps::EXTENSION_ID => McpApps::extensionCapability(),
     *     ])
     *
     * @return array{mimeTypes: string[]}
     */
    public static function extensionCapability(): array
    {
        return [
            'mimeTypes' => [self::MIME_TYPE],
        ];
    }

    /**
     * Checks whether a Resource is a UI resource based on its URI scheme and MIME type.
     */
    public static function isUiResource(Resource $resource): bool
    {
        return str_starts_with($resource->uri, self::URI_SCHEME.'://')
            && self::MIME_TYPE === $resource->mimeType;
    }
}
