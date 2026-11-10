<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

use Mcp\Exception\LogicException;

/**
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class MimeType
{
    /**
     * @throws LogicException if the fileinfo extension is missing
     */
    public static function guess(string $path): ?string
    {
        if (!\function_exists('mime_content_type')) {
            throw new LogicException(\sprintf('Detecting the MIME type of "%s" requires the "fileinfo" PHP extension. Install it or pass the MIME type explicitly.', $path));
        }

        $mimeType = mime_content_type($path);

        return false === $mimeType ? null : $mimeType;
    }
}
