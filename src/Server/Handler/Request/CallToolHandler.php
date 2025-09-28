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

use Mcp\Capability\Tool\ToolCallerInterface;
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class CallToolHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ToolCallerInterface $toolCaller,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof CallToolRequest;
    }

    public function handle(CallToolRequest|HasMethodInterface $message, SessionInterface $session): Response|Error
    {
        \assert($message instanceof CallToolRequest);

        try {
            $content = $this->toolCaller->call($message);
        } catch (ExceptionInterface $exception) {
            $this->logger->error(
                \sprintf('Error while executing tool "%s": "%s".', $message->name, $exception->getMessage()),
                [
                    'tool' => $message->name,
                    'arguments' => $message->arguments,
                ],
            );

            return Error::forInternalError('Error while executing tool', $message->getId());
        }

        return new Response($message->getId(), $content);
    }
}
