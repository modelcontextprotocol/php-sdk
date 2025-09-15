<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Resource;

use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
final class ResourceReader implements ResourceReaderInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function read(ReadResourceRequest $request): ReadResourceResult
    {
        $uri = $request->uri;

        $this->logger->debug('Reading resource', ['uri' => $uri]);

        $reference = $this->referenceProvider->getResource($uri);

        if (null === $reference) {
            $this->logger->warning('Resource not found', ['uri' => $uri]);
            throw new ResourceNotFoundException($request);
        }

        try {
            $result = $this->referenceHandler->handle($reference, ['uri' => $uri]);
            $formattedResult = $reference->formatResult($result, $uri);

            $this->logger->debug('Resource read successfully', [
                'uri' => $uri,
                'result_type' => \gettype($result),
            ]);

            return new ReadResourceResult($formattedResult);
        } catch (\Throwable $e) {
            $this->logger->error('Resource read failed', [
                'uri' => $uri,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ResourceReadException($request, $e);
        }
    }
}
