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

use Mcp\Capability\Completion\CompleterInterface;
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Handles completion/complete requests.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class CompletionCompleteHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly CompleterInterface $completer,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof CompletionCompleteRequest;
    }

    public function handle(CompletionCompleteRequest|HasMethodInterface $message, SessionInterface $session): Response|Error
    {
        \assert($message instanceof CompletionCompleteRequest);

        try {
            $result = $this->completer->complete($message);
        } catch (ExceptionInterface) {
            return Error::forInternalError('Error while handling completion request', $message->getId());
        }

        return new Response($message->getId(), $result);
    }
}
