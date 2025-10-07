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

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
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
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
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

        $toolName = $message->name;
        $arguments = $message->arguments ?? [];

        $this->logger->debug('Executing tool', ['name' => $toolName, 'arguments' => $arguments]);

        try {
            $reference = $this->referenceProvider->getTool($toolName);
            if (null === $reference) {
                throw new ToolNotFoundException($message);
            }

            $result = $this->referenceHandler->handle($reference, $arguments);
            $formatted = $reference->formatResult($result);

            $this->logger->debug('Tool executed successfully', [
                'name' => $toolName,
                'result_type' => \gettype($result),
            ]);

            return new Response($message->getId(), new CallToolResult($formatted));
        } catch (ToolNotFoundException $e) {
            $this->logger->error('Tool not found', ['name' => $toolName]);

            return new Error($message->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        } catch (ToolCallException|ExceptionInterface $e) {
            $this->logger->error(\sprintf('Error while executing tool "%s": "%s".', $toolName, $e->getMessage()), [
                'tool' => $toolName,
                'arguments' => $arguments,
            ]);

            return Error::forInternalError('Error while executing tool', $message->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error during tool execution', [
                'name' => $toolName,
                'exception' => $e->getMessage(),
            ]);

            return Error::forInternalError('Error while executing tool', $message->getId());
        }
    }
}
