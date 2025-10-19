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

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for the logging/setLevel request.
 *
 * Handles client requests to set the logging level for the server.
 * The server should send all logs at this level and higher (more severe) to the client.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class SetLogLevelHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceRegistryInterface $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof SetLogLevelRequest;
    }

    public function handle(SetLogLevelRequest|HasMethodInterface $message, SessionInterface $session): Response
    {
        \assert($message instanceof SetLogLevelRequest);

        // Update the log level in the registry via the interface
        $this->registry->setLoggingLevel($message->level);

        $this->logger->debug("Log level set to: {$message->level->value}");

        return new Response($message->getId(), new EmptyResult());
    }
}
