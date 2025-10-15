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
        private readonly ReferenceProviderInterface $referenceProvider,
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
            $reference = $this->referenceProvider->getPrompt($promptName);
            if (null === $reference) {
                throw new PromptNotFoundException($request);
            }

            $arguments['_session'] = $session;

            $result = $this->referenceHandler->handle($reference, $arguments);

            $formatted = $reference->formatResult($result);

            return new Response($request->getId(), new GetPromptResult($formatted));
        } catch (PromptNotFoundException $e) {
            $this->logger->error('Prompt not found', ['prompt_name' => $promptName]);

            return new Error($request->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        } catch (PromptGetException|ExceptionInterface $e) {
            $this->logger->error('Error while handling prompt', ['prompt_name' => $promptName]);

            return Error::forInternalError('Error while handling prompt: '.$e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Error while handling prompt', ['prompt_name' => $promptName]);

            return Error::forInternalError('Error while handling prompt: '.$e->getMessage(), $request->getId());
        }
    }
}
