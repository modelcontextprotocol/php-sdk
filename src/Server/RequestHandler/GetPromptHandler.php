<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Server\RequestHandler;

use Mcp\Capability\Registry;
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\MethodHandlerInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class GetPromptHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof GetPromptRequest;
    }

    public function handle(GetPromptRequest|HasMethodInterface $message): Response|Error
    {
        \assert($message instanceof GetPromptRequest);

        try {
            $messages = $this->registry->handleGetPrompt($message->name, $message->arguments);
        } catch (ExceptionInterface) {
            return Error::forInternalError('Error while handling prompt', $message->getId());
        }

        return new Response($message->getId(), new GetPromptResult($messages));
    }
}
