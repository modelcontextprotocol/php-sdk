<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

use Mcp\Schema\JsonRpc\Request;

/**
 * A ping, issued by either the server or the client, to check that the other party is still alive. The receiver must
 * promptly respond, or else may be disconnected.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class PingRequest extends Request
{
    public static function getMethod(): string
    {
        return 'ping';
    }

    protected static function fromParams(?array $params): static
    {
        return new self();
    }

    protected function getParams(): ?array
    {
        return null;
    }
}
