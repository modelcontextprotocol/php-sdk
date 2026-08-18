<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Mcp\Server\Transport\Http\StatelessResponder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Carries the modern (SEP-2575) lifecycle over HTTP.
 *
 * Not a mode of {@see StreamableHttpTransport}, whose job is largely session
 * management; without a session what is left is a POST in, one message out.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessHttpTransport
{
    use ReadsBoundedBody;

    /**
     * Upper bound on the request body read for a POST, guarding against memory
     * exhaustion from an oversized (or unbounded chunked) payload.
     */
    public const DEFAULT_MAX_BODY_BYTES = 4 * 1024 * 1024;

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private StatelessResponder $responder;

    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param iterable<MiddlewareInterface>|null $middleware `null` installs {@see self::defaultMiddleware()}; `[]` disables all middleware
     */
    public function __construct(
        private readonly StatelessProtocol $protocol,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        ?iterable $middleware = null,
    ) {
        $this->middleware = null === $middleware
            ? self::defaultMiddleware()
            : array_values([...$middleware]);

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->responder = new StatelessResponder($this->responseFactory, $this->streamFactory, $this->logger);
    }

    /**
     * Browser-facing protections, era-independent. The protocol-version
     * middleware is absent: here the version travels in `_meta`, so a
     * header-only check would judge half the story.
     *
     * @return list<MiddlewareInterface>
     */
    public static function defaultMiddleware(): array
    {
        return [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(),
        ];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new MiddlewareRequestHandler(
            $this->middleware,
            \Closure::fromCallable([$this, 'dispatch']),
        );

        return $handler->handle($request);
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        if ('OPTIONS' === $request->getMethod()) {
            return $this->responseFactory->createResponse(204);
        }

        // No GET stream and no DELETE teardown: there is no session to address.
        if ('POST' !== $request->getMethod()) {
            return $this->json(
                json_encode(Error::forInvalidRequest(\sprintf('The modern lifecycle accepts POST only, got %s.', $request->getMethod())), \JSON_THROW_ON_ERROR),
                405,
            );
        }

        $payload = $this->readBoundedBody($request->getBody(), $this->maxBodyBytes);

        if (null === $payload) {
            $this->logger->warning('Rejected POST body exceeding the maximum allowed size.', ['limit' => $this->maxBodyBytes]);

            return $this->json(
                json_encode(Error::forInvalidRequest(\sprintf('Request body exceeds the maximum allowed size of %d bytes.', $this->maxBodyBytes)), \JSON_THROW_ON_ERROR),
                413,
            );
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $this->responder->respond($this->protocol->handle($payload, $headers));
    }

    private function json(string $payload, int $status): ResponseInterface
    {
        return $this->responder->json($payload, $status);
    }
}
