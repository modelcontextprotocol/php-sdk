<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\StatelessResult;
use Mcp\Server\Transport\CallbackStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turns a modern-era answer into the HTTP response carrying it.
 *
 * Shared by the transports that can serve that era, so a `subscriptions/listen`
 * stream and a plain result look the same on the wire whether the endpoint
 * serves the modern lifecycle alone or alongside the handshake one.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessResponder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function respond(StatelessResult $result): ResponseInterface
    {
        if ($result->isStream()) {
            \assert(null !== $result->frames);

            return $this->sse($result->frames);
        }

        if ($result->isEmpty()) {
            return $this->responseFactory->createResponse($result->httpStatus);
        }

        return $this->json($result->toJson(), $result->httpStatus);
    }

    public function error(Error $error, int $httpStatus): ResponseInterface
    {
        return $this->json(json_encode($error, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), $httpStatus);
    }

    public function json(string $payload, int $status): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));
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
}
