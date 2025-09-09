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
use Mcp\Exception\RegistryException;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;

/**
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
final class PromptGetter implements PromptGetterInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
    ) {}

    /**
     * @throws RegistryException
     * @throws \JsonException
     */
    public function get(GetPromptRequest $request): GetPromptResult
    {
        $reference = $this->referenceProvider->getPrompt($request->name);

        if (null === $reference) {
            throw new PromptNotFoundException($request);
        }

        try {
            return new GetPromptResult(
                $reference->formatResult(
                    $this->referenceHandler->handle($reference, $request->arguments ?? []),
                ),
            );
        } catch (\Throwable $e) {
            throw new PromptGetException($request, $e);
        }
    }
}
