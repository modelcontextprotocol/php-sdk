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

use Mcp\Capability\Formatter\PromptResultFormatter;
use Mcp\Capability\Provider\DynamicPromptProviderInterface;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Prompt;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;

/**
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
class DynamicPromptReference extends ElementReference implements ClientAwareInterface
{
    use DynamicArgumentPreparationTrait;

    /**
     * @param array<string, class-string|object> $completionProviders
     */
    public function __construct(
        public readonly Prompt $prompt,
        public readonly DynamicPromptProviderInterface $provider,
        public readonly string $promptName,
        public readonly array $completionProviders = [],
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
        return $this->provider->getPrompt($this->promptName, $arguments);
    }

    /**
     * @return PromptMessage[]
     */
    public function formatResult(mixed $promptGenerationResult): array
    {
        return (new PromptResultFormatter())->format($promptGenerationResult);
    }
}
