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

use Mcp\Capability\Registry\DynamicPromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<GetPromptResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class GetPromptHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetPromptRequest;
    }

    /**
     * @return Response<GetPromptResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof GetPromptRequest);

        $promptName = $request->name;
        $arguments = $request->arguments ?? [];

        try {
            // Try dynamic prompt first, fall back to static
            $reference = $this->registry->getDynamicPrompt($promptName)
                ?? $this->registry->getPrompt($promptName);

            $arguments['_session'] = $session;

            $result = $this->referenceHandler->handle($reference, $arguments);

            $formatted = $reference->formatResult($result);

            $this->logger->debug('Prompt handled successfully', [
                'name' => $promptName,
                'dynamic' => $reference instanceof DynamicPromptReference,
            ]);

            return new Response($request->getId(), new GetPromptResult($formatted));
        } catch (PromptGetException $e) {
            $this->logger->error(\sprintf('Error while handling prompt "%s": "%s".', $promptName, $e->getMessage()));

            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (PromptNotFoundException $e) {
            $this->logger->error('Prompt not found', ['prompt_name' => $promptName]);

            return Error::forResourceNotFound($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error while handling prompt "%s": "%s".', $promptName, $e->getMessage()));

            return Error::forInternalError('Error while handling prompt', $request->getId());
        }
    }
}
