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

use Mcp\Capability\Registry\DynamicResourceReference;
use Mcp\Capability\Registry\DynamicResourceTemplateReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<ReadResourceResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ReadResourceHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ReadResourceRequest;
    }

    /**
     * @return Response<ReadResourceResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ReadResourceRequest);

        $uri = $request->uri;

        $this->logger->debug('Reading resource', ['uri' => $uri]);

        try {
            // Try dynamic resource first, then dynamic template, fall back to static
            $reference = $this->registry->getDynamicResource($uri)
                ?? $this->registry->getDynamicResourceTemplate($uri)
                ?? $this->registry->getResource($uri);

            $arguments = [
                'uri' => $uri,
                '_session' => $session,
            ];

            // For template references, extract variables from URI
            if ($reference instanceof ResourceTemplateReference || $reference instanceof DynamicResourceTemplateReference) {
                $variables = $reference->extractVariables($uri);
                $arguments = array_merge($arguments, $variables);
            }

            $result = $this->referenceHandler->handle($reference, $arguments);

            // Format result based on reference type
            $mimeType = $this->getMimeType($reference);
            $formatted = $reference->formatResult($result, $uri, $mimeType);

            $this->logger->debug('Resource read successfully', [
                'uri' => $uri,
                'dynamic' => $reference instanceof DynamicResourceReference || $reference instanceof DynamicResourceTemplateReference,
            ]);

            return new Response($request->getId(), new ReadResourceResult($formatted));
        } catch (ResourceReadException $e) {
            $this->logger->error(\sprintf('Error while reading resource "%s": "%s".', $uri, $e->getMessage()));

            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri]);

            return Error::forResourceNotFound($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error while reading resource "%s": "%s".', $uri, $e->getMessage()));

            return Error::forInternalError('Error while reading resource', $request->getId());
        }
    }

    /**
     * Gets the MIME type from a resource reference.
     */
    private function getMimeType(
        ResourceReference|ResourceTemplateReference|DynamicResourceReference|DynamicResourceTemplateReference $reference,
    ): ?string {
        return match (true) {
            $reference instanceof ResourceReference => $reference->schema->mimeType,
            $reference instanceof DynamicResourceReference => $reference->schema->mimeType,
            $reference instanceof ResourceTemplateReference => $reference->resourceTemplate->mimeType,
            $reference instanceof DynamicResourceTemplateReference => $reference->resourceTemplate->mimeType,
        };
    }
}
