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

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscovererInterface;
use Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\Loader\ArrayLoader;
use Mcp\Capability\Registry\Loader\DiscoveryLoader;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Icon;
use Mcp\Schema\Implementation;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Builder
{
    private ?Implementation $serverInfo = null;

    private RegistryInterface $registry;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $discoveryCache = null;

    private ?EventDispatcherInterface $eventDispatcher = null;

    private ?ContainerInterface $container = null;

    private ?SchemaGeneratorInterface $schemaGenerator = null;

    private ?DiscovererInterface $discoverer = null;

    private ?SessionManagerInterface $sessionManager = null;

    private ?SessionStoreInterface $sessionStore = null;

    private int $paginationLimit = 50;

    private ?string $instructions = null;

    private ?ProtocolVersion $protocolVersion = null;

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
     *     description: ?string,
     *     annotations: ?ToolAnnotations,
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
     *     description: ?string,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $prompts = [];

    private ?string $discoveryBasePath = null;

    /**
     * @var string[]
     */
    private array $discoveryScanDirs = [];

    /**
     * @var array|string[]
     */
    private array $discoveryExcludeDirs = [];

    private ?ServerCapabilities $serverCapabilities = null;

    /**
     * @var LoaderInterface[]
     */
    private array $loaders = [];

    /**
     * Sets the server's identity. Required.
     *
     * @param ?Icon[] $icons
     */
    public function setServerInfo(
        string $name,
        string $version,
        ?string $description = null,
        ?array $icons = null,
        ?string $websiteUrl = null,
    ): self {
        $this->serverInfo = new Implementation(trim($name), trim($version), $description, $icons, $websiteUrl);

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

    public function setDiscoverer(DiscovererInterface $discoverer): self
    {
        $this->discoverer = $discoverer;

        return $this;
    }

    public function setSession(
        ?SessionStoreInterface $sessionStore = null,
        ?SessionManagerInterface $sessionManager = null,
    ): self {
        $this->sessionStore = $sessionStore;
        $this->sessionManager = $sessionManager;

        if (null !== $sessionManager && null !== $sessionStore) {
            throw new InvalidArgumentException('Cannot set both SessionStore and SessionManager. Set only one or the other.');
        }

        return $this;
    }

    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     */
    public function setDiscovery(
        string $basePath,
        array $scanDirs = ['.', 'src'],
        array $excludeDirs = [],
        ?CacheInterface $cache = null,
    ): self {
        $this->discoveryBasePath = $basePath;
        $this->discoveryScanDirs = $scanDirs;
        $this->discoveryExcludeDirs = $excludeDirs;
        $this->discoveryCache = $cache;

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
     * @param array<string, mixed>|null $inputSchema
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $outputSchema
     */
    public function addTool(
        callable|array|string $handler,
        ?string $name = null,
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
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     */
    public function addResource(
        \Closure|array|string $handler,
        string $uri,
        ?string $name = null,
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
     * @param array<string, mixed>|null $meta
     */
    public function addResourceTemplate(
        \Closure|array|string $handler,
        string $uriTemplate,
        ?string $name = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?Annotations $annotations = null,
        ?array $meta = null,
    ): self {
        $this->resourceTemplates[] = compact(
            'handler',
            'uriTemplate',
            'name',
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
        ?string $description = null,
        ?array $icons = null,
        ?array $meta = null,
    ): self {
        $this->prompts[] = compact('handler', 'name', 'description', 'icons', 'meta');

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
     * Builds the fully configured Server instance.
     */
    public function build(): Server
    {
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new Container();
        $registry = $this->registry ?? new Registry($this->eventDispatcher, $logger);

        $loaders = [
            ...$this->loaders,
            new ArrayLoader($this->tools, $this->resources, $this->resourceTemplates, $this->prompts, $logger, $this->schemaGenerator),
        ];

        if (null !== $this->discoveryBasePath) {
            $discoverer = $this->discoverer ?? $this->createDiscoverer($logger);
            $loaders[] = new DiscoveryLoader($this->discoveryBasePath, $this->discoveryScanDirs, $this->discoveryExcludeDirs, $discoverer);
        }

        foreach ($loaders as $loader) {
            $loader->load($registry);
        }

        $sessionManager = $this->sessionManager ?? new SessionManager(
            $this->sessionStore ?? new InMemorySessionStore(),
            $logger,
        );
        $messageFactory = MessageFactory::make();

        $capabilities = $this->serverCapabilities ?? new ServerCapabilities(
            tools: $registry->hasTools(),
            toolsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            resources: $registry->hasResources() || $registry->hasResourceTemplates(),
            resourcesSubscribe: false,
            resourcesListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            prompts: $registry->hasPrompts(),
            promptsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            logging: true,
            completions: true,
        );

        $serverInfo = $this->serverInfo ?? new Implementation();
        $configuration = new Configuration($serverInfo, $capabilities, $this->paginationLimit, $this->instructions, $this->protocolVersion);
        $referenceHandler = new ReferenceHandler($container);

        $requestHandlers = array_merge($this->requestHandlers, [
            new Handler\Request\CallToolHandler($registry, $referenceHandler, $logger),
            new Handler\Request\CompletionCompleteHandler($registry, $container),
            new Handler\Request\GetPromptHandler($registry, $referenceHandler, $logger),
            new Handler\Request\InitializeHandler($configuration),
            new Handler\Request\ListPromptsHandler($registry, $this->paginationLimit),
            new Handler\Request\ListResourcesHandler($registry, $this->paginationLimit),
            new Handler\Request\ListResourceTemplatesHandler($registry, $this->paginationLimit),
            new Handler\Request\ListToolsHandler($registry, $this->paginationLimit),
            new Handler\Request\PingHandler(),
            new Handler\Request\ReadResourceHandler($registry, $referenceHandler, $logger),
            new Handler\Request\SetLogLevelHandler(),
        ]);

        $notificationHandlers = array_merge($this->notificationHandlers, [
            new Handler\Notification\InitializedHandler(),
        ]);

        $protocol = new Protocol(
            requestHandlers: $requestHandlers,
            notificationHandlers: $notificationHandlers,
            messageFactory: $messageFactory,
            sessionManager: $sessionManager,
            logger: $logger,
        );

        return new Server($protocol, $logger);
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
