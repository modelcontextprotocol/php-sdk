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
use Mcp\Schema\Request\TasksUpdateRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\TaskCapabilityGuard;
use Mcp\Server\Task\TaskInputHandlerInterface;
use Mcp\Server\Task\TaskStoreInterface;

/**
 * Delivers the input a waiting task asked for (SEP-2663).
 *
 * The handler's own job is narrow — validate the id, hand the answers to
 * whatever is running the task, acknowledge — because what to *do* with an
 * answer is the task's business, not the protocol's.
 *
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskStoreInterface $store,
        private readonly ?TaskInputHandlerInterface $inputHandler = null,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof TasksUpdateRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof TasksUpdateRequest);

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

        // Answers to something already finished, or never asked for, are
        // ignored rather than refused: the spec says to drop responses for
        // unknown or already-satisfied keys, and a terminal task is the same
        // case one step further along.
        if (TaskStatus::InputRequired === $task->status) {
            $updated = $this->inputHandler?->receive($task, $request->inputResponses)
                ?? $task->with(TaskStatus::Working, statusMessage: 'Input received.');

            $this->store->save($updated);
        }

        return new Response($request->getId(), new EmptyResult());
    }
}
