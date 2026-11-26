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

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TasksCancelRequest;
use Mcp\Schema\Extension\Tasks\TasksGetRequest;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\TaskManager;

/**
 * Answers `tasks/get` and `tasks/cancel` (SEP-2663).
 *
 * @implements RequestHandlerInterface<Task|EmptyResult>
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class TasksHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TaskManager $tasks,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof TasksGetRequest || $request instanceof TasksCancelRequest;
    }

    /**
     * @return Response<Task|EmptyResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof TasksGetRequest || $request instanceof TasksCancelRequest);

        $context = new RequestContext($session, $request);
        $version = $context->getProtocolVersion();

        // The extension does not exist below 2026-07-28 (SEP-2663, Backward Compatibility).
        if (!$version->isAtLeast(ProtocolVersion::V2026_07_28)) {
            return Error::forMethodNotFound(\sprintf('Method "%s" does not exist in protocol version %s.', $request::getMethod(), $version->value), $request->getId());
        }

        $task = $this->tasks->find($context, $request->taskId);

        if (null === $task) {
            return Error::forInvalidParams(\sprintf('Unknown task "%s".', $request->taskId), $request->getId());
        }

        if ($request instanceof TasksGetRequest) {
            return new Response($request->getId(), $task);
        }

        $this->tasks->cancel($task);

        return new Response($request->getId(), new EmptyResult());
    }
}
