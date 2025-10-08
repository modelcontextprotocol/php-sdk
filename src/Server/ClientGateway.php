<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Exception\LogicException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class ClientGateway
{
    private ?TransportInterface $transport = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function connect(TransportInterface $transport): void
    {
        if (null !== $this->transport) {
            throw new LogicException('ClientGateway is already connected to a transport.');
        }

        $this->transport = $transport;
    }

    /**
     * Sends a JSON-RPC request to the connected MCP client.
     */
    public function request(HasMethodInterface $message): ResultInterface
    {
        if (null === $this->transport) {
            throw new LogicException('Cannot send request to client without an initialized transport.');
        }

        $this->transport->send(json_encode($message, \JSON_THROW_ON_ERROR), [/* HOW TO GET THE SESSION */]);

        $this->transport->onMessage(function (string $message, ?Uuid $sessionId) {
            $this->logger->info('Received message from client: '.$message);
        });

        return new CreateSamplingMessageResult(Role::Assistant, new TextContent('Hello Back!'), 'gpt-4');
    }
}
