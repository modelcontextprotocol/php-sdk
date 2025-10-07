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
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Container\ContainerInterface;

/**
 * Handles completion/complete requests.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CompletionCompleteHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof CompletionCompleteRequest;
    }

    public function handle(CompletionCompleteRequest|HasMethodInterface $message, SessionInterface $session): Response|Error
    {
        \assert($message instanceof CompletionCompleteRequest);

        $name = $message->argument['name'] ?? '';
        $value = $message->argument['value'] ?? '';

        $reference = match ($message->ref->type) {
            'ref/prompt' => $this->referenceProvider->getPrompt($message->ref->name),
            'ref/resource' => $this->referenceProvider->getResourceTemplate($message->ref->uri),
            default => null,
        };

        if (null === $reference) {
            return new Response($message->getId(), new CompletionCompleteResult([]));
        }

        $providers = $reference->completionProviders;
        $provider = $providers[$name] ?? null;
        if (null === $provider) {
            return new Response($message->getId(), new CompletionCompleteResult([]));
        }

        if (\is_string($provider)) {
            if (!class_exists($provider)) {
                return Error::forInternalError('Invalid completion provider', $message->getId());
            }
            $provider = $this->container?->has($provider) ? $this->container->get($provider) : new $provider();
        }

        if (!$provider instanceof ProviderInterface) {
            return Error::forInternalError('Invalid completion provider type', $message->getId());
        }

        try {
            $completions = $provider->getCompletions($value);
            $total = \count($completions);
            $hasMore = $total > 100;
            $paged = \array_slice($completions, 0, 100);

            return new Response($message->getId(), new CompletionCompleteResult($paged, $total, $hasMore));
        } catch (\Throwable) {
            return Error::forInternalError('Error while handling completion request', $message->getId());
        }
    }
}
