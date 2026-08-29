<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Task\Handler;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\TasksGetRequest;
use Mcp\Schema\Result\TaskResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\TaskCapabilityGuard;
use Mcp\Server\Task\TaskStoreInterface;

/**
 * Answers a poll with the task's current state (SEP-2663).
 *
 * @implements RequestHandlerInterface<TaskResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksGetHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskStoreInterface $store,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof TasksGetRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof TasksGetRequest);

        // The task surface is gated on negotiation: to a client that never
        // declared the extension these methods do not exist as *its* methods,
        // and -32021 says which declaration is missing.
        if (null !== $refusal = TaskCapabilityGuard::refuse($request, $session)) {
            return $refusal;
        }

        $task = $this->store->get($request->taskId);

        if (null === $task) {
            // An unknown id and a lapsed one are the same answer: the spec
            // reserves -32602 for exactly this, and a client cannot act on the
            // difference.
            return Error::forInvalidParams(\sprintf('Unknown task "%s".', $request->taskId), $request->getId(), ['taskId' => $request->taskId]);
        }

        return new Response($request->getId(), new TaskResult($task));
    }
}
