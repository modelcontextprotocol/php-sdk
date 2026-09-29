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
use Mcp\Exception\LogicException;
use Mcp\Exception\MissingRequestMetaException;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Exception\RequestStateException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\DiscoverResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Server\Wire\InboundClassifier;
use Mcp\Server\Wire\Rev2026Codec;
use Mcp\Server\Wire\WireCodecInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dispatches a single modern-era (SEP-2575) request.
 *
 * Separate from {@see Protocol} because the modern era has no
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
     *
     * A deny-list rather than an allow-list on purpose: extensions add methods
     * this class has never heard of, so an unlisted method has to reach
     * dispatch. Every removal named in the 2026-07-28 changelog belongs here —
     * the handlers behind them stay registered for the handshake era, which is
     * why the era guard, and not the registration, is what turns them off.
     */
    public const REMOVED_METHODS = [
        'initialize',
        'notifications/initialized',
        'ping',
        'logging/setLevel',
        // Replaced by the `resourceSubscriptions` filter of subscriptions/listen.
        'resources/subscribe',
        'resources/unsubscribe',
        'notifications/roots/list_changed',
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
     * @param array<string, string>                              $extensionMethods  RPC method to the extension identifier defining it
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
        ?CachePolicy $cachePolicy = null,
        private readonly ?NotificationBusInterface $notificationBus = null,
        private readonly array $extensionMethods = [],
    ) {
        $this->codec = $codec ?? new Rev2026Codec($configuration->serverInfo, $cachePolicy);

        if (null === $this->headerValidator) {
            // Not fatal: a transport without a header layer — stdio — has
            // nothing to validate. But on HTTP the headers are REQUIRED for
            // compliance, so an absent validator there is a silently
            // non-conformant server and worth saying out loud once.
            $this->logger->warning('No StandardHeaderValidator configured; the SEP-2243 request headers will not be enforced. This is correct only for a transport without a header layer.');
        }
    }

    /**
     * The modern revisions this dispatcher answers for.
     *
     * @return list<ProtocolVersion>
     */
    public function supportedVersions(): array
    {
        return $this->supportedVersions;
    }

    /**
     * Whether the transport carrying this dispatcher has a header layer whose
     * required members must be present.
     *
     * The validator's presence is the signal: it is what a header-bearing
     * transport installs, and stdio carries its metadata inline instead
     * (see the stdio binding's "Request Metadata").
     */
    private function requiresTransportHeaders(): bool
    {
        return null !== $this->headerValidator;
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
        //
        // An absent id and an unreadable one are different messages: the first
        // is a notification, the second a malformed request. JSON-RPC 2.0
        // writes "no id" as an explicit null, so that counts as absent too.
        $isNotification = !\array_key_exists('id', $decoded) || null === $decoded['id'];
        $id = $decoded['id'] ?? null;

        if (!\is_string($id) && !\is_int($id)) {
            $id = null;
        }

        $method = $decoded['method'] ?? null;
        if (!\is_string($method) || '' === $method) {
            return StatelessResult::error(Error::forInvalidRequest('A JSON-RPC message must carry a "method".', $id), 400);
        }

        $params = \is_array($decoded['params'] ?? null) ? $decoded['params'] : null;

        // No id is a notification. It gets an acknowledgment, never a response
        // — answering one with a JSON-RPC message would invent a correlation
        // the client has no request to match it against. Checked before the
        // `_meta` parse: notification params carry `NotificationMetaObject`,
        // which has none of a request's required members.
        if ($isNotification) {
            return $this->acknowledge($method);
        }

        if (null === $id) {
            return StatelessResult::error(Error::forInvalidRequest('A JSON-RPC request id must be a string or a number.'), 400);
        }

        try {
            $meta = RequestMeta::fromParams($params, $headers);
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

        return $this->dispatch($method, $decoded, $meta, $id, self::acceptsEventStream($headers));
    }

    /**
     * Answers a notification.
     *
     * This revision's core defines no client-to-server notification over HTTP —
     * `notifications/cancelled` is stdio-only, since closing the response
     * stream is the cancellation signal here — so anything arriving is either
     * an extension's or a client still speaking an older revision. Accepting
     * the former and refusing the latter both come out as a status with no
     * body; what must not happen is a JSON-RPC response.
     */
    private function acknowledge(string $method): StatelessResult
    {
        if (\in_array($method, self::REMOVED_METHODS, true)) {
            $this->logger->debug('Refused a notification this revision removed.', ['method' => $method]);

            return StatelessResult::empty(400);
        }

        $this->logger->debug('Accepted a notification with no handler to run.', ['method' => $method]);

        return StatelessResult::empty(202);
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

        // REQUIRED on every POST. The 2025-03-26 fallback for a header-less
        // request exists only for servers choosing to serve pre-2025-06-18
        // clients, which a modern-only endpoint is not.
        if (null === $headerVersion && $this->requiresTransportHeaders()) {
            return StatelessResult::error(
                Error::forHeaderMismatch(
                    \sprintf('Missing required MCP-Protocol-Version header (_meta declares "%s").', $meta->protocolVersion),
                    $id,
                ),
                400,
            );
        }

        // The same check the HTTP entry runs before routing, so the edge and
        // this dispatcher cannot disagree about what a request claims.
        if (null !== $mismatch = InboundClassifier::crossCheckVersion($headerVersion, $meta->protocolVersion)) {
            return StatelessResult::error(Error::forHeaderMismatch($mismatch, $id), 400);
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
        $bus = $this->notificationBus;
        $codec = $this->codec;

        return StatelessResult::stream(static function () use ($agreed, $id, $lifetime, $bus, $codec): \Generator {
            // MUST be the first message carrying this subscription's id, and
            // MUST precede any notification on it.
            yield [
                'jsonrpc' => '2.0',
                'method' => self::ACKNOWLEDGED_NOTIFICATION,
                'params' => [
                    '_meta' => [RequestMeta::SUBSCRIPTION_ID => $id],
                    'notifications' => (object) $agreed->toAcknowledgedArray(),
                ],
            ];

            // From now, not from the beginning: a subscriber wants what happens
            // next, not a replay of the server's history.
            $cursor = $bus?->cursor() ?? 0;

            // The tick is not optional: PHP spots a dropped peer by writing,
            // and a sleeping loop would pin an FPM worker for the full lifetime.
            $deadline = 0.0 >= $lifetime ? \INF : microtime(true) + $lifetime;

            while (microtime(true) < $deadline) {
                if (null !== $bus) {
                    [$notifications, $cursor] = $bus->since($cursor);

                    foreach ($notifications as $notification) {
                        if (!$agreed->carries($notification)) {
                            continue;
                        }

                        yield self::tagWithSubscription($notification, $id);
                    }
                }

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
                'result' => $codec->encodeResult(self::LISTEN_METHOD, [
                    'resultType' => 'complete',
                    '_meta' => [RequestMeta::SUBSCRIPTION_ID => $id],
                ], false),
            ];
        });
    }

    /**
     * Every message on a listen stream carries the id of the subscription it
     * belongs to, which is how a client demultiplexes them on stdio — where
     * they all share one channel.
     *
     * @return array<string, mixed>
     */
    private static function tagWithSubscription(Notification $notification, string|int $id): array
    {
        /** @var array<string, mixed> $frame */
        $frame = $notification->jsonSerialize();

        $params = \is_array($frame['params'] ?? null) ? $frame['params'] : [];
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $meta[RequestMeta::SUBSCRIPTION_ID] = $id;
        $params['_meta'] = $meta;
        $frame['params'] = $params;

        return $frame;
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
    private function dispatch(string $method, array $decoded, RequestMeta $meta, string|int|null $id, bool $wantsStream = false): StatelessResult
    {
        try {
            $messages = $this->messageFactory->create(json_encode($decoded, \JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger->warning('Rejected an unparseable modern-era request.', ['method' => $method, 'exception' => $e]);

            return StatelessResult::error($this->unknownMethod($method, $id), 404);
        }

        $request = $messages[0] ?? null;

        // The factory hands back an exception object rather than throwing one,
        // distinguishing a genuinely unknown method (-32601, the client should
        // stop asking) from a known method the message could not otherwise be
        // parsed into (-32600, the request itself is malformed).
        if ($request instanceof InvalidInputMessageException) {
            $unknownMethod = \sprintf('Unknown method "%s".', $method) === $request->getMessage();

            return StatelessResult::error(
                $unknownMethod
                    ? $this->unknownMethod($method, $id)
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

        // What ClientGateway::progress() reads to find the progress token, and
        // the handshake era sets under the same key.
        $session->set(Protocol::SESSION_ACTIVE_REQUEST_META, $request->getMeta());

        foreach ($this->requestHandlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            $run = $this->run($handler, $request, $session, $meta);

            try {
                // Runs the handler up to its first notification, or to the end
                // if it emits none. Deciding here and not earlier is what keeps
                // the status codes honest: a request that turns out to need
                // -32021 has said nothing yet, so it can still be answered
                // with 400 rather than an error frame under a 200.
                $run->rewind();
            } catch (\Throwable $e) {
                return $this->toErrorResult($method, $id, $e);
            }

            if ($run->valid() && $wantsStream) {
                return StatelessResult::stream(fn (): \Generator => $this->streamFrames($run, $meta, $method, $id, null === $input));
            }

            try {
                // Stepped rather than foreach()ed: rewind() already advanced it,
                // and a generator will not be traversed a second time.
                while ($run->valid()) {
                    $this->logger->debug('Dropped a notification: the client did not accept a response stream.', [
                        'method' => $method,
                        'notification' => $run->current()::getMethod(),
                    ]);

                    $run->next();
                }

                $result = $run->getReturn();
            } catch (\Throwable $e) {
                return $this->toErrorResult($method, $id, $e);
            }

            if ($result instanceof Error) {
                return StatelessResult::error($result, 400);
            }

            if (null !== $capabilityError = $this->checkInputRequests($result->result, $meta, $method, $id)) {
                return $capabilityError;
            }

            return $this->encode($method, $id, $result->result, null === $input);
        }

        return StatelessResult::error($this->unknownMethod($method, $id), 404);
    }

    /**
     * A method with no handler, said as precisely as the server can.
     *
     * An extension's method is still `-32601` when the extension is off — the
     * server genuinely does not implement it — but naming the extension turns
     * an opaque refusal into something the caller can act on.
     */
    private function unknownMethod(string $method, string|int|null $id): Error
    {
        $extension = $this->extensionMethods[$method] ?? null;

        if (null !== $extension) {
            return Error::forMethodNotFound(
                \sprintf('Method "%s" belongs to the "%s" extension, which this server does not serve.', $method, $extension),
                $id,
            );
        }

        return Error::forMethodNotFound(\sprintf('No handler found for method "%s".', $method), $id);
    }

    /**
     * Runs a handler, yielding the notifications it emits as it emits them and
     * returning its result.
     *
     * The fiber is what makes a handler's `$gateway->progress(...)` look
     * synchronous while the caller decides where the notification goes. Server
     * -to-client *requests* are never forwarded: this revision carries what it
     * needs in the result (MRTR), and putting a request on a response stream is
     * something the transport binding forbids outright.
     *
     * An elicitation is answered here rather than refused. Already answered, it
     * resumes the fiber and the handler runs on; not yet, and the ask becomes
     * the result — abandoning the fiber, since this request has nothing left to
     * say and the client will re-send it. Abandoning unwinds it, so a handler's
     * `finally` still runs; what does not run is everything after the ask.
     *
     * That is what lets one handler serve both eras through
     * {@see \Mcp\Server\ClientGateway::elicit()}; see {@see ElicitationReplay}
     * for what it costs.
     *
     * @param RequestHandlerInterface<ResultInterface> $handler
     *
     * @return \Generator<int, Notification, null, Response<ResultInterface>|Error>
     */
    private function run(RequestHandlerInterface $handler, Request $request, Session $session, RequestMeta $meta): \Generator
    {
        $fiber = new \Fiber(static fn (): mixed => $handler->handle($request, $session));
        $input = $session->get(InputContext::class);
        $replay = new ElicitationReplay($input instanceof InputContext ? $input : null, $this->requestStateCodec);

        $suspended = $fiber->start();

        while (!$fiber->isTerminated()) {
            if (null !== $elicitation = self::readElicitation($suspended)) {
                [$named, $elicit] = $elicitation;
                $key = $replay->key($named);

                if (null === $answer = $replay->answer($key, $elicit->mode)) {
                    try {
                        return new Response($request->getId(), $replay->ask($key, $elicit));
                    } catch (LogicException $e) {
                        $this->logger->error('A handler asked for input across rounds on a server with no requestState signing key.', ['exception' => $e]);

                        return Error::forInternalError('The server could not carry its own state across a round of input.', $request->getId());
                    }
                }

                $suspended = $fiber->resume(new Response($request->getId(), $answer));

                continue;
            }

            $notification = $this->readNotification($suspended, $meta);

            if (null !== $notification) {
                yield $notification;
            }

            $suspended = $fiber->resume(null);
        }

        /** @var Response<ResultInterface>|Error $return */
        $return = $fiber->getReturn();

        return $return;
    }

    /**
     * Reads one fiber suspension, or null when it carries nothing to send.
     *
     * @param mixed $suspended the payload {@see \Mcp\Server\ClientGateway} suspended with
     */
    private function readNotification(mixed $suspended, RequestMeta $meta): ?Notification
    {
        if (!\is_array($suspended) || 'notification' !== ($suspended['type'] ?? null)) {
            if (\is_array($suspended) && 'request' === ($suspended['type'] ?? null)) {
                // Elicitation never reaches here — it is answered in run(). What
                // is left are the kinds this revision removed outright, and no
                // multi round-trip shape brings them back.
                throw new LogicException('This protocol revision has no server-initiated requests: sampling and roots were removed with it, so take what you need through tool arguments, resource URIs or server configuration instead. Elicitation is the one ask that survived, as a multi round-trip request.');
            }

            return null;
        }

        $notification = $suspended['notification'] ?? null;

        if (!$notification instanceof Notification) {
            return null;
        }

        // The client opts into logs per request; with no level named the server
        // MUST NOT send any, which is why an absent level drops rather than
        // defaults.
        if ($notification instanceof LoggingMessageNotification) {
            if (null === $meta->logLevel || !$notification->level->isAtLeast($meta->logLevel)) {
                return null;
            }
        }

        return $notification;
    }

    /**
     * One fiber suspension read as an elicitation, or null when it is not one.
     *
     * @param mixed $suspended the payload {@see \Mcp\Server\ClientGateway} suspended with
     *
     * @return array{0: string|null, 1: ElicitRequest}|null the name the handler gave the ask, and the ask
     */
    private static function readElicitation(mixed $suspended): ?array
    {
        if (!\is_array($suspended) || 'request' !== ($suspended['type'] ?? null)) {
            return null;
        }

        $request = $suspended['request'] ?? null;

        if (!$request instanceof ElicitRequest) {
            return null;
        }

        $key = $suspended['input_key'] ?? null;

        return [\is_string($key) ? $key : null, $request];
    }

    /**
     * The frames of a request-scoped response stream: the notifications the
     * handler emits, then the response that ends it.
     *
     * @param \Generator<int, Notification, null, Response<ResultInterface>|Error> $run
     *
     * @return \Generator<mixed>
     */
    private function streamFrames(\Generator $run, RequestMeta $meta, string $method, string|int $id, bool $cacheable): \Generator
    {
        try {
            while ($run->valid()) {
                yield self::withTraceContext($run->current()->jsonSerialize(), $meta->traceContext);

                $run->next();
            }

            $result = $run->getReturn();
        } catch (\Throwable $e) {
            // Headers left long ago, so the status is already 200 and the only
            // way left to report this is a frame.
            yield $this->toErrorResult($method, $id, $e)->message?->jsonSerialize();

            return;
        }

        if (!$result instanceof Error && null !== $capabilityError = $this->checkInputRequests($result->result, $meta, $method, $id)) {
            yield $capabilityError->message?->jsonSerialize();

            return;
        }

        yield $result instanceof Error
            ? $result->jsonSerialize()
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->codec->encodeResult($method, (array) $result->result->jsonSerialize(), $cacheable)];
    }

    /**
     * Refuses to send an ask the client cannot answer.
     *
     * The handler's mistake rather than the client's, but the client is the one
     * that has to hear about it, and `-32021` is precisely the code for
     * "processing this needs a capability you did not declare" — so it is
     * reported as that, and logged as the server-side bug it is.
     */
    private function checkInputRequests(ResultInterface $result, RequestMeta $meta, string $method, string|int $id): ?StatelessResult
    {
        if (!$result instanceof InputRequiredResult) {
            return null;
        }

        $missing = InputRequestCapabilities::missing($result, $meta->clientCapabilities);

        if (null === $missing) {
            return null;
        }

        $this->logger->warning('A handler asked for input the client did not declare it could provide; the ask was replaced with -32021.', [
            'method' => $method,
            'required' => $missing->jsonSerialize(),
        ]);

        return StatelessResult::error(
            Error::forMissingRequiredClientCapability(
                'The server needs input this client did not declare it can provide.',
                $missing,
                $id,
            ),
            400,
        );
    }

    /**
     * Puts the request's trace context back onto a notification it caused, so a
     * collector can join the two without the handler carrying it by hand.
     *
     * @param array<string, mixed>  $frame
     * @param array<string, string> $traceContext
     *
     * @return array<string, mixed>
     */
    private static function withTraceContext(array $frame, array $traceContext): array
    {
        if ([] === $traceContext) {
            return $frame;
        }

        $params = \is_array($frame['params'] ?? null) ? $frame['params'] : [];
        $frameMeta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        // Anything the notification set itself wins: it knows its own span.
        $params['_meta'] = [...$traceContext, ...$frameMeta];
        $frame['params'] = $params;

        return $frame;
    }

    /**
     * The one place a handler's exception becomes an answer, so the streaming
     * and non-streaming paths cannot disagree about which code it earns.
     */
    private function toErrorResult(string $method, string|int $id, \Throwable $e): StatelessResult
    {
        if ($e instanceof MissingRequiredClientCapabilityException) {
            return StatelessResult::error(
                Error::forMissingRequiredClientCapability($e->getMessage(), $e->requiredCapabilities, $id),
                400,
            );
        }

        if ($e instanceof \InvalidArgumentException) {
            return StatelessResult::error(Error::forInvalidParams($e->getMessage(), $id), 400);
        }

        if ($e instanceof LogicException) {
            // Guidance for the tool author, not a detail leaked from their
            // code or a dependency's — safe to echo back verbatim.
            return StatelessResult::error(Error::forInternalError($e->getMessage(), $id), 500);
        }

        $this->logger->error('Uncaught exception handling a modern-era request.', ['method' => $method, 'exception' => $e]);

        return StatelessResult::error(Error::forInternalError(self::INTERNAL_ERROR_MESSAGE, $id), 500);
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
    private function encode(string $method, string|int $id, ResultInterface $result, bool $cacheable = true): StatelessResult
    {
        return StatelessResult::ok($id, $this->codec->encodeResult($method, (array) $result->jsonSerialize(), $cacheable));
    }

    /**
     * Whether the client will read a response stream.
     *
     * Clients MUST offer both content types, so this is normally true; a client
     * that does not gets its notifications dropped rather than a stream it
     * cannot parse.
     *
     * @param array<string, string> $headers
     */
    private static function acceptsEventStream(array $headers): bool
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp($key, 'Accept')) {
                return str_contains(strtolower($value), 'text/event-stream');
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        return InboundClassifier::header($headers, $name);
    }
}
