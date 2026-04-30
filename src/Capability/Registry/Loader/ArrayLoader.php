<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry\Loader;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ConfigurationException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Icon;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Handler;
use Mcp\Server\Handler\RuntimeHandlerInterface;
use Mcp\Server\Handler\RuntimePromptHandlerInterface;
use Mcp\Server\Handler\RuntimeResourceHandlerInterface;
use Mcp\Server\Handler\RuntimeResourceTemplateHandlerInterface;
use Mcp\Server\Handler\RuntimeToolHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Antoine Bluchet <soyuka@gmail.com>
 *
 * @phpstan-import-type Handler from ElementReference
 */
final class ArrayLoader implements LoaderInterface
{
    /**
     * @param array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     annotations: ?ToolAnnotations,
     *     inputSchema: ?array<string, mixed>,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>,
     *     outputSchema: ?array<string, mixed>
     * }[] $tools
     * @param array{
     *     handler: Handler,
     *     uri: string,
     *     name: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     size: int|null,
     *     annotations: ?Annotations,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[] $resources
     * @param array{
     *     handler: Handler,
     *     uriTemplate: string,
     *     name: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     annotations: ?Annotations,
     *     meta: ?array<string, mixed>
     * }[] $resourceTemplates
     * @param array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[] $prompts
     */
    public function __construct(
        private readonly array $tools = [],
        private readonly array $resources = [],
        private readonly array $resourceTemplates = [],
        private readonly array $prompts = [],
        private LoggerInterface $logger = new NullLogger(),
        private ?SchemaGeneratorInterface $schemaGenerator = null,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $docBlockParser = new DocBlockParser(logger: $this->logger);
        $schemaGenerator = $this->schemaGenerator ?? new SchemaGenerator($docBlockParser);

        // Register Tools
        foreach ($this->tools as $data) {
            try {
                $handler = $data['handler'];
                $prepared = $handler instanceof RuntimeToolHandlerInterface
                    ? $this->prepareRuntimeTool($data, $handler)
                    : $this->prepareReflectedTool($data, $handler, $schemaGenerator, $docBlockParser);

                $tool = new Tool(
                    name: $prepared['name'],
                    title: $data['title'] ?? null,
                    inputSchema: $prepared['inputSchema'],
                    description: $prepared['description'],
                    annotations: $data['annotations'] ?? null,
                    icons: $data['icons'] ?? null,
                    meta: $data['meta'] ?? null,
                    outputSchema: $prepared['outputSchema'],
                );
                $registry->registerTool($tool, $data['handler'], true);

                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual {$prepared['kind']} {$prepared['name']} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to register manual tool',
                    ['handler' => $this->getHandlerDescription($data['handler']), 'name' => $data['name'], 'exception' => $e],
                );
                if ($e instanceof ConfigurationException) {
                    throw $e;
                }
                $nameForMessage = $data['name'] ?? '<unnamed>';
                throw new ConfigurationException("Error registering manual tool '{$nameForMessage}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Resources
        foreach ($this->resources as $data) {
            try {
                $handler = $data['handler'];
                $prepared = $handler instanceof RuntimeResourceHandlerInterface
                    ? $this->prepareRuntimeResource($data, $handler)
                    : $this->prepareReflectedResource($data, $handler, $docBlockParser);

                $resource = new Resource(
                    uri: $data['uri'],
                    name: $prepared['name'],
                    description: $prepared['description'],
                    mimeType: $data['mimeType'] ?? null,
                    annotations: $data['annotations'] ?? null,
                    size: $data['size'] ?? null,
                    icons: $data['icons'] ?? null,
                    meta: $data['meta'] ?? null,
                );
                $registry->registerResource($resource, $data['handler'], true);

                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual {$prepared['kind']} {$prepared['name']} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to register manual resource',
                    ['handler' => $this->getHandlerDescription($data['handler']), 'uri' => $data['uri'], 'exception' => $e],
                );
                if ($e instanceof ConfigurationException) {
                    throw $e;
                }
                throw new ConfigurationException("Error registering manual resource '{$data['uri']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Templates
        foreach ($this->resourceTemplates as $data) {
            try {
                $handler = $data['handler'];
                $prepared = $handler instanceof RuntimeResourceTemplateHandlerInterface
                    ? $this->prepareRuntimeResourceTemplate($data, $handler)
                    : $this->prepareReflectedResourceTemplate($data, $handler, $docBlockParser);

                $template = new ResourceTemplate(
                    uriTemplate: $data['uriTemplate'],
                    name: $prepared['name'],
                    description: $prepared['description'],
                    mimeType: $data['mimeType'] ?? null,
                    annotations: $data['annotations'] ?? null,
                    meta: $data['meta'] ?? null,
                );
                $registry->registerResourceTemplate($template, $data['handler'], $prepared['completionProviders'], true);

                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual {$prepared['kind']} {$prepared['name']} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to register manual template',
                    ['handler' => $this->getHandlerDescription($data['handler']), 'uriTemplate' => $data['uriTemplate'], 'exception' => $e],
                );
                if ($e instanceof ConfigurationException) {
                    throw $e;
                }
                throw new ConfigurationException("Error registering manual resource template '{$data['uriTemplate']}': {$e->getMessage()}", 0, $e);
            }
        }

        // Register Prompts
        foreach ($this->prompts as $data) {
            try {
                $handler = $data['handler'];
                $prepared = $handler instanceof RuntimePromptHandlerInterface
                    ? $this->prepareRuntimePrompt($data, $handler)
                    : $this->prepareReflectedPrompt($data, $handler, $docBlockParser);

                $prompt = new Prompt(
                    name: $prepared['name'],
                    title: $data['title'] ?? null,
                    description: $prepared['description'],
                    arguments: $prepared['arguments'],
                    icons: $data['icons'] ?? null,
                    meta: $data['meta'] ?? null
                );
                $registry->registerPrompt($prompt, $data['handler'], $prepared['completionProviders'], true);

                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual {$prepared['kind']} {$prepared['name']} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to register manual prompt',
                    ['handler' => $this->getHandlerDescription($data['handler']), 'name' => $data['name'], 'exception' => $e],
                );
                if ($e instanceof ConfigurationException) {
                    throw $e;
                }
                $nameForMessage = $data['name'] ?? '<unnamed>';
                throw new ConfigurationException("Error registering manual prompt '{$nameForMessage}': {$e->getMessage()}", 0, $e);
            }
        }

        $this->logger->debug('Manual element registration complete.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: ?array<string, mixed>, kind: string}
     */
    private function prepareRuntimeTool(array $data, RuntimeToolHandlerInterface $handler): array
    {
        $this->assertRuntimeRequiredFields($data, $handler, 'tool');

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'inputSchema' => $data['inputSchema'] ?? $handler->getInputSchema(),
            'outputSchema' => $data['outputSchema'] ?? $handler->getOutputSchema(),
            'kind' => 'runtime tool',
        ];
    }

    /**
     * @param array<string, mixed>                               $data
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return array{name: string, description: ?string, inputSchema: array<string, mixed>, outputSchema: ?array<string, mixed>, kind: string}
     */
    private function prepareReflectedTool(array $data, \Closure|array|string $handler, SchemaGeneratorInterface $schemaGenerator, DocBlockParser $docBlockParser): array
    {
        $reflection = HandlerResolver::resolve($handler);
        $meta = $this->resolveReflectedNameAndDescription($data, $handler, $reflection, $docBlockParser, 'closure_tool_');

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'inputSchema' => $data['inputSchema'] ?? $schemaGenerator->generate($reflection),
            'outputSchema' => $data['outputSchema'] ?? null,
            'kind' => 'tool',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, description: string, kind: string}
     */
    private function prepareRuntimeResource(array $data, RuntimeResourceHandlerInterface $handler): array
    {
        $this->assertRuntimeRequiredFields($data, $handler, 'resource');

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'kind' => 'runtime resource',
        ];
    }

    /**
     * @param array<string, mixed>                               $data
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return array{name: string, description: ?string, kind: string}
     */
    private function prepareReflectedResource(array $data, \Closure|array|string $handler, DocBlockParser $docBlockParser): array
    {
        $reflection = HandlerResolver::resolve($handler);
        $meta = $this->resolveReflectedNameAndDescription($data, $handler, $reflection, $docBlockParser, 'closure_resource_');

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'kind' => 'resource',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, description: string, completionProviders: array<string, ProviderInterface|class-string>, kind: string}
     */
    private function prepareRuntimeResourceTemplate(array $data, RuntimeResourceTemplateHandlerInterface $handler): array
    {
        $this->assertRuntimeRequiredFields($data, $handler, 'resource template');

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'completionProviders' => $handler->getCompletionProviders(),
            'kind' => 'runtime template',
        ];
    }

    /**
     * @param array<string, mixed>                               $data
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return array{name: string, description: ?string, completionProviders: array<string, ProviderInterface|class-string>, kind: string}
     */
    private function prepareReflectedResourceTemplate(array $data, \Closure|array|string $handler, DocBlockParser $docBlockParser): array
    {
        $reflection = HandlerResolver::resolve($handler);
        $meta = $this->resolveReflectedNameAndDescription($data, $handler, $reflection, $docBlockParser, 'closure_template_');

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'completionProviders' => $this->getCompletionProviders($reflection),
            'kind' => 'template',
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, description: string, arguments: PromptArgument[], completionProviders: array<string, ProviderInterface|class-string>, kind: string}
     */
    private function prepareRuntimePrompt(array $data, RuntimePromptHandlerInterface $handler): array
    {
        $this->assertRuntimeRequiredFields($data, $handler, 'prompt');

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'arguments' => $handler->getPromptArguments(),
            'completionProviders' => $handler->getCompletionProviders(),
            'kind' => 'runtime prompt',
        ];
    }

