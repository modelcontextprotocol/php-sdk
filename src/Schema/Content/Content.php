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
 * Any content block of a message, a prompt or a tool result.
 *
 * @property string $type
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface Content extends \JsonSerializable
{
}
