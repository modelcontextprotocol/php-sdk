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
            $providers = [];

            if ($request->ref instanceof PromptReference) {
                $providers = $this->registry->getDynamicPromptCompletionProviders($request->ref->name);
                if (null === $providers) {
                    $reference = $this->registry->getPrompt($request->ref->name);
                    $providers = $reference->completionProviders;
                }
            } elseif (str_contains($request->ref->uri, '{')) {
                // URI template - check dynamic resource template providers first
                $providers = $this->registry->getDynamicResourceTemplateCompletionProviders($request->ref->uri);
                if (null === $providers) {
                    $reference = $this->registry->getResourceTemplate($request->ref->uri);
                    $providers = $reference->completionProviders;
                }
            } else {
                // Concrete URI - use existing static resource logic
                $reference = $this->registry->getResource($request->ref->uri);
                $providers = $reference->completionProviders;
            }

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
