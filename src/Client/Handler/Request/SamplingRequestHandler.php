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

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

/**
 * Handler for sampling requests from the server.
 *
 * The MCP server may request the client to sample an LLM during tool execution.
 * This handler wraps a user-provided callback that performs the actual LLM call.
 *
 * @implements RequestHandlerInterface<array<string, mixed>>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SamplingRequestHandler implements RequestHandlerInterface
{
    /**
     * @param callable(CreateSamplingMessageRequest): CreateSamplingMessageResult $callback
     */
    public function __construct(
        private readonly mixed $callback,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CreateSamplingMessageRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        assert($request instanceof CreateSamplingMessageRequest);

        $result = ($this->callback)($request);

        return $result->jsonSerialize();
    }
}
