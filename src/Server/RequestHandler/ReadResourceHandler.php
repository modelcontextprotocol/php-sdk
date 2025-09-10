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
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\MethodHandlerInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ReadResourceHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ReadResourceRequest;
    }

    public function handle(ReadResourceRequest|HasMethodInterface $message): Response|Error
    {
        \assert($message instanceof ReadResourceRequest);

        try {
            $contents = $this->registry->handleReadResource($message->uri);
        } catch (ResourceNotFoundException $e) {
            return new Error($message->getId(), Error::RESOURCE_NOT_FOUND, $e->getMessage());
        } catch (ExceptionInterface) {
            return Error::forInternalError('Error while reading resource', $message->getId());
        }

        return new Response($message->getId(), new ReadResourceResult($contents));
    }
}
