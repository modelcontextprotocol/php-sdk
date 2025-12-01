<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;
use Psr\Container\ContainerInterface;

/**
 * Handles the execution of element references by resolving handlers and delegating argument preparation.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class ReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Handles the execution of an element reference.
     *
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $session = $arguments['_session'];
        $clientGateway = new ClientGateway($session);

        // Resolve the handler to a callable and optionally an instance
        [$callable, $instance] = $this->resolveHandler($reference->handler);

        // Set client on reference if it implements ClientAwareInterface
        // (e.g., dynamic references forward this to their providers)
        if ($reference instanceof ClientAwareInterface) {
            $reference->setClient($clientGateway);
        }

        // Set client on handler instances that implement ClientAwareInterface
        if ($instance instanceof ClientAwareInterface) {
            $instance->setClient($clientGateway);
        }

        // Delegate argument preparation to the reference
        $preparedArguments = $reference->prepareArguments($arguments, $callable);

        return \call_user_func($callable, ...$preparedArguments);
    }

    /**
     * Resolves a handler to a callable, optionally returning an instance.
     *
     * @param \Closure|array|string $handler
     *
     * @return array{0: callable, 1: ?object}
     */
    private function resolveHandler(\Closure|array|string $handler): array
    {
        // String handler: class name with __invoke or function name
        if (\is_string($handler)) {
            if (class_exists($handler) && method_exists($handler, '__invoke')) {
                $instance = $this->getClassInstance($handler);

                return [$instance, $instance];
            }

            if (\function_exists($handler)) {
                return [$handler, null];
            }

            throw new InvalidArgumentException("Invalid string handler: '{$handler}' is not a valid class or function.");
        }

        // Closure handler
        if ($handler instanceof \Closure) {
            return [$handler, null];
        }

        // Array handler: [class/object, method]
        if (\is_array($handler)) {
            [$classOrObject, $methodName] = $handler;

            if (\is_string($classOrObject)) {
                $instance = $this->getClassInstance($classOrObject);

                return [[$instance, $methodName], $instance];
            }

            // Already an object instance
            return [[$classOrObject, $methodName], $classOrObject];
        }

        throw new InvalidArgumentException('Invalid handler type');
    }

    private function getClassInstance(string $className): object
    {
        if (null !== $this->container && $this->container->has($className)) {
            return $this->container->get($className);
        }

        return new $className();
    }
}
