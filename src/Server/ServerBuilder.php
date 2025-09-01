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

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Prompt\Completion\EnumCompletionProvider;
use Mcp\Capability\Prompt\Completion\ListCompletionProvider;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\JsonRpc\Handler;
use Mcp\Schema\Annotations;
use Mcp\Schema\Implementation;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class ServerBuilder
{
    private ?Implementation $serverInfo = null;

    private ?LoggerInterface $logger = null;

    private ?CacheInterface $cache = null;

    private ?EventDispatcherInterface $eventDispatcher = null;

    private ?ContainerInterface $container = null;

    private ?int $paginationLimit = 50;

    private ?string $instructions = null;

    /** @var array<
     *     array{handler: array|string|Closure,
     *     name: string|null,
     *     description: string|null,
     *     annotations: ToolAnnotations|null}
     * > */
    private array $manualTools = [];

    /** @var array<
     *     array{handler: array|string|Closure,
     *     uri: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     size: int|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResources = [];

    /** @var array<
     *     array{handler: array|string|Closure,
     *     uriTemplate: string,
     *     name: string|null,
     *     description: string|null,
     *     mimeType: string|null,
     *     annotations: Annotations|null}
     * > */
    private array $manualResourceTemplates = [];
    /** @var array<
     *     array{handler: array|string|Closure,
     *     name: string|null,
     *     description: string|null}
     * > */
    private array $manualPrompts = [];
    private ?string $discoveryBasePath = null;
    /**
     * @var array|string[]
     */
    private array $discoveryScanDirs = [];
    private array $discoveryExcludeDirs = [];

    /**
     * Sets the server's identity. Required.
     */
    public function withServerInfo(string $name, string $version, ?string $description = null): self
    {
        $this->serverInfo = new Implementation(trim($name), trim($version), $description);

        return $this;
    }

    /**
     * Configures the server's pagination limit.
     */
    public function withPaginationLimit(int $paginationLimit): self
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
    public function withInstructions(?string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Provides a PSR-3 logger instance. Defaults to NullLogger.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Provides a PSR-11 DI container, primarily for resolving user-defined handler classes.
     * Defaults to a basic internal container.
     */
    public function withContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function withDiscovery(
        string $basePath,
        array $scanDirs = ['.', 'src'],
        array $excludeDirs = [],
    ): self {
        $this->discoveryBasePath = $basePath;
        $this->discoveryScanDirs = $scanDirs;
        $this->discoveryExcludeDirs = $excludeDirs;

        return $this;
    }

    /**
     * Manually registers a tool handler.
     */
    public function withTool(callable|array|string $handler, ?string $name = null, ?string $description = null, ?ToolAnnotations $annotations = null, ?array $inputSchema = null): self
    {
        $this->manualTools[] = compact('handler', 'name', 'description', 'annotations', 'inputSchema');

        return $this;
    }

    /**
     * Manually registers a resource handler.
     */
    public function withResource(callable|array|string $handler, string $uri, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null): self
    {
        $this->manualResources[] = compact('handler', 'uri', 'name', 'description', 'mimeType', 'size', 'annotations');

        return $this;
    }

    /**
     * Manually registers a resource template handler.
     */
    public function withResourceTemplate(callable|array|string $handler, string $uriTemplate, ?string $name = null, ?string $description = null, ?string $mimeType = null, ?Annotations $annotations = null): self
    {
        $this->manualResourceTemplates[] = compact('handler', 'uriTemplate', 'name', 'description', 'mimeType', 'annotations');

        return $this;
    }

    /**
     * Manually registers a prompt handler.
     */
    public function withPrompt(callable|array|string $handler, ?string $name = null, ?string $description = null): self
    {
        $this->manualPrompts[] = compact('handler', 'name', 'description');

        return $this;
    }

    /**
     * Builds the fully configured Server instance.
     */
    public function build(): Server
    {
        $container = $this->container ?? new Container();
        $registry = new Registry(new ReferenceHandler($container), $this->eventDispatcher, $this->logger);

        $this->registerManualElements($registry, $this->logger);

        if (null !== $this->discoveryBasePath) {
            $discovery = new Discoverer($registry, $this->logger);
            $discovery->discover($this->discoveryBasePath, $this->discoveryScanDirs, $this->discoveryExcludeDirs);
        }

        return new Server(
            Handler::make($registry, $this->serverInfo, $this->logger),
            $this->logger,
        );
    }

    /**
     * Helper to perform the actual registration based on stored data.
     * Moved into the builder.
     */
    private function registerManualElements(Registry $registry, LoggerInterface $logger = new NullLogger()): void
    {
        if (empty($this->manualTools) && empty($this->manualResources) && empty($this->manualResourceTemplates) && empty($this->manualPrompts)) {
            return;
        }

        $docBlockParser = new DocBlockParser(logger: $logger);
        $schemaGenerator = new SchemaGenerator($docBlockParser);

        // Register Tools
        foreach ($this->manualTools as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_tool_'.spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $inputSchema = $data['inputSchema'] ?? $schemaGenerator->generate($reflection);

                $tool = new Tool($name, $inputSchema, $description, $data['annotations']);
                $registry->registerTool($tool, $data['handler'], true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (\is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual tool {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual tool '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Resources
        foreach ($this->manualResources as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_resource_'.spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $uri = $data['uri'];
                $mimeType = $data['mimeType'];
                $size = $data['size'];
                $annotations = $data['annotations'];

                $resource = new Resource($uri, $name, $description, $mimeType, $annotations, $size);
                $registry->registerResource($resource, $data['handler'], true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (\is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual resource {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource '{$data['uri']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Templates
        foreach ($this->manualResourceTemplates as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_template_'.spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $uriTemplate = $data['uriTemplate'];
                $mimeType = $data['mimeType'];
                $annotations = $data['annotations'];

                $template = new ResourceTemplate($uriTemplate, $name, $description, $mimeType, $annotations);
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerResourceTemplate($template, $data['handler'], $completionProviders, true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (\is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual template {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource template '{$data['uriTemplate']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Prompts
        foreach ($this->manualPrompts as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);

                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_prompt_'.spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getSummary($docBlock) ?? null;
                }

                $arguments = [];
                $paramTags = $reflection instanceof \ReflectionMethod ? $docBlockParser->getParamTags($docBlockParser->parseDocBlock($reflection->getDocComment() ?? null)) : [];
                foreach ($reflection->getParameters() as $param) {
                    $reflectionType = $param->getType();

                    // Basic DI check (heuristic)
                    if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                        continue;
                    }

                    $paramTag = $paramTags['$'.$param->getName()] ?? null;
                    $arguments[] = new PromptArgument(
                        $param->getName(),
                        $paramTag ? trim((string) $paramTag->getDescription()) : null,
                        !$param->isOptional() && !$param->isDefaultValueAvailable()
                    );
                }

                $prompt = new Prompt($name, $description, $arguments);
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerPrompt($prompt, $data['handler'], $completionProviders, true);

                $handlerDesc = $data['handler'] instanceof \Closure ? 'Closure' : (\is_array($data['handler']) ? implode('::', $data['handler']) : $data['handler']);
                $logger->debug("Registered manual prompt {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $logger->error('Failed to register manual prompt', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual prompt '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }

        $logger->debug('Manual element registration complete.');
    }

    private function getCompletionProviders(\ReflectionMethod|\ReflectionFunction $reflection): array
    {
        $completionProviders = [];
        foreach ($reflection->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }

            $completionAttributes = $param->getAttributes(CompletionProvider::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($completionAttributes)) {
                $attributeInstance = $completionAttributes[0]->newInstance();

                if ($attributeInstance->provider) {
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->providerClass) {
                    $completionProviders[$param->getName()] = $attributeInstance->providerClass;
                } elseif ($attributeInstance->values) {
                    $completionProviders[$param->getName()] = new ListCompletionProvider($attributeInstance->values);
                } elseif ($attributeInstance->enum) {
                    $completionProviders[$param->getName()] = new EnumCompletionProvider($attributeInstance->enum);
                }
            }
        }

        return $completionProviders;
    }
}
