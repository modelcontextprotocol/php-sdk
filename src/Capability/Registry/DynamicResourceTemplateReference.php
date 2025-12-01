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
use Mcp\Capability\Provider\DynamicResourceTemplateProviderInterface;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\ResourceTemplate;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;

/**
 * @author Mateu Aguilo Bosch <mateu@mateuaguilo.com>
 */
class DynamicResourceTemplateReference extends ElementReference implements ClientAwareInterface
{
    use DynamicArgumentPreparationTrait;

    private readonly UriTemplateMatcher $uriTemplateMatcher;

    /**
     * @param array<string, class-string|object> $completionProviders
     */
    public function __construct(
        public readonly ResourceTemplate $resourceTemplate,
        public readonly DynamicResourceTemplateProviderInterface $provider,
        public readonly string $uriTemplate,
        public readonly array $completionProviders = [],
        ?UriTemplateMatcher $uriTemplateMatcher = null,
    ) {
        parent::__construct($this, false);

        $this->uriTemplateMatcher = $uriTemplateMatcher ?? new UriTemplateMatcher();
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
        return $this->provider->readResource($this->uriTemplate, $arguments['uri'] ?? '');
    }

    /**
     * @return array<int, string>
     */
    public function getVariableNames(): array
    {
        return $this->uriTemplateMatcher->getVariableNames($this->resourceTemplate->uriTemplate);
    }

    public function matches(string $uri): bool
    {
        return $this->uriTemplateMatcher->matches($uri, $this->resourceTemplate->uriTemplate);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractVariables(string $uri): array
    {
        return $this->uriTemplateMatcher->extractVariables($uri, $this->resourceTemplate->uriTemplate);
    }

    /**
     * @return ResourceContents[]
     */
    public function formatResult(mixed $readResult, string $uri, ?string $mimeType = null): array
    {
        return (new ResourceResultFormatter())->format($readResult, $uri, $mimeType, $this->resourceTemplate->meta);
    }
}
