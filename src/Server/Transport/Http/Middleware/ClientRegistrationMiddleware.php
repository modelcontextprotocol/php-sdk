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
use Mcp\Exception\ClientRegistrationException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\ClientRegistrarInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OAuth 2.0 Dynamic Client Registration (RFC 7591) middleware.
 *
 * Handles POST /register requests by delegating to a ClientRegistrarInterface
 * and enriches /.well-known/oauth-authorization-server responses with the
 * registration_endpoint.
 */
final class ClientRegistrationMiddleware implements MiddlewareInterface
{
    private const REGISTRATION_PATH = '/register';

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly ClientRegistrarInterface $registrar,
        private readonly string $localBaseUrl,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if ('' === trim($localBaseUrl)) {
            throw new InvalidArgumentException('The $localBaseUrl must not be empty.');
        }

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('POST' === $request->getMethod() && self::REGISTRATION_PATH === $path) {
            return $this->handleRegistration($request);
        }

        $response = $handler->handle($request);

        if ('GET' === $request->getMethod() && '/.well-known/oauth-authorization-server' === $path) {
            return $this->enrichAuthServerMetadata($response);
        }

        return $response;
    }

    private function handleRegistration(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getBody()->__toString();

        try {
            $decoded = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonResponse(400, [
                'error' => 'invalid_client_metadata',
                'error_description' => 'Request body must be valid JSON.',
            ]);
        }

        if (!$decoded instanceof \stdClass) {
            return $this->jsonResponse(400, [
                'error' => 'invalid_client_metadata',
                'error_description' => 'Request body must be a JSON object.',
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = (array) $decoded;

        try {
            $result = $this->registrar->register($data);
        } catch (ClientRegistrationException $e) {
            return $this->jsonResponse(400, [
                'error' => 'invalid_client_metadata',
                'error_description' => $e->getMessage(),
            ]);
        }

        return $this->jsonResponse(201, $result, [
            'Cache-Control' => 'no-store',
        ]);
    }

    private function enrichAuthServerMetadata(ResponseInterface $response): ResponseInterface
    {
        if (200 !== $response->getStatusCode()) {
            return $response;
        }

        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $metadata = json_decode($stream->__toString(), true);

        if (!\is_array($metadata)) {
            return $response;
        }

        $metadata['registration_endpoint'] = rtrim($this->localBaseUrl, '/').self::REGISTRATION_PATH;

        return $response
            ->withBody($this->streamFactory->createStream(
                json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ))
            ->withHeader('Content-Type', 'application/json')
            ->withoutHeader('Content-Length');
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $extraHeaders
     */
    private function jsonResponse(int $status, array $data, array $extraHeaders = []): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(
                json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ));

        foreach ($extraHeaders as $name => $value) {
            if ('' !== $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
