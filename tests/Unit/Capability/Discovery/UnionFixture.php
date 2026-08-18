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

class UnionFixture
{
    /**
     * @param string[]|int  $mixedish      an array branch beside a scalar one
     * @param int|string    $scalars       two scalar branches
     * @param string[]      $arrayOnly     one branch, and it is an array
     * @param string[]|null $nullableArray an array branch that may be null
     */
    public function handle(array|int $mixedish, int|string $scalars, array $arrayOnly, ?array $nullableArray): string
    {
        return '';
    }
}
