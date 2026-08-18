<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Exception\InvalidInputMessageException;
use Mcp\Exception\MissingRequestMetaException;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Exception\RequestStateException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Result\DiscoverResult;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Wire\Rev2026Codec;
use Mcp\Server\Wire\WireCodecInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dispatches a single modern-era (SEP-2575) request.
 *
 * Separate from {@see \Mcp\Server\Protocol} because the modern era has no
 * session to resolve, replay or keep a fiber against; the two eras share
 * request handlers, not control flow.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessProtocol
{
    private readonly WireCodecInterface $codec;

    /**
     * Methods the modern era deleted. Answered as unknown methods, which is
     * what they are to a modern server.
     */
    public const REMOVED_METHODS = [
        'initialize',
        'notifications/initialized',
        'ping',
        'logging/setLevel',
    ];

    public const DISCOVER_METHOD = 'server/discover';
    public const LISTEN_METHOD = 'subscriptions/listen';
    public const ACKNOWLEDGED_NOTIFICATION = 'notifications/subscriptions/acknowledged';

    /**
     * What a client is told when a handler fails in a way nothing anticipated.
     *
     * Deliberately generic rather than {@see \Throwable::getMessage()}: the
     * real message is logged, not returned, so an internal detail (a
     * connection string, a file path, another library's error text) never
     * reaches the client that triggered it.
     */
    private const INTERNAL_ERROR_MESSAGE = 'Internal server error.';

    /**
     * @param iterable<RequestHandlerInterface<ResultInterface>> $requestHandlers
     * @param list<ProtocolVersion>                              $supportedVersions
     */
    public function __construct(
        private readonly iterable $requestHandlers,
        private readonly MessageFactory $messageFactory,
        private readonly Configuration $configuration,
        private readonly array $supportedVersions = [ProtocolVersion::V2026_07_28],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $subscriptionLifetime = 30.0,
        ?WireCodecInterface $codec = null,
        private readonly ?StandardHeaderValidator $headerValidator = null,
        private readonly ?RequestStateCodec $requestStateCodec = null,
    ) {
        $this->codec = $codec ?? new Rev2026Codec($configuration->serverInfo);
    }

    /**
     * Answers one JSON-RPC request read from an HTTP request body.
     *
     * @param array<string, string> $headers request headers, case-insensitively matched
     */
    public function handle(string $body, array $headers = []): StatelessResult
    {
        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return StatelessResult::error(Error::forParseError($e->getMessage()), 400);
        }

        if (!\is_array($decoded)) {
            return StatelessResult::error(Error::forInvalidRequest('A JSON-RPC message must be a JSON object.'), 400);
        }

        // The id is read before anything is validated so that every error below
        // can echo it when there is one to echo. A missing or malformed id is
        // left as null rather than coerced to "" — Error::jsonSerialize()
        // already knows to omit a null id instead of fabricating one, and
        // "id": "" would falsely claim the sender issued a request with an
        // empty-string id.
        $id = $decoded['id'] ?? null;
        if (!\is_string($id) && !\is_int($id)) {
            $id = null;
        }

        $method = $decoded['method'] ?? null;
        if (!\is_string($method) || '' === $method) {
            return StatelessResult::error(Error::forInvalidRequest('A JSON-RPC message must carry a "method".', $id), 400);
        }

        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : null;

        try {
            $meta = RequestMeta::fromParams($params);
        } catch (MissingRequestMetaException $e) {
            return StatelessResult::error(Error::forInvalidParams($e->getMessage(), $id), 400);
        }

        if (null !== $versionError = $this->checkVersion($meta, $headers, $id)) {
            return $versionError;
        }

        // After the version check: a peer on the wrong revision has a more
        // fundamental problem than headers that disagree with its body.
        if (null !== $headerError = $this->headerValidator?->validate($method, $params, $headers)) {
            return StatelessResult::error(Error::forHeaderMismatch($headerError, $id), 400);
        }

        if (self::DISCOVER_METHOD === $method || self::LISTEN_METHOD === $method) {
            // Both answer with a single response tied to this request's id,
            // unlike a genuine notification — so unlike the general dispatch
            // path below, a missing id here is this request being invalid
            // rather than this request needing no answer at all.
            if (null === $id) {
                return StatelessResult::error(Error::forInvalidRequest(\sprintf('Method "%s" requires an "id".', $method)), 400);
            }

            if (self::DISCOVER_METHOD === $method) {
                return $this->encode($method, $id, $this->discover());
            }

            return $this->listen($params, $id);
        }

        if (\in_array($method, self::REMOVED_METHODS, true)) {
            return StatelessResult::error(
                Error::forMethodNotFound(\sprintf('Method "%s" does not exist in protocol version %s.', $method, $meta->protocolVersion), $id),
                404,
            );
        }

        return $this->dispatch($method, $decoded, $meta, $id);
    }

    /**
     * Header and `_meta` must agree before the version can be judged supported:
     * when they disagree the server cannot know which the client meant, so a
     * mismatch outranks an unsupported version.
     *
     * @param array<string, string> $headers
     */
    private function checkVersion(RequestMeta $meta, array $headers, string|int|null $id): ?StatelessResult
    {
        $headerVersion = $this->header($headers, 'MCP-Protocol-Version');

        if (null !== $headerVersion && $headerVersion !== $meta->protocolVersion) {
            return StatelessResult::error(
                Error::forHeaderMismatch(
                    \sprintf('MCP-Protocol-Version header "%s" contradicts the "%s" declared in _meta.', $headerVersion, $meta->protocolVersion),
                    $id,
                ),
                400,
            );
        }

        $version = ProtocolVersion::tryFrom($meta->protocolVersion);

        if (null === $version || !\in_array($version, $this->supportedVersions, true)) {
            return StatelessResult::error(
                Error::forUnsupportedProtocolVersion($meta->protocolVersion, $this->supportedVersions, $id),
                400,
            );
        }

        return null;
    }

    /**
     * Opens a `subscriptions/listen` stream. The subscription id is the
     * JSON-RPC id of this request, so there is none to mint.
     *
     * @param array<string, mixed>|null $params
     */
    private function listen(?array $params, string|int $id): StatelessResult
    {
        $notifications = \is_array($params['notifications'] ?? null) ? $params['notifications'] : null;
        $agreed = NotificationFilter::fromParams($notifications)->intersect($this->configuration->capabilities);

        $lifetime = $this->subscriptionLifetime;

        return StatelessResult::stream(static function () use ($agreed, $id, $lifetime): \Generator {
            yield [
                'jsonrpc' => '2.0',
                'method' => self::ACKNOWLEDGED_NOTIFICATION,
                'params' => [
                    '_meta' => [RequestMeta::SUBSCRIPTION_ID => $id],
                    'notifications' => (object) $agreed->toAcknowledgedArray(),
                ],
            ];

            // Cross-request notifications need a channel this SDK does not
            // define yet, so the stream only waits. The tick is not optional:
            // PHP spots a dropped peer by writing, and a sleeping loop would
            // pin an FPM worker for the full lifetime.
            $deadline = microtime(true) + $lifetime;
            while (microtime(true) < $deadline) {
                yield null;

                if (connection_aborted()) {
                    return;
                }

                usleep(250_000);
            }

            // Graceful closure (SHOULD), so the client can tell this from a
            // dropped transport.
            yield [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'resultType' => 'complete',
                    '_meta' => [RequestMeta::SUBSCRIPTION_ID => $id],
                ],
            ];
        });
    }

    private function discover(): DiscoverResult
    {
        return new DiscoverResult(
            $this->supportedVersions,
            $this->configuration->capabilities,
            $this->configuration->instructions,
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function dispatch(string $method, array $decoded, RequestMeta $meta, string|int|null $id): StatelessResult
    {
        try {
            $messages = $this->messageFactory->create(json_encode($decoded, \JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger->warning('Rejected an unparseable modern-era request.', ['method' => $method, 'exception' => $e]);

            return StatelessResult::error(Error::forMethodNotFound(\sprintf('Method "%s" is not supported.', $method), $id), 404);
        }

        $request = $messages[0] ?? null;

        // A notification (no id) is never answered, successful or not — this is
        // one of the SDK's own registered message classes, just not a Request.
        if ($request instanceof Notification) {
            return StatelessResult::accepted();
        }

        // The factory hands back an exception object rather than throwing one,
        // distinguishing a genuinely unknown method (-32601, the client should
        // stop asking) from a known method the message could not otherwise be
        // parsed into (-32600, the request itself is malformed).
        if ($request instanceof InvalidInputMessageException) {
            $unknownMethod = \sprintf('Unknown method "%s".', $method) === $request->getMessage();

            return StatelessResult::error(
                $unknownMethod
                    ? Error::forMethodNotFound($request->getMessage(), $id)
                    : Error::forInvalidRequest($request->getMessage(), $id),
                $unknownMethod ? 404 : 400,
            );
        }

        if (!$request instanceof Request) {
            // Reachable only for a well-formed Response/Error object posted to
            // this endpoint — decodable by the factory, but not a request this
            // server can answer.
            return StatelessResult::error(Error::forInvalidRequest(\sprintf('"%s" is not a request this server can answer.', $method), $id), 400);
        }

        // Request::fromArray() already rejected a missing/invalid id (as an
        // InvalidInputMessageException, handled above), so this request's id
        // is always present here — reusing it narrows $id for the rest of this
        // method instead of trusting the raw, still-nullable value from above.
        $id = $request->getId();

        $session = new Session(new InMemorySessionStore());
        $session->set(RequestMeta::class, $meta);

        // Under the same keys the handshake era writes, so everything reading
        // connection state — ClientGateway's capability probes above all — sees
        // this request's declaration instead of an empty session.
        $session->set('client_capabilities', $meta->clientCapabilities->jsonSerialize());
        $session->set('protocol_version', $meta->protocolVersion);

        try {
            $input = $this->liftInputContext($decoded['params'] ?? null);
        } catch (RequestStateException $e) {
            // Invalid params, not an authorization failure: the client only
            // echoes what it was given, and the reason stays out of the answer.
            $this->logger->warning('Rejected a requestState that failed verification.', ['method' => $method, 'reason' => $e->getMessage()]);

            return StatelessResult::error(Error::forInvalidParams('The supplied requestState failed verification.', $id), 400);
        }

        if (null !== $input) {
            $session->set(InputContext::class, $input);
        }

        if (null !== $this->requestStateCodec) {
            $session->set(RequestStateCodec::class, $this->requestStateCodec);
        }

        foreach ($this->requestHandlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            try {
                $result = $handler->handle($request, $session);
            } catch (MissingRequiredClientCapabilityException $e) {
                return StatelessResult::error(
                    Error::forMissingRequiredClientCapability($e->getMessage(), $e->requiredCapabilities, $id),
                    400,
                );
            } catch (\InvalidArgumentException $e) {
                return StatelessResult::error(Error::forInvalidParams($e->getMessage(), $id), 400);
            } catch (\Throwable $e) {
                $this->logger->error('Uncaught exception handling a modern-era request.', ['method' => $method, 'exception' => $e]);

                return StatelessResult::error(Error::forInternalError(self::INTERNAL_ERROR_MESSAGE, $id), 500);
            }

            if ($result instanceof Error) {
                return StatelessResult::error($result, 400);
            }

            return $this->encode($method, $id, $result->result);
        }

        return StatelessResult::error(Error::forMethodNotFound(\sprintf('No handler found for method "%s".', $method), $id), 404);
    }

    /**
     * Reads the multi round-trip material off a retry, verifying the state
     * before any of it reaches a handler. Neither member means a first call,
     * which is what a handler tests to decide whether it still needs to ask.
     *
     * @param array<string, mixed>|null $params
     *
     * @throws RequestStateException when a state is present but does not verify
     */
    private function liftInputContext(?array $params): ?InputContext
    {
        $responses = \is_array($params['inputResponses'] ?? null) ? $params['inputResponses'] : null;
        $state = \is_string($params['requestState'] ?? null) ? $params['requestState'] : null;

        if (null === $responses && null === $state) {
            return null;
        }

        // Answers are result objects; dropping non-objects leaves the handler
        // to ask again rather than read a malformed retry as satisfied.
        if (null !== $responses) {
            $responses = array_filter($responses, static fn (mixed $response): bool => \is_array($response));
        }

        $payload = [];

        if (null !== $state) {
            // A server with no codec never minted a state, so this one cannot
            // have come from here.
            if (null === $this->requestStateCodec) {
                throw new RequestStateException('mac');
            }

            $payload = $this->requestStateCodec->verify($state);
        }

        return new InputContext($responses ?? [], $payload);
    }

    /**
     * Runs a result through the wire codec. Passed as-is rather than via a
     * json round trip, which would turn a nested `{}` into `[]`.
     */
    private function encode(string $method, string|int $id, ResultInterface $result): StatelessResult
    {
        return StatelessResult::ok($id, $this->codec->encodeResult($method, (array) $result->jsonSerialize()));
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return $value;
            }
        }

        return null;
    }
}
