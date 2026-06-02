<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server\Transport\Http\OAuth\SigningKeyInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishes the authorization server's public signing keys as a JWK Set
 * (RFC 7517) so resource servers can verify self-issued access tokens.
 */
final class JwksMiddleware implements MiddlewareInterface
{
    public const DEFAULT_PATH = '/.well-known/jwks.json';

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<SigningKeyInterface> */
    private array $signingKeys;

    /**
     * @param SigningKeyInterface|iterable<SigningKeyInterface> $signingKeys One key, or a set to support rotation
     */
    public function __construct(
        SigningKeyInterface|iterable $signingKeys,
        private readonly string $path = self::DEFAULT_PATH,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->signingKeys = $signingKeys instanceof SigningKeyInterface
            ? [$signingKeys]
            : array_values([...$signingKeys]);

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('GET' !== $request->getMethod() || $this->path !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        $keys = array_map(static fn (SigningKeyInterface $key): array => $key->getPublicJwk(), $this->signingKeys);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'max-age=3600')
            ->withBody($this->streamFactory->createStream(json_encode(['keys' => $keys], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
    }
}
