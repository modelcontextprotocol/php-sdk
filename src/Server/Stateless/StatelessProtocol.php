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
 * The handshake-era {@see \Mcp\Server\Protocol} is built around a session that
 * outlives the request: it resolves one, replays `initialize` state into it,
 * and keeps a fiber attached to it across HTTP round-trips. None of that
 * survives into the modern era, where each request is self-describing and
 * independent, so this is a separate dispatcher rather than a mode flag on the
 * old one — the two eras share handlers, not control flow.
 *
 * Request handlers are reused verbatim. They expect a session, so each request
 * gets an ephemeral one that is discarded when the request ends; handlers that
 * merely stash per-request scratch state keep working, and handlers that tried
 * to persist across requests would have had nothing to persist into anyway.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessProtocol
{
    private readonly WireCodecInterface $codec;

    /**
     * Methods the modern era deleted outright. They are answered as unknown
     * methods rather than as errors specific to each, because that is exactly
     * what they are to a modern server — listing them separately only serves
     * to document the intent.
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

        // Both the deleted methods and never-known ones are "not found". The
        // 404 pairs the HTTP layer with the JSON-RPC code so a client that
        // routes on status alone reaches the same conclusion.
        if (\in_array($method, self::REMOVED_METHODS, true)) {
            return StatelessResult::error(
                Error::forMethodNotFound(\sprintf('Method "%s" does not exist in protocol version %s.', $method, $meta->protocolVersion), $id),
                404,
            );
        }

        return $this->dispatch($method, $decoded, $meta, $id);
    }

    /**
     * The header and `_meta` must agree on the version before that version can
     * be judged supported or not.
     *
     * The order matters: when the two disagree, the server has been told two
     * different things and cannot know which the client meant, so "these
     * contradict" is the honest answer even if one of the two values happens to
     * be unsupported. Reporting it as an unsupported version instead would send
     * the client off to renegotiate a version that was never the problem.
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
     * Opens a `subscriptions/listen` stream.
     *
     * The subscription needs no identifier of its own: the spec defines it as
     * the JSON-RPC id of this very request, which both sides already have. So
     * there is no id to mint, store, or hand back — every frame is tagged with
     * something the client chose.
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

            // Nothing else is emitted yet: delivering list-changed and
            // resource-updated notifications means observing mutations made by
            // *other* requests, which under a share-nothing runtime needs a
            // cross-process channel this SDK does not yet define. Holding the
            // stream open for a bounded window keeps the subscription honest —
            // the client sees an open, acknowledged stream — without pinning a
            // worker indefinitely for events that cannot arrive.
            //
            // The tick is what makes that bound real. PHP only learns the peer
            // hung up by trying to write to it, so a loop that merely sleeps
            // holds its worker for the full lifetime after the client has gone.
            // On a fixed-size FPM pool a handful of abandoned subscriptions is
            // enough to starve every other request, which is exactly what makes
            // it worth the extra yield.
            $deadline = microtime(true) + $lifetime;
            while (microtime(true) < $deadline) {
                yield null;

                if (connection_aborted()) {
                    return;
                }

                usleep(250_000);
            }

            // Graceful closure (SHOULD): tell the client the subscription ended
            // deliberately, so it can distinguish this from a dropped transport.
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
     * Runs a result through the wire codec on its way out.
     *
     * The round-trip through JSON is what flattens the result — and everything
     * nested inside it — into the plain arrays the codec stamps. Handing the
     * codec objects instead would make it responsible for knowing how each one
     * serializes, which is exactly the coupling this layer exists to avoid.
     */
    private function encode(string $method, string|int $id, ResultInterface $result): StatelessResult
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return StatelessResult::ok($id, $this->codec->encodeResult($method, $body));
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
