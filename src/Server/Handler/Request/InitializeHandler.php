<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Request;

use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializeHandler implements MethodHandlerInterface
{
    public function __construct(
        public readonly ?Configuration $configuration = null,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof InitializeRequest;
    }

    public function handle(InitializeRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof InitializeRequest);

        $session->set('client_info', $message->clientInfo->jsonSerialize());

        return new Response(
            $message->getId(),
            new InitializeResult(
                $this->configuration->capabilities ?? new ServerCapabilities(),
                $this->configuration->serverInfo ?? new Implementation(),
                $this->configuration?->instructions,
            ),
        );
    }
}
