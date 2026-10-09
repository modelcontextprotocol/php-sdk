<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Notification\ProgressNotificationHandler;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\State\ClientState;
use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\InputRequestResolver;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Client\Transport\HeaderAwareTransportInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\DiscoverRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Stateless\RequestMeta;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Client protocol handler for MCP communication.
 *
 * Handles message routing, request/response correlation, and the initialization handshake.
 * All blocking operations are delegated to the transport.
 *
 * @phpstan-import-type FiberSuspend from TransportInterface
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Protocol
{
    /**
     * How many times a request may be re-sent before the client gives up.
     *
     * Both loops that re-send are bounded by it: a server that keeps asking for
     * input, and one that keeps rejecting the offered revision. Neither is
     * expected to run more than a round or two, so the cap is only there to
     * stop a broken or hostile server from spinning the client forever.
     */
    private const MAX_ROUND_TRIPS = 10;

    private ?TransportInterface $transport = null;
    private ClientStateInterface $state;
    private MessageFactory $messageFactory;
    private LoggerInterface $logger;

    /** @var NotificationHandlerInterface[] */
    private array $notificationHandlers;

    /** Set only when the configured revision has no handshake. */
    private ?RequestEnvelope $envelope = null;

    private ?HeaderFactory $headers = null;

    private ToolCatalog $tools;

    /** What a modern-era connection stamps on every request, see {@see self::setLogLevel()}. */
    private ?LoggingLevel $logLevel = null;

    private readonly InputRequestResolver $inputRequests;

    /**
     * Progress tokens are only required to be unique within a connection, and
     * a retry keeps the caller's one — the work being reported on is the same.
     */
    private int $progressTokens = 0;

    /**
     * @param RequestHandlerInterface<mixed>[] $requestHandlers
     * @param NotificationHandlerInterface[]   $notificationHandlers
     */
    public function __construct(
        private readonly array $requestHandlers = [],
        array $notificationHandlers = [],
        ?MessageFactory $messageFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->state = new ClientState();
        $this->messageFactory = $messageFactory ?? MessageFactory::make();
        $this->logger = $logger ?? new NullLogger();

        $this->notificationHandlers = [
            new ProgressNotificationHandler($this->state),
            ...$notificationHandlers,
        ];

        $this->tools = new ToolCatalog($this->logger);
        $this->inputRequests = new InputRequestResolver($this->requestHandlers, $this->logger);
    }

    /**
     * What the client knows about the server's tools, from `tools/list`.
     *
     * Kept on the protocol rather than the facade because it is what makes the
     * SEP-2243 headers derivable at send time.
     */
    public function getToolCatalog(): ToolCatalog
    {
        return $this->tools;
    }

    /**
     * Connect this protocol to a transport.
     *
     * Sets up message handling callbacks.
     *
     * @param TransportInterface $transport The transport to connect
     * @param Configuration      $config    The client configuration for initialization
     */
    public function connect(TransportInterface $transport, Configuration $config): void
    {
        $this->transport = $transport;

        // A fresh catalog per connection: it is what a server told this client
        // about its tools, and a server reached by reconnecting — the same one
        // or another — has said nothing yet.
        $this->tools = new ToolCatalog($this->logger);

        // Like `logging/setLevel` on the handshake era, a level asked for on
        // one connection is not carried over to the next.
        $this->logLevel = null;

        $transport->setState($this->state);
        $transport->onInitialize(fn () => $this->initialize($config));
        $transport->onMessage($this->processMessage(...));
        $transport->onError(fn (\Throwable $e) => $this->logger->error('Transport error', ['exception' => $e]));

        if ($transport instanceof HeaderAwareTransportInterface) {
            $transport->onHeaders($this->headersFor(...));
        }

        $this->logger->info('Protocol connected to transport', ['transport' => $transport::class]);
    }

    /**
     * The headers belonging to an encoded message, for a transport that has any.
     *
     * @return array<string, string>
     */
    private function headersFor(string $payload): array
    {
        if (null === $this->headers || null === $this->envelope) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return \is_array($decoded)
            ? $this->headers->forMessage($decoded, $this->envelope->protocolVersion())
            : [];
    }

    /**
     * Ready the connection for use, settling which protocol era it speaks.
     *
     * A handshake revision opens with `initialize`, as every revision up to
     * 2025-11-25 does. A modern one has no handshake: the client probes with
     * `server/discover` instead, and falls back to the handshake when the
     * answer shows the server does not speak the modern era — see
     * {@see self::negotiate()}.
     *
     * @param Configuration $config The client configuration
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function initialize(Configuration $config): Response|Error
    {
        // Settled anew on every attempt: a reconnect may reach another server.
        $this->envelope = null;
        $this->headers = null;

        if (!$config->protocolVersion->isModern()) {
            return $this->handshake($config->protocolVersion, $config);
        }

        return $this->negotiate($config);
    }

    /**
     * Probe for the modern era, falling back to the handshake when the server
     * does not speak it.
     *
     * Only positive evidence keeps the connection modern: a `DiscoverResult`
     * naming a modern revision this client speaks, or a refusal naming one
     * (which {@see self::request()} has already retried with). Any other error,
     * silence until the timeout, or a server advertising nothing but handshake
     * revisions identifies a server from before the modern era. The fallback is
     * deliberately not keyed to any one error code: such servers answer an
     * unknown request before `initialize` however they like, or not at all.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio#backward-compatibility
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#backward-compatibility
     *
     * @return Response<array<string, mixed>>|Error
     */
    private function negotiate(Configuration $config): Response|Error
    {
        $version = $config->protocolVersion;

        // Twice at most: a probe that timed out here may still have reached a
        // slow-starting server and settled it on the modern era, which the
        // fallback handshake then hears about as a refusal naming that era.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->enterModernEra($version, $config);

            $probe = $this->request(new DiscoverRequest(), $config->initTimeout);
            $adopted = $this->adopt($probe);

            if ($adopted instanceof Response || $adopted instanceof Error) {
                return $adopted;
            }

            if (null === $config->fallbackProtocolVersion) {
                return Error::forInvalidRequest(\sprintf(
                    'Server does not speak protocol version %s and this client is configured without a handshake fallback: %s',
                    $config->protocolVersion->value,
                    self::describe($probe),
                ));
            }

            $this->logger->info('Server does not speak the modern era; falling back to the "initialize" handshake.', [
                'probe' => self::describe($probe),
                'offering' => $config->fallbackProtocolVersion->value,
            ]);

            $this->envelope = null;
            $this->headers = null;

            $handshake = $this->handshake($config->fallbackProtocolVersion, $config);

            if ($handshake instanceof Error && 0 === $attempt && null !== $modern = self::mutualModern($handshake)) {
                $this->logger->info('Server settled on the modern era after all; probing again.', ['version' => $modern->value]);
                $version = $modern;

                continue;
            }

            return $handshake;
        }

        // Unreachable: the second attempt always returns. Kept so the method
        // cannot fall off its end should the loop change.
        return Error::forInternalError('Protocol negotiation did not settle on a revision.');
    }

    private function enterModernEra(ProtocolVersion $version, Configuration $config): void
    {
        $this->envelope = new RequestEnvelope($version, $config->capabilities, $config->clientInfo, $this->logLevel);
        $this->headers = new HeaderFactory($this->tools);
    }

    /**
     * Whether the connection settled on the modern era, where every request
     * carries its own metadata.
     */
    public function isModern(): bool
    {
        return null !== $this->envelope;
    }

    /**
     * Ask for the server's log messages from $level up on every request that
     * follows — the modern era's stand-in for `logging/setLevel`.
     */
    public function setLogLevel(LoggingLevel $level): void
    {
        $this->logLevel = $level;
        $this->envelope = $this->envelope?->withLogLevel($level);
    }

    /**
     * Reads a probe's answer: the connection, now modern; an error ending the
     * attempt; or null when the server is not a modern one.
     *
     * @param Response<array<string, mixed>>|Error $probe
     *
     * @return Response<array<string, mixed>>|Error|null
     */
    private function adopt(Response|Error $probe): Response|Error|null
    {
        \assert(null !== $this->envelope);

        if ($probe instanceof Error) {
            // An outage is not an answer about the era: nothing to fall back to.
            if (\is_array($probe->data) && true === ($probe->data[TransportInterface::CONNECTION_LOST] ?? null)) {
                return $probe;
            }

            if (Error::UNSUPPORTED_PROTOCOL_VERSION !== $probe->code) {
                return null;
            }

            // A refusal naming a modern revision was already retried with it,
            // so reaching here means it named none this client speaks. A server
            // naming handshake revisions is still reachable through them; one
            // naming neither is a modern server this client cannot talk to.
            $supported = self::supportedVersions($probe);

            foreach ($supported as $version) {
                if (!$version->isModern()) {
                    return null;
                }
            }

            $named = self::namedVersions($probe);

            return Error::forInvalidRequest(\sprintf('Server supports none of the protocol versions this client speaks (it advertises %s).', [] === $named ? 'none' : implode(', ', $named)), $probe->id);
        }

        $advertised = $probe->result['supportedVersions'] ?? null;

        // Not a DiscoverResult, which has to name its revisions, so not evidence
        // of the modern era either.
        if (!\is_array($advertised)) {
            return null;
        }

        $current = $this->envelope->protocolVersion();
        $chosen = null;

        foreach (ProtocolVersion::modernVersions() as $version) {
            if (\in_array($version->value, $advertised, true) && (null === $chosen || $version->isAtLeast($chosen))) {
                $chosen = $version;
            }
        }

        if (\in_array($current->value, $advertised, true)) {
            $chosen = $current;
        }

        if (null === $chosen) {
            // It speaks discover but advertises only handshake revisions: a
            // statement of where it can be reached, not an incompatibility.
            return null;
        }

        if ($chosen !== $current) {
            $this->logger->warning('Server does not speak the configured revision; continuing on one it advertises.', [
                'configured' => $current->value,
                'using' => $chosen->value,
            ]);

            $this->envelope = $this->envelope->withProtocolVersion($chosen);
        }

        $this->readDiscovery($probe->result);

        return $this->settleModern($probe);
    }

    /**
     * @param Response<array<string, mixed>> $probe
     *
     * @return Response<array<string, mixed>>
     */
    private function settleModern(Response $probe): Response
    {
        \assert(null !== $this->envelope);

        $this->state->setProtocolVersion($this->envelope->protocolVersion());
        $this->state->setInitialized(true);

        $this->logger->info('Connection settled on the modern era', [
            'protocolVersion' => $this->envelope->protocolVersion()->value,
        ]);

        return $probe;
    }

    /**
     * The `initialize` handshake: offer a revision, take the server's answer,
     * confirm with `notifications/initialized`.
     *
     * @return Response<array<string, mixed>>|Error
     */
    private function handshake(ProtocolVersion $offered, Configuration $config): Response|Error
    {
        $request = new InitializeRequest(
            $offered->value,
            $config->capabilities,
            $config->clientInfo,
        );

        $response = $this->request($request, $config->initTimeout);

        if ($response instanceof Error && Error::UNSUPPORTED_PROTOCOL_VERSION === $response->code) {
            $named = self::namedVersions($response);

            return new Error($response->id, $response->code, \sprintf(
                'Server does not speak protocol version %s; it supports %s.',
                $offered->value,
                [] === $named ? 'none it named' : implode(', ', $named),
            ), $response->data);
        }

        if ($response instanceof Response) {
            $initResult = InitializeResult::fromArray($response->result);

            // A counter-offer this SDK cannot speak leaves nothing to fall back to,
            // so the handshake fails rather than continuing on a revision neither
            // side agrees on.
            $negotiated = $initResult->protocolVersion;
            if (null === $negotiated || $negotiated->isModern()) {
                // fromArray() above already rejected a missing or non-string revision.
                $counterOffer = (string) $response->result['protocolVersion'];

                return Error::forInvalidParams(\sprintf(
                    'Server responded with unsupported protocol version "%s". Supported versions: %s.',
                    $counterOffer,
                    implode(', ', array_map(
                        static fn (ProtocolVersion $v): string => $v->value,
                        ProtocolVersion::handshakeVersions(),
                    )),
                ), $response->id);
            }

            $this->state->setProtocolVersion($negotiated);
            $this->state->setServerInfo($initResult->serverInfo);
            $this->state->setInstructions($initResult->instructions);
            $this->state->setServerCapabilities($initResult->capabilities);
            $this->state->setInitialized(true);

            $this->sendNotification(new InitializedNotification());

            $this->logger->info('Initialization complete', [
                'server' => $initResult->serverInfo->name,
                'protocolVersion' => $negotiated->value,
            ]);
        }

        return $response;
    }

    /**
     * Read defensively: none of a `DiscoverResult` beyond its revisions is
     * load-bearing for the requests that follow.
     *
     * @param array<string, mixed> $result
     */
    private function readDiscovery(array $result): void
    {
        // Identity is wire vocabulary in this revision, so it rides in `_meta`
        // rather than the result body. The top level is read as a fallback
        // because that is where the handshake era put it.
        $meta = \is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
        $serverInfo = $meta[RequestMeta::SERVER_INFO] ?? $result['serverInfo'] ?? null;

        if (\is_array($serverInfo)) {
            try {
                $this->state->setServerInfo(Implementation::fromArray($serverInfo));
            } catch (\Throwable $e) {
                $this->logger->debug('Ignoring unreadable serverInfo from "server/discover".', ['exception' => $e]);
            }
        }

        if (\is_string($result['instructions'] ?? null)) {
            $this->state->setInstructions($result['instructions']);
        }

        if (\is_array($result['capabilities'] ?? null)) {
            $this->state->setServerCapabilities(ServerCapabilities::fromArray($result['capabilities']));
        }
    }

    /**
     * The newest modern revision a refusal names that this client speaks.
     */
    private static function mutualModern(Error $error): ?ProtocolVersion
    {
        if (Error::UNSUPPORTED_PROTOCOL_VERSION !== $error->code) {
            return null;
        }

        $mutual = null;

        foreach (self::supportedVersions($error) as $version) {
            if ($version->isModern() && (null === $mutual || $version->isAtLeast($mutual))) {
                $mutual = $version;
            }
        }

        return $mutual;
    }

    /**
     * The revisions a `-32022` refusal names, as far as this SDK knows them.
     *
     * @return list<ProtocolVersion>
     */
    private static function supportedVersions(Error $error): array
    {
        return array_values(array_filter(array_map(ProtocolVersion::tryFrom(...), self::namedVersions($error))));
    }

    /**
     * The revisions a `-32022` refusal names, known to this SDK or not.
     *
     * @return list<string>
     */
    private static function namedVersions(Error $error): array
    {
        $supported = \is_array($error->data) && \is_array($error->data['supported'] ?? null) ? $error->data['supported'] : [];

        return array_values(array_filter($supported, is_string(...)));
    }

    /**
     * @param Response<array<string, mixed>>|Error $probe
     */
    private static function describe(Response|Error $probe): string
    {
        return $probe instanceof Error
            ? \sprintf('"server/discover" was answered with error %d (%s)', $probe->code, $probe->message)
            : '"server/discover" was answered without a modern revision';
    }

    /**
     * Send a request to the server and wait for response.
     *
     * If a response is immediately available (sync HTTP), returns it.
     * Otherwise, suspends the Fiber and waits for the transport to resume it.
     *
     * In the modern era this is also where the two loops that re-send live:
     * answering a server's request for input (SEP-2322), and retrying under a
     * revision the server accepts (SEP-2575). Both re-send the same call, so
     * they belong together and above the single exchange.
     *
     * @param Request $request      The request to send
     * @param int     $timeout      The timeout in seconds
     * @param bool    $withProgress Whether to attach a progress token to the request
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function request(Request $request, int $timeout, bool $withProgress = false, ?CancellationTokenInterface $cancellation = null, ?float $callTimeout = null): Response|Error
    {
        $deadline = null !== $callTimeout ? microtime(true) + $callTimeout : null;
        $payload = $request->withId(0)->jsonSerialize();
        unset($payload['id']);

        if ($withProgress) {
            $payload = self::withMeta($payload, ['progressToken' => 'prog-'.++$this->progressTokens]);
        }

        if (null === $this->envelope) {
            return $this->exchange($payload, $timeout, $cancellation, $deadline);
        }

        for ($attempt = 0; $attempt < self::MAX_ROUND_TRIPS; ++$attempt) {
            $response = $this->exchange($payload, $timeout, $cancellation, $deadline);

            if ($response instanceof Error) {
                $retry = $this->withAcceptedVersion($response);

                if (null === $retry) {
                    return $response;
                }

                continue;
            }

            $asked = InputRequestResolver::asked($response->result);

            if (null === $asked) {
                return $response;
            }

            // A fresh `inputResponses`/`requestState` pair every round, never
            // merged with the last: the answers belong to the ask that just
            // arrived, and carrying an old one forward is how state leaks
            // between rounds.
            //
            // Cast to object: inputResponses is a JSON object keyed by the
            // server's ids, but a PHP array with no entries or with sequential
            // numeric-string keys encodes as a JSON array instead.
            $payload['params'] = [
                ...($payload['params'] ?? []),
                'inputResponses' => (object) $this->inputRequests->resolve($asked),
            ];

            unset($payload['params']['requestState']);

            // Echoed byte-for-byte, and only when the server sent one: the
            // value is the server's to read, and inventing or reshaping it
            // would break whatever it encodes.
            if (\is_string($response->result['requestState'] ?? null)) {
                $payload['params']['requestState'] = $response->result['requestState'];
            }

            $this->logger->debug('Retrying request with resolved input', [
                'method' => $payload['method'] ?? null,
                'round' => $attempt + 1,
            ]);
        }

        return Error::forInternalError(\sprintf('Server asked for input more than %d times without completing the request.', self::MAX_ROUND_TRIPS));
    }

    /**
     * Switches the offered revision when the server refuses the current one,
     * or null when there is nothing to retry with.
     *
     * @param Error $error the server's refusal
     */
    private function withAcceptedVersion(Error $error): ?ProtocolVersion
    {
        if (Error::UNSUPPORTED_PROTOCOL_VERSION !== $error->code || null === $this->envelope) {
            return null;
        }

        $data = \is_array($error->data) ? $error->data : [];
        $supported = \is_array($data['supported'] ?? null) ? $data['supported'] : [];
        $current = $this->envelope->protocolVersion();

        foreach ($supported as $candidate) {
            $version = \is_string($candidate) ? ProtocolVersion::tryFrom($candidate) : null;

            // Only another modern revision is reachable from here: falling back
            // to a handshake era one would mean opening a connection this
            // transport already decided it was not going to open.
            if (null === $version || !$version->isModern() || $version === $current) {
                continue;
            }

            $this->logger->info('Server rejected the offered protocol revision, retrying with one it supports.', [
                'offered' => $current->value,
                'retrying' => $version->value,
            ]);

            $this->envelope = $this->envelope->withProtocolVersion($version);
            $this->state->setProtocolVersion($version);

            return $version;
        }

        return null;
    }

    /**
     * One request on the wire: assign an id, send, and wait for its answer.
     *
     * A retry gets a new id, because the previous one is spent — the server has
     * already answered it, and reusing it would make the two indistinguishable.
     *
     * @param array<string, mixed> $payload
     *
     * @return Response<array<string, mixed>>|Error
     */
    private function exchange(array $payload, int $timeout, ?CancellationTokenInterface $cancellation = null, ?float $deadline = null): Response|Error
    {
        if (null !== ($interruption = self::interruption($cancellation, $deadline))) {
            throw $interruption;
        }

        $requestId = $this->state->nextRequestId();
        $payload['id'] = $requestId;

        $this->state->addPendingRequest($requestId, $timeout);

        try {
            $this->send($payload, 'request');

            // send() can block and leave a JSON answer already buffered: drop it
            // and report the interruption instead of a success nobody awaits.
            if (null !== ($interruption = self::interruption($cancellation, $deadline))) {
                throw $interruption;
            }

            $immediate = $this->state->consumeResponse($requestId);
            if (null !== $immediate) {
                $this->logger->debug('Received immediate response', ['id' => $requestId]);

                return $immediate;
            }

            $this->logger->debug('Suspending fiber for response', ['id' => $requestId]);

            $response = \Fiber::suspend([
                'type' => 'await_response',
                'request_id' => $requestId,
                'timeout' => $timeout,
                'cancellation' => $cancellation,
                'deadline' => $deadline,
            ]);

            // A transport may resume with a buffered reply before checking interruption.
            if (null !== ($interruption = self::interruption($cancellation, $deadline))) {
                throw $interruption;
            }

            return $response;
        } catch (RequestCancelledException|TimeoutException $e) {
            $this->state->consumeResponse($requestId);
            $this->notifyCancellation($requestId, $e->getMessage());

            throw $e;
        } finally {
            // Only the response path clears it, so a request that timed out or
            // whose send() threw would stay pending and fail every later one.
            $this->state->removePendingRequest($requestId);
        }
    }

    /**
     * The interruption an in-flight request is subject to, if any. Checked on
     * both sides of a send and after the transport resumes a suspended request.
     *
     * @phpstan-impure
     */
    private static function interruption(?CancellationTokenInterface $cancellation, ?float $deadline): RequestCancelledException|TimeoutException|null
    {
        if ($cancellation?->isCancellationRequested()) {
            return new RequestCancelledException('The client cancelled the request.');
        }

        if (null !== $deadline && microtime(true) >= $deadline) {
            return new TimeoutException('The request deadline expired.');
        }

        return null;
    }

    /**
     * Tell the server an abandoned request's result will go unused. Only stdio and
     * handshake-era HTTP need it: a modern connection signals by closing the
     * response stream. Best effort — a send failure is logged, not raised.
     */
    private function notifyCancellation(int $requestId, string $reason): void
    {
        if ($this->transport instanceof HttpTransport && true === $this->state->getProtocolVersion()?->isModern()) {
            return;
        }

        try {
            $this->sendNotification(new CancelledNotification($requestId, $reason));
        } catch (\Throwable $notificationError) {
            $this->logger->warning('Could not send request cancellation notification.', ['request_id' => $requestId, 'exception' => $notificationError]);
        }
    }

    /**
     * Send a notification to the server (fire and forget).
     */
    public function sendNotification(Notification $notification): void
    {
        $this->send($notification->jsonSerialize(), 'notification');
    }

    /**
     * Encode and hand a message to the transport, stamping the per-request
     * envelope on the way out when the revision calls for one.
     *
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, string $kind): void
    {
        if (null !== $this->envelope) {
            $payload = $this->envelope->stamp($payload);
        }

        $this->logger->debug('Sending '.$kind, [
            'id' => $payload['id'] ?? null,
            'method' => $payload['method'] ?? null,
        ]);

        $this->transport?->send(json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function withMeta(array $payload, array $meta): array
    {
        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $existing = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $params['_meta'] = [...$existing, ...$meta];
        $payload['params'] = $params;

        return $payload;
    }

    /**
     * Send a response back to the server (for server-initiated requests).
     *
     * @param Response<mixed>|Error $response
     */
    private function sendResponse(Response|Error $response): void
    {
        $this->logger->debug('Sending response', ['id' => $response->getId()]);

        $encoded = json_encode($response, \JSON_THROW_ON_ERROR);
        $this->transport?->send($encoded);
    }

    /**
     * Process an incoming message from the server.
     *
     * Routes to appropriate handler based on message type.
     */
    public function processMessage(string $input): void
    {
        $this->logger->debug('Received message', ['input' => $input]);

        try {
            $messages = $this->messageFactory->create($input);
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse message', ['exception' => $e]);

            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof Response || $message instanceof Error) {
                $this->handleResponse($message);
            } elseif ($message instanceof Request) {
                $this->handleRequest($message);
            } elseif ($message instanceof Notification) {
                $this->handleNotification($message);
            }
        }
    }

    /**
     * Handle a response from the server.
     *
     * This stores it in session. The transport will pick it up and resume the Fiber.
     *
     * @param Response<mixed>|Error $response
     */
    private function handleResponse(Response|Error $response): void
    {
        $requestId = $response->getId();

        if (null === $requestId) {
            $this->logger->warning('Received an id-less error response; cannot correlate it to a request.', ['response' => $response->jsonSerialize()]);

            return;
        }

        if (!\array_key_exists($requestId, $this->state->getPendingRequests())) {
            $this->logger->debug('Ignoring response for a request that is no longer pending.', ['id' => $requestId]);

            return;
        }

        $this->logger->debug('Handling response', ['id' => $requestId]);

        $this->state->storeResponse($requestId, $response->jsonSerialize());
    }

    /**
     * Handle a request from the server (e.g., sampling request).
     */
    private function handleRequest(Request $request): void
    {
        $method = $request::getMethod();

        $this->logger->debug('Received server request', [
            'method' => $method,
            'id' => $request->getId(),
        ]);

        foreach ($this->requestHandlers as $handler) {
            if ($handler->supports($request)) {
                try {
                    $response = $handler->handle($request);
                } catch (\Throwable $e) {
                    $this->logger->error('Unexpected error while handling request', [
                        'method' => $method,
                        'exception' => $e,
                    ]);

                    $response = Error::forInternalError(
                        \sprintf('Unexpected error while handling "%s" request', $method),
                        $request->getId()
                    );
                }

                $this->sendResponse($response);

                return;
            }
        }

        $error = Error::forMethodNotFound(
            \sprintf('Client does not handle "%s" requests.', $method),
            $request->getId()
        );

        $this->sendResponse($error);
    }

    /**
     * Handle a notification from the server.
     */
    private function handleNotification(Notification $notification): void
    {
        $method = $notification::getMethod();

        $this->logger->debug('Received server notification', [
            'method' => $method,
        ]);

        foreach ($this->notificationHandlers as $handler) {
            if ($handler->supports($notification)) {
                try {
                    $handler->handle($notification);
                } catch (\Throwable $e) {
                    $this->logger->warning('Notification handler failed', ['exception' => $e]);
                }

                return;
            }
        }
    }

    public function getState(): ClientStateInterface
    {
        return $this->state;
    }
}
