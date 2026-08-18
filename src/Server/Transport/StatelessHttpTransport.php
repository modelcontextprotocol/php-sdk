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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Carries the modern (SEP-2575) lifecycle over HTTP.
 *
 * Deliberately not a mode of {@see StreamableHttpTransport}: that transport's
 * job is largely session management — minting an `Mcp-Session-Id`, resuming a
 * suspended fiber against it, tearing it down on DELETE — and a modern request
 * has no session to manage. What is left is a POST in, one JSON-RPC message
 * out, and a status code the protocol has already chosen.
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

    public function __construct(
        private readonly StatelessProtocol $protocol,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('OPTIONS' === $request->getMethod()) {
            return $this->responseFactory->createResponse(204);
        }

        // No GET-opened notification stream and no DELETE teardown: without a
        // session there is nothing for either to address.
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
                    // A null frame is a keep-alive tick, not a message: it goes
                    // out as an SSE comment, which the client ignores and which
                    // gives PHP the write it needs to notice a dropped peer.
                    echo null === $frame
                        ? ": keep-alive\n\n"
                        : 'data: '.json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n\n";
                    flush();
                }
            } catch (\Throwable $e) {
                // The status line and headers are long gone by the time a frame
                // fails, so there is no way to turn this into an error response.
                // Log it and let the stream end; the client sees a close without
                // the graceful-closure frame, which is exactly what it means.
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
