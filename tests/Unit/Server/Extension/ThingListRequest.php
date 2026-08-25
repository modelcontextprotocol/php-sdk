<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Extension;

use Mcp\Schema\JsonRpc\Request;

final class ThingListRequest extends Request
{
    public static function getMethod(): string
    {
        return 'com.example/things.list';
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
