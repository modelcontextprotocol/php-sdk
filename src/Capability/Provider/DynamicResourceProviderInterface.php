<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Provider;

use Mcp\Schema\Resource;

/**
 * Provider for runtime resource discovery and reading.
 *
 * Implement ClientAwareInterface to access ClientGateway.
 *
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
interface DynamicResourceProviderInterface
{
    /**
     * @return iterable<Resource>
     */
    public function getResources(): iterable;

    public function supportsResource(string $uri): bool;

    public function readResource(string $uri): mixed;
}
