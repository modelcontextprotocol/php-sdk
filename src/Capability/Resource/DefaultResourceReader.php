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
use Mcp\Exception\RegistryException;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;

/**
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
final class DefaultResourceReader implements ResourceReaderInterface
{
    public function __construct(
        private readonly ReferenceProviderInterface $referenceProvider,
        private readonly ReferenceHandlerInterface $referenceHandler,
    ) {
    }

    /**
     * @throws RegistryException
     */
    public function read(ReadResourceRequest $request): ReadResourceResult
    {
        $reference = $this->referenceProvider->getResource($request->uri);

        if (null === $reference) {
            throw new \InvalidArgumentException(\sprintf('Resource "%s" is not registered.', $request->uri));
        }

        return new ReadResourceResult(
            $reference->formatResult(
                $this->referenceHandler->handle($reference, ['uri' => $request->uri]),
                $request->uri,
            ),
        );
    }
}
