<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Completion;

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Result\CompletionCompleteResult;
use Psr\Container\ContainerInterface;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Completer implements CompleterInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function complete(CompletionCompleteRequest $request): CompletionCompleteResult
    {
        $argumentName = $request->argument['name'] ?? '';
        $currentValue = $request->argument['value'] ?? '';

        $reference = match (true) {
            'ref/prompt' === $request->ref->type => $this->referenceProvider->getPrompt($request->ref->name),
            'ref/resource' === $request->ref->type => $this->referenceProvider->getResourceTemplate($request->ref->uri),
            default => null,
        };

        if (null === $reference) {
            return new CompletionCompleteResult([]);
        }

        $providerClassOrInstance = $reference->completionProviders[$argumentName] ?? null;
        if (null === $providerClassOrInstance) {
            return new CompletionCompleteResult([]);
        }

        if (\is_string($providerClassOrInstance)) {
            if (!class_exists($providerClassOrInstance)) {
                throw new RuntimeException(\sprintf('Completion provider class "%s" does not exist.', $providerClassOrInstance));
            }

            $provider = $this->container?->has($providerClassOrInstance)
                ? $this->container->get($providerClassOrInstance)
                : new $providerClassOrInstance();
        } else {
            $provider = $providerClassOrInstance;
        }

        if (!$provider instanceof ProviderInterface) {
            throw new RuntimeException('Completion provider must implement ProviderInterface.');
        }

        $completions = $provider->getCompletions($currentValue);

        $total = \count($completions);
        $hasMore = $total > 100;
        $pagedCompletions = \array_slice($completions, 0, 100);

        return new CompletionCompleteResult($pagedCompletions, $total, $hasMore);
    }
}
