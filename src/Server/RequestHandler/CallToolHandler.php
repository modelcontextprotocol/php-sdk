<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Capability\Tool\ToolCallerInterface;
use Mcp\Exception\ReferenceExecutionException;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\MethodHandlerInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class CallToolHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ToolCallerInterface $toolCaller,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof CallToolRequest;
    }

    public function handle(CallToolRequest|HasMethodInterface $message): Response|Error
    {
        \assert($message instanceof CallToolRequest);

        try {
            $content = $this->toolCaller->call($message);
        } catch (ToolNotFoundException $exception) {
            return Error::forInvalidParams($exception->getMessage(), $message->getId());
        } catch (ToolCallException $exception) {
            $registryException = $exception->registryException;

            if ($registryException instanceof ReferenceExecutionException) {
                return new Response($message->getId(), CallToolResult::error(array_map(
                    fn (string $message): TextContent => new TextContent($message),
                    $registryException->messages,
                )));
            }

            return new Error($message->getId(), $registryException->getCode(), $registryException->getMessage());
        }

        return new Response($message->getId(), $content);
    }
}
