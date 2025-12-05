<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Capability\Formatter\ResourceResultFormatter;
use Mcp\Capability\Provider\DynamicResourceProviderInterface;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Resource;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;

/**
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
class DynamicResourceReference extends ElementReference implements ClientAwareInterface
{
    use DynamicArgumentPreparationTrait;

    public function __construct(
        public readonly Resource $schema,
        public readonly DynamicResourceProviderInterface $provider,
        public readonly string $uri,
    ) {
        parent::__construct($this, false);
    }

    public function setClient(ClientGateway $clientGateway): void
    {
        if ($this->provider instanceof ClientAwareInterface) {
            $this->provider->setClient($clientGateway);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): mixed
    {
        return $this->provider->readResource($arguments['uri'] ?? $this->uri);
    }

    /**
     * @return ResourceContents[]
     */
    public function formatResult(mixed $readResult, string $uri, ?string $mimeType = null): array
    {
        return (new ResourceResultFormatter())->format($readResult, $uri, $mimeType, $this->schema->meta);
    }
}
