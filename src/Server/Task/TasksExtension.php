<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Task;

use Mcp\Exception\LogicException;
use Mcp\Schema\Extension\ArgumentProvidingExtensionInterface;
use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\TasksCancelRequest;
use Mcp\Schema\Request\TasksGetRequest;
use Mcp\Schema\Request\TasksUpdateRequest;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\Handler\TasksCancelHandler;
use Mcp\Server\Task\Handler\TasksGetHandler;
use Mcp\Server\Task\Handler\TasksUpdateHandler;

/**
 * The `io.modelcontextprotocol/tasks` extension (SEP-2663).
 *
 * On a server, enable it with a store and the `tasks/*` surface appears; on a
 * client, enabling it without one is what declares the extension:
 *
 * ```php
 * Server::builder()->enableExtension(new TasksExtension(new InMemoryTaskStore()));
 * Client::builder()->enableExtension(new TasksExtension());
 * ```
 *
 * Creating a task is the *server's* decision, made per request — a client opts
 * in once by declaring the extension and then handles whichever result shape
 * arrives. A handler creates one through the {@see TaskContext} it can declare
 * as a parameter, and returns the {@see \Mcp\Schema\Result\CreateTaskResult}.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksExtension implements ArgumentProvidingExtensionInterface
{
    public const ID = 'io.modelcontextprotocol/tasks';

    /**
     * @param ?TaskStoreInterface $store where a server keeps its tasks; a client declaring the extension needs none
     */
    public function __construct(
        private readonly ?TaskStoreInterface $store = null,
        private readonly ?TaskInputHandlerInterface $inputHandler = null,
    ) {
    }

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(self::ID);
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [
            TasksGetRequest::class,
            TasksUpdateRequest::class,
            TasksCancelRequest::class,
        ];
    }

    public function getRequestHandlers(): iterable
    {
        $store = $this->getStore();

        yield new TasksGetHandler($store);
        yield new TasksUpdateHandler($store, $this->inputHandler);
        yield new TasksCancelHandler($store);
    }

    public function getArgumentProviders(): array
    {
        $store = $this->getStore();

        return [
            TaskContext::class => static fn (SessionInterface $session, Request $request): TaskContext => new TaskContext($session, $store),
        ];
    }

    /**
     * @throws LogicException when enabled on a server without a store
     */
    public function getStore(): TaskStoreInterface
    {
        return $this->store ?? throw new LogicException(\sprintf('The Tasks extension needs a store to serve tasks/*; enable it with new %s(new InMemoryTaskStore()) or another %s.', self::class, TaskStoreInterface::class));
    }

    /**
     * Whether a client's declared capabilities include this extension.
     *
     * The gate on task creation: a client that never declared it has no
     * `tasks/get` loop and would be left holding a handle it does not know how
     * to redeem.
     *
     * @param ?array<string, mixed> $extensions the `extensions` member of the request's clientCapabilities
     */
    public static function declaredBy(?array $extensions): bool
    {
        return \array_key_exists(self::ID, $extensions ?? []);
    }
}
