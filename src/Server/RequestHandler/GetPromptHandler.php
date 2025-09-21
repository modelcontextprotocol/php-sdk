<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Capability\Prompt\PromptGetterInterface;
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class GetPromptHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly PromptGetterInterface $promptGetter,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof GetPromptRequest;
    }

    public function handle(GetPromptRequest|HasMethodInterface $message, SessionInterface $session): Response|Error
    {
        \assert($message instanceof GetPromptRequest);

        try {
            $messages = $this->promptGetter->get($message);
        } catch (ExceptionInterface) {
            return Error::forInternalError('Error while handling prompt', $message->getId());
        }

        return new Response($message->getId(), $messages);
    }
}
