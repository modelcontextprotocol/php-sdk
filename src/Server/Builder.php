<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscovererInterface;
use Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\Loader\ChainLoader;
use Mcp\Capability\Registry\Loader\DiscoveryLoader;
use Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\Loader\ReflectedElementLoader;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\LogicException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\AbstractExtension;
use Mcp\Schema\Extension\ExtensionInterface;
use Mcp\Schema\Icon;
use Mcp\Schema\Implementation;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\ElementHandlerInterface;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Stateless\RequestStateCodec;
use Mcp\Server\Stateless\StandardHeaderValidator;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use Mcp\Server\Wire\CachePolicy;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Finder\Finder;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @phpstan-type AssembledParts array{
 *     logger: LoggerInterface,
 *     eventDispatcher: ?EventDispatcherInterface,
 *     configuration: Configuration,
 *     messageFactory: MessageFactory,
 *     sessionManager: SessionManagerInterface,
 *     registry: RegistryInterface,
 *     requestHandlers: list<RequestHandlerInterface<mixed>>,
 *     notificationHandlers: list<NotificationHandlerInterface>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Builder
{
    private ?Implementation $serverInfo = null;

    private RegistryInterface $registry;

    private ?SubscriptionManagerInterface $subscriptionManager = null;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $discoveryCache = null;

    private ?EventDispatcherInterface $eventDispatcher = null;

    private ?ContainerInterface $container = null;

    private ?SchemaGeneratorInterface $schemaGenerator = null;

    private ?ReferenceHandlerInterface $referenceHandler = null;

    private ?DiscovererInterface $discoverer = null;

    private ?SessionManagerInterface $sessionManager = null;

    private ?SessionStoreInterface $sessionStore = null;

    private int $gcProbability = 1;

    private int $gcDivisor = 100;

    private int $paginationLimit = 50;

    private ?string $instructions = null;

    private ?ProtocolVersion $protocolVersion = null;

    private ?CachePolicy $cachePolicy = null;

    private ?NotificationBusInterface $notificationBus = null;

    private float $subscriptionLifetime = 30.0;

    /** @var array<string, string> RPC method to the extension identifier defining it */
    private array $extensionMethods = [];

    /** @var list<class-string<\Mcp\Schema\JsonRpc\Request>|class-string<\Mcp\Schema\JsonRpc\Notification>> */
    private array $extensionMessages = [];

    private ?string $requestStateKey = null;

    private int $requestStateTtl = 600;

    /**
     * @var array<int, RequestHandlerInterface<mixed>>
     */
    private array $requestHandlers = [];

    /**
     * @var array<int, NotificationHandlerInterface>
     */
    private array $notificationHandlers = [];

    /**
     * @var array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     annotations: ?ToolAnnotations,
     *     inputSchema: ?array<string, mixed>,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>,
     *     outputSchema: ?array<string, mixed>,
     * }[]
     */
    private array $tools = [];

    /**
     * @var array{
     *     handler: Handler,
     *     uri: string,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     size: int|null,
     *     annotations: ?Annotations,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $resources = [];

    /**
     * @var array{
     *     handler: Handler,
     *     uriTemplate: string,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     annotations: ?Annotations,
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $resourceTemplates = [];

    /**
     * @var array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $prompts = [];

    /**
     * @var list<array{definition: Tool, handler: ToolHandlerInterface}>
     */
    private array $explicitTools = [];

    /**
     * @var list<array{definition: ResourceDefinition, handler: ResourceHandlerInterface}>
     */
    private array $explicitResources = [];

    /**
     * @var list<array{definition: ResourceTemplate, handler: ResourceTemplateHandlerInterface, completionProviders: array<string, ProviderInterface>}>
     */
    private array $explicitResourceTemplates = [];

    /**
     * @var list<array{definition: Prompt, handler: PromptHandlerInterface, completionProviders: array<string, ProviderInterface>}>
     */
    private array $explicitPrompts = [];

    private ?string $discoveryBasePath = null;

    /**
     * @var string[]
     */
    private array $discoveryScanDirs = [];

    /**
     * @var array|string[]
     */
    private array $discoveryExcludeDirs = [];

    /**
     * @var string[]|null
     */
    private ?array $discoveryNamePatterns = null;

    private ?ServerCapabilities $serverCapabilities = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $extensions = [];

    /**
     * @var LoaderInterface[]
     */
    private array $loaders = [];

    private bool $hasCustomRegistry = false;

    private bool $lazyLoading = true;

    private bool $headerValidation = true;

    /** @var list<ProtocolVersion>|null null defaults to every modern revision, [] serves none */
    private ?array $modernVersions = null;

    private bool $inputRequiredShim = true;

    private int $inputRequiredRounds = InputRequiredShim::DEFAULT_MAX_ROUNDS;

    private int $inputRequiredTimeout = InputRequiredShim::DEFAULT_ROUND_TIMEOUT;

    /**
     * @see self::assemble() for why this is memoized
     *
     * @var AssembledParts|null
     */
    private ?array $parts = null;

    /**
     * Sets the server's identity. Required.
     *
     * @param ?Icon[] $icons
     * @param ?string $title Display name for UI and end-user contexts. Falls back to $name when absent.
     */
    public function setServerInfo(
        string $name,
        string $version,
        ?string $description = null,
        ?array $icons = null,
        ?string $websiteUrl = null,
        ?string $title = null,
    ): self {
        $this->serverInfo = new Implementation(trim($name), trim($version), $description, $icons, $websiteUrl, $title);

        return $this;
    }

    /**
     * Sets the bus carrying server-initiated notifications to open
     * `subscriptions/listen` streams (SEP-2575).
     *
     * Without one, a listen stream acknowledges and then carries nothing: there
     * is no safe default, because the right implementation depends on whether
     * the publisher and the stream share a process.
     * {@see InMemoryNotificationBus} is correct for stdio and persistent
     * runtimes; under PHP-FPM, where they are different workers, use
     * {@see Psr16NotificationBus} or an implementation over your own broker.
     *
     * Registry changes are published automatically when an event dispatcher is
     * configured; anything else — `notifications/resources/updated` above all —
     * is published by the application calling
     * {@see NotificationBusInterface::publish()}.
     */
    public function setNotificationBus(NotificationBusInterface $bus): self
    {
        $this->notificationBus = $bus;

        return $this;
    }

    /**
     * Sets how long a `subscriptions/listen` stream is held open before the
     * server closes it gracefully.
     *
     * The real ceiling is the runtime's: under PHP-FPM a stream cannot outlive
     * `max_execution_time`, and a value above it buys a killed worker instead
     * of a longer subscription. Pass `0` for "until the client or the runtime
     * ends it", which is what a persistent runtime wants.
     */
    public function setSubscriptionLifetime(float $seconds): self
    {
        $this->subscriptionLifetime = max(0.0, $seconds);

        return $this;
    }

    /**
     * Sets how long, and to whom, this server's answers may be cached (SEP-2549).
     *
     * The modern lifecycle must put `ttlMs` and `cacheScope` on every cacheable
     * result; without a policy it says "private, immediately stale", which is
     * conformant and forfeits the point. Build one with
     * {@see CachePolicy::default()} and narrow it per method:
     *
     * ```php
     * $builder->setCachePolicy(
     *     CachePolicy::default(60_000)
     *         ->withMethod('tools/list', 3_600_000, CacheScope::Public),
     * );
     * ```
     */
    public function setCachePolicy(CachePolicy $policy): self
    {
        $this->cachePolicy = $policy;

        return $this;
    }

    /**
     * Sets the key signing the `requestState` carried across the rounds of a
     * multi round-trip request (SEP-2322). Without one, every echoed state is
     * refused.
     *
     * The same key must reach every instance that might serve the retry, so a
     * per-process random value only works for a single-process deployment.
     *
     * @param string $key at least 32 bytes
     * @param int    $ttl how long a minted state stays valid, in seconds
     */
    public function setRequestState(string $key, int $ttl = 600): self
    {
        $this->requestStateKey = $key;
        $this->requestStateTtl = $ttl;

        return $this;
    }

    /**
     * Configures the server's pagination limit.
     */
    public function setPaginationLimit(int $paginationLimit): self
    {
        $this->paginationLimit = $paginationLimit;

        return $this;
    }

    /**
     * Configures the instructions describing how to use the server and its features.
     *
     * This can be used by clients to improve the LLM's understanding of available tools, resources,
     * etc. It can be thought of like a "hint" to the model. For example, this information MAY
     * be added to the system prompt.
     */
    public function setInstructions(?string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Explicitly set server capabilities. If set, this overrides automatic detection.
     */
    public function setCapabilities(ServerCapabilities $serverCapabilities): self
    {
        $this->serverCapabilities = $serverCapabilities;

        return $this;
    }

    /**
     * Enable one or more MCP protocol extensions, announced to clients under
     * `capabilities.extensions` during the initialize handshake.
     *
     * An extension also contributes the message classes its methods decode
     * into and the handlers serving them, if any — see {@see AbstractExtension}
     * for extensions that only announce a capability.
     *
     * @throws InvalidArgumentException if the identifier is not a valid `_meta` prefix
     * @throws LogicException           if the same extension is enabled more than once, or
     *                                  two enabled extensions define the same RPC method
     */
    public function enableExtension(ExtensionInterface ...$extensions): self
    {
        foreach ($extensions as $extension) {
            $id = (string) $extension->getId();

            if (isset($this->extensions[$id])) {
                throw new LogicException(\sprintf('Extension "%s" is already enabled.', $id));
            }

            $this->extensions[$id] = $extension->getCapabilities();

            // Without this the method cannot be decoded at all, so nothing
            // downstream ever sees it.
            foreach ($extension->getMessages() as $message) {
                $method = $message::getMethod();

                // The message factory resolves a method to whichever class was
                // registered first, so a second owner here would silently lose
                // the dispatch race while still being named in error messages.
                if (isset($this->extensionMethods[$method]) && $this->extensionMethods[$method] !== $id) {
                    throw new LogicException(\sprintf('Method "%s" is already claimed by extension "%s", so extension "%s" cannot also define it.', $method, $this->extensionMethods[$method], $id));
                }

                $this->extensionMessages[] = $message;
                // Recorded even though the handler answers it, so a server with
                // the extension *off* can say so instead of "no such method".
                $this->extensionMethods[$method] = $id;
            }

            foreach ($extension->getRequestHandlers() as $handler) {
                $this->requestHandlers[] = $handler;
            }
        }

        return $this;
    }

    /**
     * Register a single custom method handler.
     *
     * @param RequestHandlerInterface<mixed> $handler
     */
    public function addRequestHandler(RequestHandlerInterface $handler): self
    {
        $this->requestHandlers[] = $handler;

        return $this;
    }

    /**
     * Register multiple custom method handlers.
     *
     * @param iterable<RequestHandlerInterface<mixed>> $handlers
     */
    public function addRequestHandlers(iterable $handlers): self
    {
        foreach ($handlers as $handler) {
            $this->requestHandlers[] = $handler;
        }

        return $this;
    }

    /**
     * Register a single custom notification handler.
     */
    public function addNotificationHandler(NotificationHandlerInterface $handler): self
    {
        $this->notificationHandlers[] = $handler;

        return $this;
    }

    /**
     * Register multiple custom notification handlers.
     *
     * @param iterable<int, NotificationHandlerInterface> $handlers
     */
    public function addNotificationHandlers(iterable $handlers): self
    {
        foreach ($handlers as $handler) {
            $this->notificationHandlers[] = $handler;
        }

        return $this;
    }

    public function setRegistry(RegistryInterface $registry): self
    {
        $this->registry = $registry;
        $this->hasCustomRegistry = true;

        return $this;
    }

    /**
     * Controls when configured loaders (manual elements, discovery, custom loaders) run.
     *
     * Lazy (the default) defers loading to the first registry read so a persistent runtime does not
     * freeze the registry to a source not yet ready at build time. Disable to load eagerly at build.
     * A registry supplied via setRegistry() is always loaded eagerly; its own constructor loader,
     * if it has one, still runs on the first read.
     */
    public function setLazyLoading(bool $lazyLoading = true): self
    {
        $this->lazyLoading = $lazyLoading;

        return $this;
    }

    /**
     * Controls whether {@see self::buildStateless()} checks the standard MCP
     * request headers (SEP-2243) against the JSON-RPC body. On by default.
     *
     * Disable for a `StatelessProtocol` served by a transport with no header
     * layer — the validator would otherwise reject every request for
     * carrying none of the standard headers.
     */
    public function setHeaderValidator(bool $headerValidation = true): self
    {
        $this->headerValidation = $headerValidation;

        return $this;
    }

    /**
     * Provides a PSR-3 logger instance. Defaults to NullLogger.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Provides a PSR-11 DI container, primarily for resolving user-defined handler classes.
     * Defaults to a basic internal container.
     */
    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function setSchemaGenerator(SchemaGeneratorInterface $schemaGenerator): self
    {
        $this->schemaGenerator = $schemaGenerator;

        return $this;
    }

    public function setReferenceHandler(ReferenceHandlerInterface $referenceHandler): self
    {
        $this->referenceHandler = $referenceHandler;

        return $this;
    }

    public function setDiscoverer(DiscovererInterface $discoverer): self
    {
        $this->discoverer = $discoverer;

        return $this;
    }

    public function setResourceSubscriptionManager(SubscriptionManagerInterface $subscriptionManager): self
    {
        $this->subscriptionManager = $subscriptionManager;

        return $this;
    }

    /**
     * Configures the session layer.
     *
     * @param int $gcProbability The numerator of the GC probability fraction (like PHP's session.gc_probability). Set to 0 to disable GC.
     * @param int $gcDivisor     The denominator of the GC probability fraction (like PHP's session.gc_divisor). Probability = gcProbability/gcDivisor.
     */
    public function setSession(
        ?SessionStoreInterface $sessionStore = null,
        ?SessionManagerInterface $sessionManager = null,
        int $gcProbability = 1,
        int $gcDivisor = 100,
    ): self {
        $this->sessionStore = $sessionStore;
        $this->sessionManager = $sessionManager;
        $this->gcProbability = $gcProbability;
        $this->gcDivisor = $gcDivisor;

        if (null !== $sessionManager && null !== $sessionStore) {
            throw new InvalidArgumentException('Cannot set both SessionStore and SessionManager. Set only one or the other.');
        }

        return $this;
    }

    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     * @param string[] $namePatterns
     */
    public function setDiscovery(
        string $basePath,
        array $scanDirs = ['.', 'src'],
        array $excludeDirs = [],
        ?CacheInterface $cache = null,
        array $namePatterns = DiscovererInterface::DEFAULT_NAME_PATERNS,
    ): self {
        $this->discoveryBasePath = $basePath;
        $this->discoveryScanDirs = $scanDirs;
        $this->discoveryExcludeDirs = $excludeDirs;
        $this->discoveryCache = $cache;
        $this->discoveryNamePatterns = $namePatterns;

        return $this;
    }

    public function setProtocolVersion(ProtocolVersion $protocolVersion): self
    {
        $this->protocolVersion = $protocolVersion;

        return $this;
    }

    /**
     * Manually registers a tool handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title        Optional human-readable title for display in UI
     * @param array<string, mixed>|null $inputSchema
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $outputSchema
     */
    public function addTool(
        callable|array|string $handler,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?ToolAnnotations $annotations = null,
        ?array $inputSchema = null,
        ?array $icons = null,
        ?array $meta = null,
        ?array $outputSchema = null,
    ): self {
        $this->tools[] = compact(
            'handler',
            'name',
            'title',
            'description',
            'annotations',
            'inputSchema',
            'icons',
            'meta',
            'outputSchema',
        );

        return $this;
    }

    /**
     * Manually registers a resource handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title   Optional human-readable title for display in UI
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     */
    public function addResource(
        \Closure|array|string $handler,
        string $uri,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?int $size = null,
        ?Annotations $annotations = null,
        ?array $icons = null,
        ?array $meta = null,
    ): self {
        $this->resources[] = compact(
            'handler',
            'uri',
            'name',
            'title',
            'description',
            'mimeType',
            'size',
            'annotations',
            'icons',
            'meta',
        );

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title   Optional human-readable title for display in UI
     * @param array<string, mixed>|null $meta
     */
    public function addResourceTemplate(
        \Closure|array|string $handler,
        string $uriTemplate,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?Annotations $annotations = null,
        ?array $meta = null,
    ): self {
        $this->resourceTemplates[] = compact(
            'handler',
            'uriTemplate',
            'name',
            'title',
            'description',
            'mimeType',
            'annotations',
            'meta',
        );

        return $this;
    }

    /**
     * Manually registers a prompt handler.
     *
     * @param Handler                   $handler
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     */
    public function addPrompt(
        \Closure|array|string $handler,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?array $icons = null,
        ?array $meta = null,
    ): self {
        $this->prompts[] = compact('handler', 'name', 'title', 'description', 'icons', 'meta');

        return $this;
    }

    /**
     * Registers an element using an explicit schema value object paired with a handler interface.
     *
     * Use this entry point when an element's name, schema, or description is only known at
     * runtime (e.g. config-driven integrations). For statically-known elements, prefer
     * `addTool/addResource/addResourceTemplate/addPrompt`, which can derive metadata from
     * reflection of the handler.
     *
     * Mismatched pairings (e.g. a `Tool` with a `PromptHandlerInterface`) raise
     * `Mcp\Exception\InvalidArgumentException`. Completion providers are only supported on
     * `Prompt` and `ResourceTemplate` definitions; supplying them with `Tool` or
     * `ResourceDefinition` raises the same exception.
     *
     * @param array<string, ProviderInterface> $completionProviders Keyed by argument/variable name
     */
    public function add(
        Tool|ResourceDefinition|ResourceTemplate|Prompt $definition,
        ElementHandlerInterface $handler,
        array $completionProviders = [],
    ): self {
        if ([] !== $completionProviders && ($definition instanceof Tool || $definition instanceof ResourceDefinition)) {
            throw new InvalidArgumentException(\sprintf('Completion providers are only supported on Prompt and ResourceTemplate definitions, got %s.', $definition::class));
        }

        match (true) {
            $definition instanceof Tool && $handler instanceof ToolHandlerInterface => $this->explicitTools[] = ['definition' => $definition, 'handler' => $handler],
            $definition instanceof ResourceDefinition && $handler instanceof ResourceHandlerInterface => $this->explicitResources[] = ['definition' => $definition, 'handler' => $handler],
            $definition instanceof ResourceTemplate && $handler instanceof ResourceTemplateHandlerInterface => $this->explicitResourceTemplates[] = ['definition' => $definition, 'handler' => $handler, 'completionProviders' => $completionProviders],
            $definition instanceof Prompt && $handler instanceof PromptHandlerInterface => $this->explicitPrompts[] = ['definition' => $definition, 'handler' => $handler, 'completionProviders' => $completionProviders],
            default => throw new InvalidArgumentException(\sprintf('%s definition cannot be paired with %s; expected the matching handler interface.', $definition::class, $handler::class)),
        };

        return $this;
    }

    /**
     * Register a single custom loader.
     */
    public function addLoader(LoaderInterface $loader): self
    {
        $this->loaders[] = $loader;

        return $this;
    }

    /**
     * @param iterable<LoaderInterface> $loaders
     */
    public function addLoaders(iterable $loaders): self
    {
        foreach ($loaders as $loader) {
            $this->loaders[] = $loader;
        }

        return $this;
    }

    /**
     * Stop serving multi round-trip handlers to handshake-era clients.
     *
     * A handler that returns an {@see \Mcp\Schema\Result\InputRequiredResult}
     * is written for the modern era, where the client answers the embedded
     * requests and retries the call. On a handshake-era connection the SDK
     * fulfils it instead, by sending those requests over that connection's own
     * channel and re-entering the handler with the answers — so one handler
     * serves both eras. See {@see InputRequiredShim} for what re-entry costs.
     *
     * Turn it off to have such a handler fail on a handshake-era connection
     * rather than be fulfilled behind your back.
     */
    public function withoutInputRequiredShim(): self
    {
        $this->inputRequiredShim = false;

        return $this;
    }

    /**
     * Bounds on the shim's loop: how many times a handler may be re-entered for
     * one request, and how long one answer is waited for.
     *
     * The wait holds the originating request open, so on a process-per-request
     * runtime it holds a worker too. Size it against your pool, not against a
     * user's patience.
     */
    public function setInputRequiredLimits(int $maxRounds, int $roundTimeout): self
    {
        if ($maxRounds < 1) {
            throw new InvalidArgumentException('maxRounds must be at least 1.');
        }

        if ($roundTimeout < 1) {
            throw new InvalidArgumentException('roundTimeout must be at least 1 second.');
        }

        $this->inputRequiredRounds = $maxRounds;
        $this->inputRequiredTimeout = $roundTimeout;

        return $this;
    }

    private function requestStateCodec(): ?RequestStateCodec
    {
        return null !== $this->requestStateKey
            ? new RequestStateCodec($this->requestStateKey, $this->requestStateTtl)
            : null;
    }

    /**
     * Serve only the handshake era, refusing modern-era traffic.
     *
     * The default is to serve both from whatever the server is run on, because
     * an endpoint that turns a client away for speaking the newer revision is
     * almost never what anyone wants. Call this when it is: a deployment that
     * has to stay on the handshake wire, or one whose tools call back into the
     * client and would fail the modern half anyway.
     */
    public function withoutModernEra(): self
    {
        $this->modernVersions = [];

        return $this;
    }

    /**
     * Revisions the modern-era leg answers for. Defaults to every modern
     * revision this SDK knows.
     *
     * @param list<ProtocolVersion> $versions
     *
     * @throws InvalidArgumentException if a version is not one {@see Wire\InboundClassifier} routes to this leg
     */
    public function setModernVersions(array $versions): self
    {
        foreach ($versions as $version) {
            if (!$version->isModern()) {
                throw new InvalidArgumentException(\sprintf('"%s" is a handshake-era revision; a request claiming it never reaches the modern leg to be served.', $version->value));
            }
        }

        $this->modernVersions = $versions;

        return $this;
    }

    /**
     * Builds the fully configured Server instance.
     *
     * The result carries a dispatcher for each era. Which one answers is a
     * per-request decision the transport makes, so one server object — and one
     * endpoint — serves handshake-era and modern-era clients alike.
     */
    public function build(): Server
    {
        $parts = $this->assemble();

        $protocol = new Protocol(
            requestHandlers: $parts['requestHandlers'],
            notificationHandlers: $parts['notificationHandlers'],
            messageFactory: $parts['messageFactory'],
            sessionManager: $parts['sessionManager'],
            logger: $parts['logger'],
            eventDispatcher: $parts['eventDispatcher'],
            inputRequiredShim: $this->inputRequiredShim
                ? new InputRequiredShim($this->inputRequiredRounds, $this->inputRequiredTimeout, $parts['logger'])
                : null,
            requestStateCodec: $this->requestStateCodec(),
        );

        $modernVersions = $this->modernVersions ?? ProtocolVersion::modernVersions();

        return new Server(
            $protocol,
            $parts['logger'],
            [] === $modernVersions ? null : $this->buildStateless($modernVersions),
        );
    }

    /**
     * Builds a dispatcher for the modern (SEP-2575) lifecycle on its own.
     *
     * Tools, prompts, resources and their handlers are era-independent, so one
     * builder configuration drives either lifecycle. {@see self::build()} wires
     * both together; this is the modern era by itself, for an endpoint that
     * serves nothing else.
     *
     * @param list<ProtocolVersion> $supportedVersions revisions this dispatcher will answer for
     */
    public function buildStateless(array $supportedVersions = [ProtocolVersion::V2026_07_28]): StatelessProtocol
    {
        $parts = $this->assemble();

        return new StatelessProtocol(
            requestHandlers: $parts['requestHandlers'],
            messageFactory: $parts['messageFactory'],
            configuration: $parts['configuration'],
            supportedVersions: $supportedVersions,
            logger: $parts['logger'],
            subscriptionLifetime: $this->subscriptionLifetime,
            headerValidator: $this->headerValidation ? new StandardHeaderValidator($parts['registry']) : null,
            requestStateCodec: $this->requestStateCodec(),
            cachePolicy: $this->cachePolicy,
            notificationBus: $this->notificationBus,
            extensionMethods: $this->extensionMethods,
        );
    }

    /**
     * Resolves the builder's configuration into the parts both lifecycles need.
     *
     * Memoized: the two eras share one registry, one session manager and one
     * set of handler instances, so they answer for the same server rather than
     * for two that merely started from the same configuration.
     *
     * @return AssembledParts
     */
    private function assemble(): array
    {
        return $this->parts ??= $this->resolve();
    }

    /**
     * @return AssembledParts
     */
    private function resolve(): array
    {
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new Container();

        // A configured bus needs the registry's change events, and PSR-14 hands
        // the SDK a dispatcher it cannot register listeners on — so it wraps.
        $eventDispatcher = null !== $this->notificationBus
            ? new PublishingEventDispatcher($this->notificationBus, $this->eventDispatcher)
            : $this->eventDispatcher;
        $subscriptionManager = $this->subscriptionManager ?? new SessionSubscriptionManager($logger);
        $sessionManager = $this->sessionManager ?? new SessionManager(
            $this->sessionStore ?? new InMemorySessionStore(),
            $logger,
            $this->gcProbability,
            $this->gcDivisor,
        );

        // ExplicitElementLoader and ReflectedElementLoader run before DiscoveryLoader so manual entries are seen first;
        // DiscoveryLoader's identity check then preserves them against same-name discovered entries.
        $loaders = [
            ...$this->loaders,
            new ExplicitElementLoader(
                $this->explicitTools,
                $this->explicitResources,
                $this->explicitResourceTemplates,
                $this->explicitPrompts,
            ),
            new ReflectedElementLoader($this->tools, $this->resources, $this->resourceTemplates, $this->prompts, $logger, $this->schemaGenerator),
        ];

        if (null !== $this->discoveryBasePath) {
            if (null !== $this->discoverer || class_exists(Finder::class)) {
                $discoverer = $this->discoverer ?? $this->createDiscoverer($logger);
                $loaders[] = new DiscoveryLoader($this->discoveryBasePath, $this->discoveryScanDirs, $this->discoveryExcludeDirs, $discoverer, $this->discoveryNamePatterns, $logger);
            } else {
                $logger->warning('File-based discovery requires symfony/finder. Skipping automatic discovery. Run: composer require symfony/finder');
            }
        }

        $chainLoader = new ChainLoader($loaders);

        if ($this->hasCustomRegistry) {
            // Builder can't inject the loader into an already-constructed instance, so load it eagerly.
            // Via loadFrom(), which suppresses the change events the load would otherwise dispatch.
            $registry = $this->registry;
            if ($registry instanceof Registry) {
                $registry->loadFrom($chainLoader);
            } else {
                $chainLoader->load($registry);
            }
            $eagerlyLoaded = true;
        } else {
            $registry = new Registry($eventDispatcher, $logger, loader: $chainLoader);
            if (!$this->lazyLoading) {
                $registry->load();
            }
            $eagerlyLoaded = !$this->lazyLoading;
        }

        $messageFactory = MessageFactory::make(additional: $this->extensionMessages);

        $capabilities = $this->serverCapabilities ?? $this->detectCapabilities($registry, $eagerlyLoaded, $eventDispatcher);

        // Extensions enabled via enableExtension() are folded into caller-supplied
        // capabilities too, so setCapabilities() does not silently drop them.
        if (null !== $this->serverCapabilities && [] !== $this->extensions) {
            $capabilities = $capabilities->withExtensions($this->extensions);
        }

        if (null !== $this->protocolVersion && $this->protocolVersion->isModern()) {
            $logger->warning('Configured protocol version cannot be reached through the "initialize" handshake, negotiating the handshake revisions instead.', [
                'configured' => $this->protocolVersion->value,
                'negotiable' => array_map(static fn (ProtocolVersion $v): string => $v->value, ProtocolVersion::handshakeVersions()),
            ]);
        }

        $serverInfo = $this->serverInfo ?? new Implementation();
        $configuration = new Configuration($serverInfo, $capabilities, $this->paginationLimit, $this->instructions, $this->protocolVersion);
        $referenceHandler = $this->referenceHandler ?? new ReferenceHandler($container);

        $requestHandlers = array_merge($this->requestHandlers, [
            new Handler\Request\CallToolHandler($registry, $referenceHandler, $logger),
            new Handler\Request\CompletionCompleteHandler($registry, $container, $logger),
            new Handler\Request\GetPromptHandler($registry, $referenceHandler, $logger),
            new Handler\Request\InitializeHandler($configuration),
            new Handler\Request\ListPromptsHandler($registry, $this->paginationLimit),
            new Handler\Request\ListResourcesHandler($registry, $this->paginationLimit),
            new Handler\Request\ListResourceTemplatesHandler($registry, $this->paginationLimit),
            new Handler\Request\ListToolsHandler($registry, $this->paginationLimit),
            new Handler\Request\PingHandler(),
            new Handler\Request\ReadResourceHandler($registry, $referenceHandler, $logger),
            new Handler\Request\ResourceSubscribeHandler($registry, $subscriptionManager, $logger),
            new Handler\Request\ResourceUnsubscribeHandler($registry, $subscriptionManager, $logger),
            new Handler\Request\SetLogLevelHandler(),
        ]);

        $notificationHandlers = array_merge($this->notificationHandlers, [
            new Handler\Notification\InitializedHandler(),
        ]);

        return [
            'logger' => $logger,
            'eventDispatcher' => $eventDispatcher,
            'registry' => $registry,
            'configuration' => $configuration,
            'messageFactory' => $messageFactory,
            'sessionManager' => $sessionManager,
            'requestHandlers' => $requestHandlers,
            'notificationHandlers' => $notificationHandlers,
        ];
    }

    /**
     * When loaded, capabilities are read from the registry. When deferred, reading it would force
     * the load, so they are advertised from the configured sources instead — opaque sources (custom
     * loaders, discovery) advertise all kinds, and over-advertising is harmless per MCP semantics.
     */
    private function detectCapabilities(RegistryInterface $registry, bool $eagerlyLoaded, ?EventDispatcherInterface $eventDispatcher): ServerCapabilities
    {
        // Without a dispatcher the registry announces nothing, so there is no
        // list-changed notification to advertise.
        $listChanged = $eventDispatcher instanceof EventDispatcherInterface;

        if ($eagerlyLoaded) {
            $hasResources = $registry->hasResources() || $registry->hasResourceTemplates();

            return new ServerCapabilities(
                tools: $registry->hasTools(),
                toolsListChanged: $listChanged,
                resources: $hasResources,
                resourcesSubscribe: $hasResources,
                resourcesListChanged: $listChanged,
                prompts: $registry->hasPrompts(),
                promptsListChanged: $listChanged,
                logging: true,
                completions: true,
                extensions: $this->extensions ?: null,
            );
        }

        $hasOpaqueSources = [] !== $this->loaders || null !== $this->discoveryBasePath;
        $hasResources = [] !== $this->resources || [] !== $this->explicitResources || [] !== $this->resourceTemplates || [] !== $this->explicitResourceTemplates || $hasOpaqueSources;

        return new ServerCapabilities(
            tools: [] !== $this->tools || [] !== $this->explicitTools || $hasOpaqueSources,
            toolsListChanged: $listChanged,
            resources: $hasResources,
            resourcesSubscribe: $hasResources,
            resourcesListChanged: $listChanged,
            prompts: [] !== $this->prompts || [] !== $this->explicitPrompts || $hasOpaqueSources,
            promptsListChanged: $listChanged,
            logging: true,
            completions: true,
            extensions: $this->extensions ?: null,
        );
    }

    private function createDiscoverer(LoggerInterface $logger): DiscovererInterface
    {
        $discoverer = new Discoverer($logger, null, $this->schemaGenerator);

        if (null !== $this->discoveryCache) {
            return new CachedDiscoverer($discoverer, $this->discoveryCache, $logger);
        }

        return $discoverer;
    }
}
