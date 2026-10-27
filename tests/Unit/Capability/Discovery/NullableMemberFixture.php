<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class NullableMemberFixture
{
    /**
     * @param array<string|null> $values a generic whose element may be null
     */
    public function genericWithNullElement(array $values): void
    {
    }

    /**
     * @param array{tags: string[]|null} $filter a shape key that is a nullable list
     */
    public function shapeWithNullableList(array $filter): void
    {
    }

    /**
     * @param array{point: array{x: int}|null} $input a shape key that is a nullable nested shape
     */
    public function shapeWithNullableShape(array $input): void
    {
    }

    /**
     * @param NullLogger $logger a class whose name contains "null"
     */
    public function classNamedNull(LoggerInterface $logger): void
    {
    }
}
