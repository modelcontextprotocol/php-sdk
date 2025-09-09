<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Prompt;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
final class PromptGetter implements PromptGetterInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function get(GetPromptRequest $request): GetPromptResult
    {
        $promptName = $request->name;
        $arguments = $request->arguments ?? [];

        $this->logger->debug('Getting prompt', ['name' => $promptName, 'arguments' => $arguments]);

        $reference = $this->referenceProvider->getPrompt($promptName);

        if (null === $reference) {
            $this->logger->warning('Prompt not found', ['name' => $promptName]);
            throw new PromptNotFoundException($request);
        }

        try {
            $result = $this->referenceHandler->handle($reference, $arguments);
            $formattedResult = $reference->formatResult($result);

            $this->logger->debug('Prompt retrieved successfully', [
                'name' => $promptName,
                'result_type' => \gettype($result),
            ]);

            return new GetPromptResult($formattedResult);
        } catch (\Throwable $e) {
            $this->logger->error('Prompt retrieval failed', [
                'name' => $promptName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new PromptGetException($request, $e);
        }
    }
}
