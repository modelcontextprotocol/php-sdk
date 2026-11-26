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

use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\Tasks\TasksCancelRequest;
use Mcp\Schema\Extension\Tasks\TasksGetRequest;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Extension\ServerExtensionInterface;
use Mcp\Server\Task\Handler\TasksHandler;

/**
 * The `io.modelcontextprotocol/tasks` extension (SEP-2663).
 *
 * On a server, enable it with the {@see TaskManager} the tools create tasks
 * through; on a client, enabling it without one is what declares it.
 *
 * @experimental
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksExtension implements ServerExtensionInterface
{
    public const ID = 'io.modelcontextprotocol/tasks';

    public function __construct(
        private readonly ?TaskManager $tasks = null,
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
            TasksCancelRequest::class,
        ];
    }

    /**
     * @throws LogicException when enabled on a server without a task manager
     */
    public function getRequestHandlers(): iterable
    {
        yield new TasksHandler($this->tasks ?? throw new LogicException(\sprintf('The Tasks extension needs a %s to serve tasks/*.', TaskManager::class)));
    }
}
