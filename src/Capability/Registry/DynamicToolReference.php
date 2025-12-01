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

use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Capability\Provider\DynamicToolProviderInterface;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Tool;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;

/**
 * @author Mateu Aguilo Bosch <mateu@mateuaguilo.com>
 */
class DynamicToolReference extends ElementReference implements ClientAwareInterface
{
    use DynamicArgumentPreparationTrait;

    public function __construct(
        public readonly Tool $tool,
        public readonly DynamicToolProviderInterface $provider,
        public readonly string $toolName,
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
        return $this->provider->executeTool($this->toolName, $arguments);
    }

    /**
     * @return Content[]
     */
    public function formatResult(mixed $toolExecutionResult): array
    {
        return (new ToolResultFormatter())->format($toolExecutionResult);
    }
}
