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
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\RegistryInterface;
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
use McpServer\Primitives\AbstractClaimsAwarePrimitive;
use McpServer\Session\SessionClaimsService;
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
        private readonly RegistryInterface $registry,
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
            $providers = match (true) {
                $request->ref instanceof PromptReference => $this->registry->getPrompt($request->ref->name)->completionProviders,
                $request->ref instanceof ResourceReference => $this->resourceCompletionProviders($request->ref->uri),
            };

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
			if ($provider instanceof AbstractClaimsAwarePrimitive) {
				$claimsService = $this->container->get(SessionClaimsService::class);
				$claims = $claimsService->getClaims($session);
				if ($claims) {
					$provider->setClaims($claims);
				}
			}

            $completions = $provider->getCompletions($value);
            $total = \count($completions);
            $hasMore = $total > 100;
            $paged = \array_slice($completions, 0, 100);

            return new Response($request->getId(), new CompletionCompleteResult($paged, $total, $hasMore));
        } catch (PromptNotFoundException|ResourceNotFoundException $e) {
            // The reference names something the server does not have, which is
            // a bad parameter rather than a missing resource.
            return Error::forInvalidParams($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            return Error::forInternalError('Error while handling completion request', $request->getId());
        }
    }

    /**
     * @return array<string, class-string|object>
     */
    private function resourceCompletionProviders(string $uri): array
    {
        $reference = $this->registry->getResource($uri);

        return $reference instanceof ResourceTemplateReference ? $reference->completionProviders : [];
    }
}
