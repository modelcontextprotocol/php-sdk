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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
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
    /**
     * Upper bound on the request body read for a POST, guarding against memory
     * exhaustion from an oversized (or unbounded chunked) payload.
     */
    public const DEFAULT_MAX_BODY_BYTES = 4 * 1024 * 1024;

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

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

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $payload = $this->readBody($body);

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

        $result = $this->protocol->handle($payload, $headers);

        if ($result->isStream()) {
            return $this->sse($result->frames);
        }

        if ($result->isEmpty()) {
            return $this->responseFactory->createResponse($result->httpStatus);
        }

        return $this->json($result->toJson(), $result->httpStatus);
    }

    /**
     * @param \Closure(): \Generator<mixed> $frames
     */
    private function sse(\Closure $frames): ResponseInterface
    {
        $logger = $this->logger;

        $callback = static function () use ($frames, $logger): void {
            try {
                foreach ($frames() as $frame) {
                    // A null frame is a keep-alive tick: an SSE comment the
                    // client ignores, and the write PHP needs to spot a drop.
                    echo null === $frame
                        ? ": keep-alive\n\n"
                        : 'data: '.json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n\n";
                    flush();
                }
            } catch (\Throwable $e) {
                // Headers are long sent, so this cannot become an error
                // response; the client sees a close without the closure frame.
                $logger->error('Subscription stream ended with an error.', ['exception' => $e]);
            }
        };

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new CallbackStream($callback, $this->logger));
    }

    /**
     * Reads the request body, bounded by {@see self::$maxBodyBytes}.
     *
     * Returns the body contents, or `null` when the payload exceeds the cap.
     * A single `read()` call is not enough: PSR-7 allows it to return fewer
     * bytes than requested, so a body arriving across more than one physical
     * read would otherwise be silently truncated. Reading incrementally to
     * EOF is what {@see StreamableHttpTransport::readBody()} already does for
     * the same reason.
     */
    private function readBody(StreamInterface $body): ?string
    {
        $size = $body->getSize();
        if (null !== $size && $size > $this->maxBodyBytes) {
            return null;
        }

        $contents = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' === $chunk) {
                break;
            }

            $contents .= $chunk;
            if (\strlen($contents) > $this->maxBodyBytes) {
                return null;
            }
        }

        return $contents;
    }

    private function json(string $payload, int $status): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));
    }
}
