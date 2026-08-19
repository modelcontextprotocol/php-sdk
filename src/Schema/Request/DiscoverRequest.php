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
 * Asks a server what it is and what it speaks (SEP-2575).
 *
 * The modern era's optional replacement for `initialize`: it reports rather
 * than negotiates, so a client MAY call it to learn the server's capabilities
 * up front, and MAY skip it and simply declare a revision on each request.
 *
 * @see \Mcp\Schema\Result\DiscoverResult
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class DiscoverRequest extends Request
{
    public static function getMethod(): string
    {
        return 'server/discover';
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
