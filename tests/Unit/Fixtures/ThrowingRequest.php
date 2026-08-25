<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Fixtures;

use Mcp\Schema\JsonRpc\Request;

/**
 * Stands in for a message class that fails with an unexpected throwable instead of a proper
 * InvalidInputMessageException, e.g. through a PHP TypeError caused by an unvalidated payload.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ThrowingRequest extends Request
{
    public static function getMethod(): string
    {
        return 'test/throwing';
    }

    protected static function fromParams(?array $params): static
    {
        throw new \TypeError('Internal detail that must not leak to the client.');
    }

    protected function getParams(): ?array
    {
        return null;
    }
}
