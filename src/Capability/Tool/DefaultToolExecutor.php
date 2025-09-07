<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Tool;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\ToolExecutionException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default implementation of ToolExecutorInterface that uses ReferenceProvider
 * and ReferenceHandlerInterface to execute tools.
 *
 * @author Pavel Buchnev <butschster@gmail.com>
 */
final class DefaultToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws ToolExecutionException if the tool execution fails
     * @throws ToolNotFoundException  if the tool is not found
     */
    public function call(CallToolRequest $request): CallToolResult
    {
        $toolName = $request->name;
        $arguments = $request->arguments ?? [];

        $this->logger->debug('Executing tool', ['name' => $toolName, 'arguments' => $arguments]);

        $toolReference = $this->referenceProvider->getTool($toolName);

        if (null === $toolReference) {
            $this->logger->warning('Tool not found', ['name' => $toolName]);
            throw new ToolNotFoundException($request);
        }

        try {
            $result = $this->referenceHandler->handle($toolReference, $arguments);
            $formattedResult = $toolReference->formatResult($result);

            $this->logger->debug('Tool executed successfully', [
                'name' => $toolName,
                'result_type' => \gettype($result),
            ]);

            return new CallToolResult($formattedResult);
        } catch (\Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'name' => $toolName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ToolExecutionException($request, $e);
        }
    }
}
