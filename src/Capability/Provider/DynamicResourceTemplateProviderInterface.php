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

use Mcp\Schema\ResourceTemplate;

/**
 * Provider for runtime resource template discovery and reading.
 *
 * Implement ClientAwareInterface to access ClientGateway.
 *
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
interface DynamicResourceTemplateProviderInterface
{
    /**
     * @return iterable<ResourceTemplate>
     */
    public function getResourceTemplates(): iterable;

    public function supportsResourceTemplate(string $uriTemplate): bool;

    public function readResource(string $uriTemplate, string $uri): mixed;

    /**
     * @return array<string, class-string|object>
     */
    public function getCompletionProviders(string $uriTemplate): array;
}
