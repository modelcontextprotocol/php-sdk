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
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<ReadResourceResult|InputRequiredResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ReadResourceHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ReadResourceRequest;
    }

    /**
     * @return Response<ReadResourceResult|InputRequiredResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ReadResourceRequest);

        $uri = $request->uri;

        $this->logger->debug('Reading resource', ['uri' => $uri]);

        try {
            $reference = $this->referenceProvider->getResource($uri);

            $arguments = [
                'uri' => $uri,
                '_session' => $session,
                '_request' => $request,
            ];

            if ($reference instanceof ResourceTemplateReference) {
                $variables = $reference->extractVariables($uri);
                $arguments = array_merge($arguments, $variables);

                $result = $this->referenceHandler->handle($reference, $arguments);

                // An ask is a result in its own right, not resource contents;
                // and a handler that built the whole result keeps what it
                // decided, caching hints included.
                if ($result instanceof InputRequiredResult || $result instanceof ReadResourceResult) {
                    return new Response($request->getId(), $result);
                }

                $formatted = $reference->formatResult($result, $uri, $reference->resourceTemplate->mimeType);
            } else {
                $result = $this->referenceHandler->handle($reference, $arguments);

                if ($result instanceof InputRequiredResult || $result instanceof ReadResourceResult) {
                    return new Response($request->getId(), $result);
                }

                $formatted = $reference->formatResult($result, $uri, $reference->resource->mimeType);
            }

            return new Response($request->getId(), new ReadResourceResult($formatted));
        } catch (MissingRequiredClientCapabilityException $e) {
            // Not a handler failure — the request was unservable, and the client
            // needs to retry declaring the capability. Rendered as -32021.
            throw $e;
        } catch (ResourceReadException $e) {
            $this->logger->error(\sprintf('Error while reading resource "%s": "%s".', $uri, $e->getMessage()), ['exception' => $e]);

            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri, 'exception' => $e]);

            // SEP-2164 retired -32002 in favour of the JSON-RPC code that
            // already meant this. Older peers still expect the old one, so the
            // revision answering the request decides.
            return (new RequestContext($session, $request))->getProtocolVersion()->usesInvalidParamsForResourceNotFound()
                ? Error::forInvalidParams($e->getMessage(), $request->getId(), ['uri' => $uri])
                : Error::forResourceNotFound($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error while reading resource "%s": "%s".', $uri, $e->getMessage()), ['exception' => $e]);

            return Error::forInternalError('Error while reading resource', $request->getId());
        }
    }
}
