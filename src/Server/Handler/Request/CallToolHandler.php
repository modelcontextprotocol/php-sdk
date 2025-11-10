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
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<CallToolResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class CallToolHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $toolName = $request->name;
        $arguments = $request->arguments ?? [];

        $this->logger->debug('Executing tool', ['name' => $toolName, 'arguments' => $arguments]);

        try {
            $reference = $this->referenceProvider->getTool($toolName);

            $arguments['_session'] = $session;

            $result = $this->referenceHandler->handle($reference, $arguments);

            $structuredContent = null;
            if (null !== $reference->tool->outputSchema && !$result instanceof CallToolResult) {
                $structuredContent = $reference->extractStructuredContent($result);
            }

            if (!$result instanceof CallToolResult) {
                $result = new CallToolResult($reference->formatResult($result), false, $structuredContent);
            }

            $this->logger->debug('Tool executed successfully', [
                'name' => $toolName,
                'result_type' => \gettype($result),
                'structured_content' => $structuredContent,
            ]);

            return new Response($request->getId(), $result);
        } catch (ToolCallException $e) {
            $this->logger->error(\sprintf('Error while executing tool "%s": "%s".', $toolName, $e->getMessage()), [
                'tool' => $toolName,
                'arguments' => $arguments,
            ]);

            $errorContent = [new TextContent($e->getMessage())];

            return new Response($request->getId(), CallToolResult::error($errorContent));
        } catch (ToolNotFoundException $e) {
            $this->logger->error('Tool not found', ['name' => $toolName]);

            return new Error($request->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error during tool execution', [
                'name' => $toolName,
                'exception' => $e->getMessage(),
            ]);

            return Error::forInternalError('Error while executing tool', $request->getId());
        }
    }
}
