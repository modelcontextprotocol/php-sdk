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

/**
 * The contents of a resource, as text or as binary blob.
 *
 * @property string                    $uri
 * @property string|null               $mimeType
 * @property array<string, mixed>|null $meta
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ResourceContents extends \JsonSerializable
{
}
