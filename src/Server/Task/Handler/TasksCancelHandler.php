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

use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\TasksCancelRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\TaskCapabilityGuard;
use Mcp\Server\Task\TaskStoreInterface;

/**
 * Records a cancellation request (SEP-2663).
 *
 * Cooperative: the acknowledgment says the intent was received, not that the
 * work stopped. A task already in a terminal state is acknowledged unchanged —
 * cancelling twice, or cancelling something finished, is not an error, and the
 * ack is deliberately empty so a client cannot mistake it for a task envelope.
 *
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksCancelHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskStoreInterface $store,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof TasksCancelRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof TasksCancelRequest);

        // The task surface is gated on negotiation: to a client that never
        // declared the extension these methods do not exist as *its* methods,
        // and -32021 says which declaration is missing.
        if (null !== $refusal = TaskCapabilityGuard::refuse($request, $session)) {
            return $refusal;
        }

        $task = $this->store->get($request->taskId);

        if (null === $task) {
            return Error::forInvalidParams(\sprintf('Unknown task "%s".', $request->taskId), $request->getId(), ['taskId' => $request->taskId]);
        }

        if (!$task->status->isTerminal()) {
            $this->store->save($task->with(TaskStatus::Cancelled));
        }

        return new Response($request->getId(), new EmptyResult());
    }
}
