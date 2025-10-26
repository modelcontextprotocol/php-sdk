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

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\Loader\ArrayLoader;
use Mcp\Capability\Registry\Loader\DiscoveryLoader;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Annotations;
use Mcp\Schema\Implementation;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\SessionFactory;
use Mcp\Server\Session\SessionFactoryInterface;
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

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $discoveryCache = null;

    private ?EventDispatcherInterface $eventDispatcher = null;

    private ?ContainerInterface $container = null;

    private ?SessionFactoryInterface $sessionFactory = null;

    private ?SessionStoreInterface $sessionStore = null;

    private int $sessionTtl = 3600;

    private int $paginationLimit = 50;

    private ?string $instructions = null;

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
     * }[]
     */
    private array $resourceTemplates = [];

    /**
     * @var array{
     *     handler: Handler,
     *     name: ?string,
     *     description: ?string,
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
     * Sets the server's identity. Required.
     */
    public function setServerInfo(string $name, string $version, ?string $description = null): self
    {
        $this->serverInfo = new Implementation(trim($name), trim($version), $description);

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
     */
    /**
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
     * @param iterable<int, RequestHandlerInterface> $handlers
     */
    /**
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

    public function setSession(
        SessionStoreInterface $sessionStore,
        SessionFactoryInterface $sessionFactory = new SessionFactory(),
        int $ttl = 3600,
    ): self {
        $this->sessionFactory = $sessionFactory;
        $this->sessionStore = $sessionStore;
        $this->sessionTtl = $ttl;

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

    /**
     * Manually registers a tool handler.
     *
     * @param Handler                   $handler
     * @param array<string, mixed>|null $inputSchema
     */
    public function addTool(
        callable|array|string $handler,
        ?string $name = null,
        ?string $description = null,
        ?ToolAnnotations $annotations = null,
        ?array $inputSchema = null,
    ): self {
        $this->tools[] = compact('handler', 'name', 'description', 'annotations', 'inputSchema');

        return $this;
    }

    /**
     * Manually registers a resource handler.
     *
     * @param Handler $handler
     */
    public function addResource(
        \Closure|array|string $handler,
        string $uri,
        ?string $name = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?int $size = null,
        ?Annotations $annotations = null,
    ): self {
        $this->resources[] = compact('handler', 'uri', 'name', 'description', 'mimeType', 'size', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     *
     * @param Handler $handler
     */
    public function addResourceTemplate(
        \Closure|array|string $handler,
        string $uriTemplate,
        ?string $name = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?Annotations $annotations = null,
    ): self {
        $this->resourceTemplates[] = compact(
            'handler',
            'uriTemplate',
            'name',
            'description',
            'mimeType',
            'annotations',
        );

        return $this;
    }

    /**
     * Manually registers a prompt handler.
     *
     * @param Handler $handler
     */
    public function addPrompt(\Closure|array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $this->prompts[] = compact('handler', 'name', 'description');

        return $this;
    }

    /**
     * Builds the fully configured Server instance.
     */
    public function build(): Server
    {
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new Container();
        $registry = new Registry($this->eventDispatcher, $logger);

        $loaders = [
            new ArrayLoader($this->tools, $this->resources, $this->resourceTemplates, $this->prompts, $logger),
        ];

        if (null !== $this->discoveryBasePath) {
            $loaders[] = new DiscoveryLoader($this->discoveryBasePath, $this->discoveryScanDirs, $this->discoveryExcludeDirs, $logger, $this->discoveryCache);
        }

        foreach ($loaders as $loader) {
            $loader->load($registry);
        }

        if ($this->serverCapabilities) {
            $registry->setServerCapabilities($this->serverCapabilities);
        }

        $sessionTtl = $this->sessionTtl ?? 3600;
        $sessionFactory = $this->sessionFactory ?? new SessionFactory();
        $sessionStore = $this->sessionStore ?? new InMemorySessionStore($sessionTtl);
        $messageFactory = MessageFactory::make();

        $capabilities = $registry->getCapabilities();
        $configuration = new Configuration($this->serverInfo, $capabilities, $this->paginationLimit, $this->instructions);
        $referenceHandler = new ReferenceHandler($container);

        $requestHandlers = array_merge($this->requestHandlers, [
            new Handler\Request\PingHandler(),
            new Handler\Request\InitializeHandler($configuration),
            new Handler\Request\ListToolsHandler($registry, $this->paginationLimit),
            new Handler\Request\CallToolHandler($registry, $referenceHandler, $logger),
            new Handler\Request\ListResourcesHandler($registry, $this->paginationLimit),
            new Handler\Request\ListResourceTemplatesHandler($registry, $this->paginationLimit),
            new Handler\Request\ReadResourceHandler($registry, $referenceHandler, $logger),
            new Handler\Request\ListPromptsHandler($registry, $this->paginationLimit),
            new Handler\Request\GetPromptHandler($registry, $referenceHandler, $logger),
        ]);

        $notificationHandlers = array_merge($this->notificationHandlers, [
            new Handler\Notification\InitializedHandler(),
        ]);

        $protocol = new Protocol(
            requestHandlers: $requestHandlers,
            notificationHandlers: $notificationHandlers,
            messageFactory: $messageFactory,
            sessionFactory: $sessionFactory,
            sessionStore: $sessionStore,
            logger: $logger,
        );

        return new Server($protocol, $logger);
    }
}
