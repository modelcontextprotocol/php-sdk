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

use Mcp\Schema\Extension\ServerExtensionInterface;

/**
 * The MCP Apps extension (io.modelcontextprotocol/ui).
 *
 * MCP Apps allows servers to expose interactive HTML UI applications as resources.
 * Clients that support the extension render these in sandboxed iframes.
 *
 * Enable on the server via {@see \Mcp\Server\Builder::enableExtension()}.
 *
 * @see https://github.com/modelcontextprotocol/ext-apps
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class McpApps implements ServerExtensionInterface
{
    public const EXTENSION_ID = 'io.modelcontextprotocol/ui';
    public const MIME_TYPE = 'text/html;profile=mcp-app';
    public const URI_SCHEME = 'ui';

    public function getId(): string
    {
        return self::EXTENSION_ID;
    }

    /**
     * @return array{mimeTypes: string[]}
     */
    public function getCapabilities(): array
    {
        return ['mimeTypes' => [self::MIME_TYPE]];
    }

    /**
     * The marker value for the `_meta.ui` field on a UI resource *descriptor*
     * (its `resources/list` entry), flagging the resource as an MCP App.
     *
     * The structured CSP/permissions metadata instead belongs on the resource
     * *content* (the `resources/read` payload) via {@see UiResourceContentMeta}.
     */
    public static function resourceMarker(): \stdClass
    {
        return new \stdClass();
    }
}
