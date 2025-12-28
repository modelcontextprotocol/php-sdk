<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Handler\Request;

use Mcp\Exception\SamplingException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handler for sampling requests from the server.
 *
 * The MCP server may request the client to sample an LLM during tool execution.
 * This handler wraps a user-provided callback that performs the actual LLM call.
 *
 * @implements RequestHandlerInterface<CreateSamplingMessageResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SamplingRequestHandler implements RequestHandlerInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param callable(CreateSamplingMessageRequest): CreateSamplingMessageResult $callback
     */
    public function __construct(
        private readonly mixed $callback,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CreateSamplingMessageRequest;
    }

    /**
     * @return Response<CreateSamplingMessageResult>|Error
     */
    public function handle(Request $request): Response|Error
    {
        \assert($request instanceof CreateSamplingMessageRequest);

        try {
            $result = ($this->callback)($request);

            return new Response($request->getId(), $result);
        } catch (SamplingException $e) {
            $this->logger->error('Sampling failed: '.$e->getMessage());

            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during sampling', ['exception' => $e]);

            return Error::forInternalError('Error while sampling LLM', $request->getId());
        }
    }
}
