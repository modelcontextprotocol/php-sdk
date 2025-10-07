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

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ReadResourceHandler implements MethodHandlerInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(HasMethodInterface $message): bool
    {
        return $message instanceof ReadResourceRequest;
    }

    public function handle(ReadResourceRequest|HasMethodInterface $message, SessionInterface $session): Response|Error
    {
        \assert($message instanceof ReadResourceRequest);

        $uri = $message->uri;

        $this->logger->debug('Reading resource', ['uri' => $uri]);

        try {
            $reference = $this->referenceProvider->getResource($uri);
            if (null === $reference) {
                throw new ResourceNotFoundException($message);
            }

            $result = $this->referenceHandler->handle($reference, ['uri' => $uri]);

            if ($reference instanceof ResourceTemplateReference) {
                $formatted = $reference->formatResult($result, $uri, $reference->resourceTemplate->mimeType);
            } else {
                $formatted = $reference->formatResult($result, $uri, $reference->schema->mimeType);
            }

            return new Response($message->getId(), new ReadResourceResult($formatted));
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri]);

            return new Error($message->getId(), Error::RESOURCE_NOT_FOUND, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Error while reading resource "%s": "%s".', $uri, $e->getMessage()));

            return Error::forInternalError('Error while reading resource', $message->getId());
        }
    }
}
