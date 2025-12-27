<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\CustomDependencies\Service;

interface StatsServiceInterface
{
    /**
     * @return array<string, int>
     */
    public function getSystemStats(): array;
}
