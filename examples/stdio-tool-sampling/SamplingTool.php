<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioToolSampling;

use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientAwareTrait;
use Psr\Log\LoggerInterface;

final class SamplingTool implements ClientAwareInterface
{
    use ClientAwareTrait;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('SamplingTool instantiated for sampling example.');
    }

    public function trySampling(): string
    {
        $this->logger->info('About to send a sampling request to the client.');

        $response = $this->getClientGateway()->request(
            new CreateSamplingMessageRequest(
                messages: [new SamplingMessage(Role::User, new TextContent('Hello from server!'))],
                maxTokens: 100,
            ),
        );

        \assert($response instanceof CreateSamplingMessageResult);

        return 'Client Response: '.$response->content->text;
    }
}
