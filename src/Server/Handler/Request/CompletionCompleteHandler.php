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

use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceReference;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Container\ContainerInterface;

/**
 * Handles completion/complete requests.
 *
 * @implements RequestHandlerInterface<CompletionCompleteResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CompletionCompleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CompletionCompleteRequest;
    }

    /**
     * @return Response<CompletionCompleteResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CompletionCompleteRequest);

        $name = $request->argument['name'] ?? '';
        $value = $request->argument['value'] ?? '';

        try {
            $reference = match (true) {
                $request->ref instanceof PromptReference => $this->referenceProvider->getPrompt($request->ref->name),
                $request->ref instanceof ResourceReference => $this->referenceProvider->getResource($request->ref->uri),
            };

            $providers = $reference->completionProviders;
            $provider = $providers[$name] ?? null;
            if (null === $provider) {
                return new Response($request->getId(), new CompletionCompleteResult([]));
            }

            if (\is_string($provider)) {
                if (!class_exists($provider)) {
                    return Error::forInternalError('Invalid completion provider', $request->getId());
                }
                $provider = $this->container?->has($provider) ? $this->container->get($provider) : new $provider();
            }

            if (!$provider instanceof ProviderInterface) {
                return Error::forInternalError('Invalid completion provider type', $request->getId());
            }

            $completions = $provider->getCompletions($value);
            $total = \count($completions);
            $hasMore = $total > 100;
            $paged = \array_slice($completions, 0, 100);

            return new Response($request->getId(), new CompletionCompleteResult($paged, $total, $hasMore));
        } catch (PromptNotFoundException|ResourceNotFoundException $e) {
            return Error::forResourceNotFound($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            return Error::forInternalError('Error while handling completion request', $request->getId());
        }
    }
}
