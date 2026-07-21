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

use Mcp\Exception\ElicitationException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handler for elicitation requests from the server.
 *
 * The MCP server may request additional information from the user during tool
 * execution. This handler wraps a user-provided callback that presents the
 * requested schema to the user and returns their response.
 *
 * @implements RequestHandlerInterface<ElicitResult>
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ElicitationRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ElicitationCallbackInterface $callback,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ElicitRequest;
    }

    /**
     * @return Response<ElicitResult>|Error
     */
    public function handle(Request $request): Response|Error
    {
        \assert($request instanceof ElicitRequest);

        try {
            $result = $this->callback->__invoke($request);

            return new Response($request->getId(), $result);
        } catch (ElicitationException $e) {
            $this->logger->error('Elicitation failed: '.$e->getMessage(), ['exception' => $e]);

            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during elicitation', ['exception' => $e]);

            return Error::forInternalError('Error while processing elicitation', $request->getId());
        }
    }
}