    /**
     * @param array<string, mixed>                               $data
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return array{name: string, description: ?string, arguments: PromptArgument[], completionProviders: array<string, ProviderInterface|class-string>, kind: string}
     */
    private function prepareReflectedPrompt(array $data, \Closure|array|string $handler, DocBlockParser $docBlockParser): array
    {
        $reflection = HandlerResolver::resolve($handler);
        $meta = $this->resolveReflectedNameAndDescription($data, $handler, $reflection, $docBlockParser, 'closure_prompt_');

        $arguments = [];
        $paramTags = $reflection instanceof \ReflectionMethod ? $docBlockParser->getParamTags(
            $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null),
        ) : [];
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
                !$param->isOptional() && !$param->isDefaultValueAvailable(),
            );
        }

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'arguments' => $arguments,
            'completionProviders' => $this->getCompletionProviders($reflection),
            'kind' => 'prompt',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertRuntimeRequiredFields(array $data, RuntimeHandlerInterface $handler, string $kindLabel): void
    {
        foreach (['name', 'description'] as $field) {
            if (null === $data[$field]) {
                throw new ConfigurationException(\sprintf('Runtime %s handler %s is missing a %s; the Builder requires an explicit %s for runtime handlers.', $kindLabel, $handler::class, $field, $field));
            }
        }
    }

    /**
     * @param array<string, mixed>                               $data
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     *
     * @return array{name: string, description: ?string}
     */
    private function resolveReflectedNameAndDescription(
        array $data,
        \Closure|array|string $handler,
        \ReflectionFunction|\ReflectionMethod $reflection,
        DocBlockParser $docBlockParser,
        string $closurePrefix,
    ): array {
        if ($reflection instanceof \ReflectionFunction) {
            return [
                'name' => $data['name'] ?? $closurePrefix.spl_object_id($handler),
                'description' => $data['description'] ?? null,
            ];
        }

        $classShortName = $reflection->getDeclaringClass()->getShortName();
        $methodName = $reflection->getName();
        $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);

        return [
            'name' => $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName),
            'description' => $data['description'] ?? $docBlockParser->getDescription($docBlock) ?? null,
        ];
    }

    /**
     * @param Handler $handler
     */
    private function getHandlerDescription(\Closure|array|string|RuntimeHandlerInterface $handler): string
    {
        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        if ($handler instanceof RuntimeHandlerInterface) {
            return $handler::class;
        }

        if (\is_array($handler)) {
            return \sprintf(
                '%s::%s',
                \is_object($handler[0]) ? $handler[0]::class : $handler[0],
                $handler[1],
            );
        }

        return (string) $handler;
    }

    /**
     * @return array<string, ProviderInterface|class-string>
     */
    private function getCompletionProviders(\ReflectionMethod|\ReflectionFunction $reflection): array
    {
        $completionProviders = [];
        foreach ($reflection->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }

            $completionAttributes = $param->getAttributes(
                CompletionProvider::class,
                \ReflectionAttribute::IS_INSTANCEOF,
            );
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
