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
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Exception\InvalidArgumentException;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Mcp\Server\Transport\Http\StatelessResponder;
use Mcp\Server\Wire\InboundClassifier;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Carries MCP over HTTP, in either protocol era.
 *
 * Every request is classified once, before anything else looks at it, and
 * routed to the lifecycle it belongs to: a per-request envelope claiming a
 * modern revision goes to {@see StatelessProtocol}, everything else — the
 * `initialize` handshake, its session's later requests, its `DELETE` teardown —
 * goes to the session machinery below. One endpoint, both eras, nothing for the
 * client to pick.
 *
 * A server run without a modern-era dispatcher (see
 * {@see \Mcp\Server\Builder::withoutModernEra()}) serves the handshake era
 * alone and refuses modern claims, naming the revisions it does serve.
 *
 * @extends BaseTransport<ResponseInterface>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StreamableHttpTransport extends BaseTransport implements StatelessAwareTransportInterface
{
    use ReadsBoundedBody;

    public const SESSION_HEADER = 'Mcp-Session-Id';
    public const PROTOCOL_VERSION_HEADER = 'Mcp-Protocol-Version';

    /**
     * Upper bound on the request body read for a POST, guarding against memory
     * exhaustion from an oversized (or unbounded chunked) payload.
     */
    public const DEFAULT_MAX_BODY_BYTES = 4 * 1024 * 1024;

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private StatelessResponder $responder;
    private InboundClassifier $classifier;

    private ?StatelessProtocol $stateless = null;

    private ?string $immediateResponse = null;
    private ?int $immediateStatusCode = null;

    /** @var list<MiddlewareInterface>|null null until {@see self::listen()} resolves the defaults */
    private ?array $middleware;

    /**
     * @param iterable<MiddlewareInterface>|null $middleware `null` installs {@see self::defaultMiddleware()}; `[]` disables all middleware
     */
    public function __construct(
        private ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?iterable $middleware = null,
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        parent::__construct($logger);

        if ($this->maxBodyBytes < 1) {
            throw new InvalidArgumentException('maxBodyBytes must be at least 1.');
        }

        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->responder = new StatelessResponder($this->responseFactory, $this->streamFactory, $this->logger);
        $this->classifier = new InboundClassifier();

        if (null === $middleware) {
            // Left unresolved: the default stack's version middleware has to
            // know which revisions this endpoint serves, and the modern
            // dispatcher arrives after the constructor.
            $this->middleware = null;
        } else {
            $this->middleware = self::normalizeMiddleware($middleware);
            if ([] === $this->middleware) {
                $this->logger->warning('Streamable HTTP transport started with an empty middleware list. Default security protections (CORS, DNS rebinding, protocol version validation) are disabled. Pass null (or omit the argument) to use the secure defaults, or include them via [...StreamableHttpTransport::defaultMiddleware(), $yourMiddleware].');
            }

            // Custom middleware runs before the request's era is classified, so a
            // ProtocolVersionMiddleware here rejects every modern-era request by
            // default — it only accepts the handshake versions it was built for.
            foreach ($this->middleware as $entry) {
                if ($entry instanceof ProtocolVersionMiddleware) {
                    $this->logger->warning('A custom middleware list includes ProtocolVersionMiddleware. It runs before the modern (2026-07-28) era is classified and rejects that era\'s requests by default, since it only recognises handshake revisions. Remove it from the custom list — the transport already applies it to handshake-era traffic on its own via self::handshakeMiddleware().');

                    break;
                }
            }
        }
    }

    /**
     * Secure default middleware stack applied when no `$middleware` is provided to the constructor.
     *
     * These run at the edge, before the request's era is known, because what
     * they enforce — origin policy, DNS rebinding — is true of both eras. The
     * `MCP-Protocol-Version` header rule is not: it belongs to the handshake
     * era, so {@see self::handshakeMiddleware()} carries it instead and the
     * modern leg answers for its own revisions.
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

    /**
     * Middleware applied only to requests classified as handshake-era traffic.
     *
     * @return list<MiddlewareInterface>
     */
    public static function handshakeMiddleware(): array
    {
        return [
            new ProtocolVersionMiddleware(),
        ];
    }

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
    }

    public function send(string $data, array $context): void
    {
        $this->immediateResponse = $data;
        $this->immediateStatusCode = $context['status_code'] ?? 200;
    }

    public function listen(): ResponseInterface
    {
        $handler = new MiddlewareRequestHandler(
            $this->middleware ??= self::defaultMiddleware(),
            \Closure::fromCallable([$this, 'handleRequest']),
        );

        return $handler->handle($this->request);
    }

    protected function handleOptionsRequest(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    /**
     * @param string $body the request body, already read and bounded by {@see self::handleRequest()}
     */
    protected function handlePostRequest(string $body): ResponseInterface
    {
        $this->handleMessage($body, $this->sessionId);

        if (null !== $this->immediateResponse) {
            $response = $this->responseFactory->createResponse($this->immediateStatusCode ?? 200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->immediateResponse));

            return $response;
        }

        if (null !== $this->sessionFiber) {
            $this->logger->info('Fiber suspended, handling via SSE.');

            return $this->createStreamedResponse();
        }

        return $this->createJsonResponse();
    }

    protected function handleDeleteRequest(): ResponseInterface
    {
        if (!$this->sessionId) {
            return $this->createErrorResponse(Error::forInvalidRequest(self::SESSION_HEADER.' header is required.'), 400);
        }

        $this->handleSessionEnd($this->sessionId);

        return $this->responseFactory->createResponse(200);
    }

    protected function createJsonResponse(): ResponseInterface
    {
        $outgoingMessages = $this->getOutgoingMessages($this->sessionId);

        if (empty($outgoingMessages)) {
            return $this->responseFactory->createResponse(202)
                ->withHeader('Content-Type', 'application/json');
        }

        $messages = array_column($outgoingMessages, 'message');
        $responseBody = 1 === \count($messages) ? $messages[0] : '['.implode(',', $messages).']';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($responseBody));

        if ($this->sessionId) {
            $response = $response->withHeader(self::SESSION_HEADER, $this->sessionId->toRfc4122());
        }

        return $response;
    }

    protected function createStreamedResponse(): ResponseInterface
    {
        $callback = function (): void {
            try {
                $this->logger->info('SSE: Starting request processing loop');

                while ($this->sessionFiber->isSuspended()) {
                    $this->flushOutgoingMessages($this->sessionId);

                    $pendingRequests = $this->getPendingRequests($this->sessionId);

                    if (empty($pendingRequests)) {
                        $yielded = $this->sessionFiber->resume();
                        $this->handleFiberYield($yielded, $this->sessionId);
                        continue;
                    }

                    $resumed = false;
                    foreach ($pendingRequests as $pending) {
                        $requestId = $pending['request_id'];
                        $timestamp = $pending['timestamp'];
                        $timeout = $pending['timeout'] ?? 120;

                        $response = $this->checkForResponse($requestId, $this->sessionId);

                        if (null !== $response) {
                            $yielded = $this->sessionFiber->resume($response);
                            $this->handleFiberYield($yielded, $this->sessionId);
                            $resumed = true;
                            break;
                        }

                        if (time() - $timestamp >= $timeout) {
                            $error = Error::forInternalError('Request timed out', $requestId);
                            $yielded = $this->sessionFiber->resume($error);
                            $this->handleFiberYield($yielded, $this->sessionId);
                            $resumed = true;
                            break;
                        }
                    }

                    if (!$resumed) {
                        usleep(100000);
                    } // Prevent tight loop
                }

                $this->handleFiberTermination();
            } finally {
                $this->sessionFiber = null;
            }
        };

        $stream = new CallbackStream($callback, $this->logger);
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody($stream);

        if ($this->sessionId) {
            $response = $response->withHeader(self::SESSION_HEADER, $this->sessionId->toRfc4122());
        }

        return $response;
    }

    protected function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber->getReturn();

        if (null !== $finalResult) {
            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                echo "event: message\n";
                echo "data: {$encoded}\n\n";
                @ob_flush();
                flush();
            } catch (\JsonException $e) {
                $this->logger->error('SSE: Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }

        $this->sessionFiber = null;
    }

    protected function flushOutgoingMessages(?Uuid $sessionId): void
    {
        $messages = $this->getOutgoingMessages($sessionId);

        foreach ($messages as $message) {
            echo "event: message\n";
            echo "data: {$message['message']}\n\n";
            @ob_flush();
            flush();
        }
    }

    protected function createErrorResponse(Error $jsonRpcError, int $statusCode): ResponseInterface
    {
        $payload = json_encode($jsonRpcError, \JSON_THROW_ON_ERROR);
        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        if (405 === $statusCode) {
            $response = $response->withHeader('Allow', 'POST, DELETE, OPTIONS');
        }

        return $response;
    }

    /**
     * Reads the request body, bounded by {@see self::$maxBodyBytes}.
     */
    private function readBody(StreamInterface $body): ?string
    {
        return $this->readBoundedBody($body, $this->maxBodyBytes);
    }

    /**
     * @param iterable<MiddlewareInterface> $middleware
     *
     * @return list<MiddlewareInterface>
     */
    private static function normalizeMiddleware(iterable $middleware): array
    {
        $normalized = [];
        foreach ($middleware as $m) {
            if (!$m instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Streamable HTTP middleware must implement Psr\\Http\\Server\\MiddlewareInterface.');
            }
            $normalized[] = $m;
        }

        return $normalized;
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        if ('OPTIONS' === $request->getMethod()) {
            return $this->handleOptionsRequest();
        }

        // Read once, here: the era decision needs the body, and so does
        // whichever leg it routes to. A PSR-7 stream over `php://input` cannot
        // be read twice.
        $body = null;
        if ('POST' === $request->getMethod()) {
            $body = $this->readBody($request->getBody());

            if (null === $body) {
                $this->logger->warning('Rejected POST body exceeding the maximum allowed size.', ['limit' => $this->maxBodyBytes]);

                return $this->createErrorResponse(Error::forInvalidRequest(\sprintf('Request body exceeds the maximum allowed size of %d bytes.', $this->maxBodyBytes)), 413);
            }
        }

        $classification = $this->classifier->classify($request->getMethod(), $body, self::headers($request));

        if ($classification->isRejected()) {
            \assert(null !== $classification->error);

            return $this->responder->error($classification->error, $classification->httpStatus);
        }

        if ($classification->modern) {
            return $this->handleModernRequest($body ?? '', $classification->claimedVersion ?? '');
        }

        // The version-header rule only reaches the traffic it is about. Running
        // it at the edge would let it answer a modern claim with the handshake
        // era's revision list, ahead of the leg that knows better.
        return (new MiddlewareRequestHandler(
            self::handshakeMiddleware(),
            fn (ServerRequestInterface $handshake): ResponseInterface => $this->handleHandshakeRequest($handshake, $body),
        ))->handle($request);
    }

    private function handleHandshakeRequest(ServerRequestInterface $request, ?string $body): ResponseInterface
    {
        $sessionIdHeaders = $request->getHeader(self::SESSION_HEADER);
        if (\count($sessionIdHeaders) > 1) {
            return $this->createErrorResponse(Error::forInvalidRequest(self::SESSION_HEADER.' header must not be repeated.'), 400);
        }

        $sessionIdString = $sessionIdHeaders[0] ?? '';

        try {
            $this->sessionId = $sessionIdString ? Uuid::fromString($sessionIdString) : null;
            // Symfony UID 5.4/6.4 throw the global parent; newer versions throw a namespaced subclass.
        } catch (\InvalidArgumentException) {
            return $this->createErrorResponse(Error::forInvalidRequest(self::SESSION_HEADER.' header must be a valid UUID.'), 400);
        }

        return match ($request->getMethod()) {
            'POST' => $this->handlePostRequest($body ?? ''),
            'DELETE' => $this->handleDeleteRequest(),
            default => $this->createErrorResponse(Error::forInvalidRequest('Method Not Allowed'), 405),
        };
    }

    /**
     * Answers a request that claimed the modern era's per-request envelope.
     */
    private function handleModernRequest(string $body, string $claimedVersion): ResponseInterface
    {
        if (null === $this->stateless) {
            return $this->responder->error(
                Error::forUnsupportedProtocolVersion($claimedVersion, ProtocolVersion::handshakeVersions()),
                400,
            );
        }

        return $this->responder->respond($this->stateless->handle($body, self::headers($this->request)));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(ServerRequestInterface $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }
}
