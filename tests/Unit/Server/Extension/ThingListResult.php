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

use Mcp\Schema\JsonRpc\ResultInterface;

final class ThingListResult implements ResultInterface
{
    /**
     * @param list<string> $things
     */
    public function __construct(
        public readonly array $things,
    ) {
    }

    /**
     * @return array{things: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['things' => $this->things];
    }
}
