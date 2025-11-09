<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @phpstan-type DiscoveredCount array{
 *     tools: int,
 *     resources: int,
 *     prompts: int,
 *     resourceTemplates: int,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Discoverer
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private ?DocBlockParser $docBlockParser = null,
        private ?SchemaGenerator $schemaGenerator = null,
    ) {
        $this->docBlockParser = $docBlockParser ?? new DocBlockParser(logger: $this->logger);
        $this->schemaGenerator = $schemaGenerator ?? new SchemaGenerator($this->docBlockParser);
    }

    /**
     * Discover MCP elements in the specified directories and return the discovery state.
     *
     * @param string        $basePath    the base path for resolving directories
     * @param array<string> $directories list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs list of directories (relative to base path) to exclude from the scan
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = []): DiscoveryState
    {
        $startTime = microtime(true);
        $discoveredCount = [
            'tools' => 0,
            'resources' => 0,
            'prompts' => 0,
            'resourceTemplates' => 0,
        ];

        $tools = [];
        $resources = [];
        $prompts = [];
        $resourceTemplates = [];

        try {
            $finder = new Finder();
            $absolutePaths = [];
            foreach ($directories as $dir) {
                $path = rtrim($basePath, '/').'/'.ltrim($dir, '/');
                if (is_dir($path)) {
                    $absolutePaths[] = $path;
                }
            }

            if (empty($absolutePaths)) {
                $this->logger->warning('No valid discovery directories found to scan.', [
                    'configured_paths' => $directories,
                    'base_path' => $basePath,
                ]);

                return new DiscoveryState();
            }

            $finder->files()
                ->in($absolutePaths)
                ->exclude($excludeDirs)
                ->name('*.php');

            foreach ($finder as $file) {
                $this->processFile($file, $discoveredCount, $tools, $resources, $prompts, $resourceTemplates);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error during file finding process for MCP discovery'.json_encode($e->getTrace(), \JSON_PRETTY_PRINT), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $duration = microtime(true) - $startTime;
        $this->logger->info('Attribute discovery finished.', [
            'duration_sec' => round($duration, 3),
            'tools' => $discoveredCount['tools'],
            'resources' => $discoveredCount['resources'],
            'prompts' => $discoveredCount['prompts'],
            'resourceTemplates' => $discoveredCount['resourceTemplates'],
        ]);

        return new DiscoveryState($tools, $resources, $prompts, $resourceTemplates);
    }

    /**
     * Process a single PHP file for MCP elements on classes or methods.
     *
     * @param DiscoveredCount                          $discoveredCount
     * @param array<string, ToolReference>             $tools
     * @param array<string, ResourceReference>         $resources
     * @param array<string, PromptReference>           $prompts
     * @param array<string, ResourceTemplateReference> $resourceTemplates
     */
    private function processFile(SplFileInfo $file, array &$discoveredCount, array &$tools, array &$resources, array &$prompts, array &$resourceTemplates): void
    {
        $className = $this->getClassFromFile($file);
        if (!$className) {
            $this->logger->warning('No valid class found in file', ['file' => $file->getPathname()]);

            return;
        }

        try {
            $reflectionClass = new \ReflectionClass($className);

            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface() || $reflectionClass->isTrait() || $reflectionClass->isEnum()) {
                return;
            }

            $processedViaClassAttribute = false;
            if ($reflectionClass->hasMethod('__invoke')) {
                $invokeMethod = $reflectionClass->getMethod('__invoke');
                if ($invokeMethod->isPublic() && !$invokeMethod->isStatic()) {
                    $attributeTypes = [McpTool::class, McpResource::class, McpPrompt::class, McpResourceTemplate::class];
                    foreach ($attributeTypes as $attributeType) {
                        $classAttribute = $reflectionClass->getAttributes($attributeType, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
                        if ($classAttribute) {
                            $this->processMethod($invokeMethod, $discoveredCount, $classAttribute, $tools, $resources, $prompts, $resourceTemplates);
                            $processedViaClassAttribute = true;
                            break;
                        }
                    }
                }
            }

            if (!$processedViaClassAttribute) {
                foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (
                        $method->getDeclaringClass()->getName() !== $reflectionClass->getName()
                        || $method->isStatic() || $method->isAbstract() || $method->isConstructor() || $method->isDestructor() || '__invoke' === $method->getName()
                    ) {
                        continue;
                    }
                    $attributeTypes = [McpTool::class, McpResource::class, McpPrompt::class, McpResourceTemplate::class];
                    foreach ($attributeTypes as $attributeType) {
                        $methodAttribute = $method->getAttributes($attributeType, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
                        if ($methodAttribute) {
                            $this->processMethod($method, $discoveredCount, $methodAttribute, $tools, $resources, $prompts, $resourceTemplates);
                            break;
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            $this->logger->error('Reflection error processing file for MCP discovery', ['file' => $file->getPathname(), 'class' => $className, 'exception' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error processing file for MCP discovery', [
                'file' => $file->getPathname(),
                'class' => $className,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Process a method with a given MCP attribute instance.
     * Can be called for regular methods or the __invoke method of an invokable class.
     *
     * @param \ReflectionMethod                                                       $method            The target method (e.g., regular method or __invoke).
     * @param DiscoveredCount                                                         $discoveredCount   pass by reference to update counts
     * @param \ReflectionAttribute<McpTool|McpResource|McpPrompt|McpResourceTemplate> $attribute         the ReflectionAttribute instance found (on method or class)
     * @param array<string, ToolReference>                                            $tools
     * @param array<string, ResourceReference>                                        $resources
     * @param array<string, PromptReference>                                          $prompts
     * @param array<string, ResourceTemplateReference>                                $resourceTemplates
     */
    private function processMethod(\ReflectionMethod $method, array &$discoveredCount, \ReflectionAttribute $attribute, array &$tools, array &$resources, array &$prompts, array &$resourceTemplates): void
    {
        $className = $method->getDeclaringClass()->getName();
        $classShortName = $method->getDeclaringClass()->getShortName();
        $methodName = $method->getName();
        $attributeClassName = $attribute->getName();

        try {
            $instance = $attribute->newInstance();

            switch ($attributeClassName) {
                case McpTool::class:
                    $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
                    $name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $instance->description ?? $this->docBlockParser->getSummary($docBlock) ?? null;
                    $inputSchema = $this->schemaGenerator->generate($method);
                    $tool = new Tool(
                        $name,
                        $inputSchema,
                        $description,
                        $instance->annotations,
                        $instance->icons,
                        $instance->meta,
                    );
                    $tools[$name] = new ToolReference($tool, [$className, $methodName], false);
                    ++$discoveredCount['tools'];
                    break;

                case McpResource::class:
                    $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
                    $name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $instance->description ?? $this->docBlockParser->getSummary($docBlock) ?? null;
                    $resource = new Resource(
                        $instance->uri,
                        $name,
                        $description,
                        $instance->mimeType,
                        $instance->annotations,
                        $instance->size,
                        $instance->icons,
                        $instance->meta,
                    );
                    $resources[$instance->uri] = new ResourceReference($resource, [$className, $methodName], false);

                    ++$discoveredCount['resources'];
                    break;

                case McpPrompt::class:
                    $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
                    $name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $instance->description ?? $this->docBlockParser->getSummary($docBlock) ?? null;
                    $arguments = [];
                    $paramTags = $this->docBlockParser->getParamTags($docBlock);
                    foreach ($method->getParameters() as $param) {
                        $reflectionType = $param->getType();
                        if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                            continue;
                        }
                        $paramTag = $paramTags['$'.$param->getName()] ?? null;
                        $arguments[] = new PromptArgument($param->getName(), $paramTag ? trim((string) $paramTag->getDescription()) : null, !$param->isOptional() && !$param->isDefaultValueAvailable());
                    }
                    $prompt = new Prompt($name, $description, $arguments, $instance->icons, $instance->meta);
                    $completionProviders = $this->getCompletionProviders($method);
                    $prompts[$name] = new PromptReference($prompt, [$className, $methodName], false, $completionProviders);
                    ++$discoveredCount['prompts'];
                    break;

                case McpResourceTemplate::class:
                    $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
                    $name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $instance->description ?? $this->docBlockParser->getSummary($docBlock) ?? null;
                    $mimeType = $instance->mimeType;
                    $annotations = $instance->annotations;
                    $meta = $instance->meta ?? null;
                    $resourceTemplate = new ResourceTemplate($instance->uriTemplate, $name, $description, $mimeType, $annotations, $meta);
                    $completionProviders = $this->getCompletionProviders($method);
                    $resourceTemplates[$instance->uriTemplate] = new ResourceTemplateReference($resourceTemplate, [$className, $methodName], false, $completionProviders);
                    ++$discoveredCount['resourceTemplates'];
                    break;
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error("Failed to process MCP attribute on {$className}::{$methodName}", ['attribute' => $attributeClassName, 'exception' => $e->getMessage(), 'trace' => $e->getPrevious() ? $e->getPrevious()->getTraceAsString() : $e->getTraceAsString()]);
        } catch (\Throwable $e) {
            $this->logger->error("Unexpected error processing attribute on {$className}::{$methodName}", ['attribute' => $attributeClassName, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * @return array<string, string|ProviderInterface>
     */
    private function getCompletionProviders(\ReflectionMethod $reflectionMethod): array
    {
        $completionProviders = [];
        foreach ($reflectionMethod->getParameters() as $param) {
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
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->values) {
                    $completionProviders[$param->getName()] = new ListCompletionProvider($attributeInstance->values);
                } elseif ($attributeInstance->enum) {
                    $completionProviders[$param->getName()] = new EnumCompletionProvider($attributeInstance->enum);
                }
            }
        }

        return $completionProviders;
    }

    /**
     * Attempt to determine the FQCN from a PHP file path.
     * Uses tokenization to extract namespace and class name.
     *
     * @return class-string|null the FQCN or null if not found/determinable
     */
    private function getClassFromFile(SplFileInfo $file): ?string
    {
        $this->logger->debug('Processing file', ['path' => $file->getPathname()]);

        try {
            $content = $file->getContents();
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to read file content during class discovery: {$file->getPathname()}", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (\strlen($content) > 500 * 1024) {
            $this->logger->warning('Skipping large file during class discovery.', ['file' => $file->getPathname()]);

            return null;
        }

        try {
            $tokens = token_get_all($content);
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to tokenize file during class discovery: {$file->getPathname()}", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $namespace = '';
        $namespaceFound = false;
        $level = 0;
        $potentialClasses = [];

        $tokenCount = \count($tokens);
        for ($i = 0; $i < $tokenCount; ++$i) {
            if (\is_array($tokens[$i]) && \T_NAMESPACE === $tokens[$i][0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $tokenCount; ++$j) {
                    if (';' === $tokens[$j] || '{' === $tokens[$j]) {
                        $namespaceFound = true;
                        $i = $j;
                        break;
                    }
                    if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [\T_STRING, \T_NAME_QUALIFIED])) {
                        $namespace .= $tokens[$j][1];
                    } elseif (\T_NS_SEPARATOR === $tokens[$j][0]) {
                        $namespace .= '\\';
                    }
                }
                if ($namespaceFound) {
                    break;
                }
            }
        }
        $namespace = trim($namespace, '\\');

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];
            if ('{' === $token) {
                ++$level;

                continue;
            }
            if ('}' === $token) {
                --$level;

                continue;
            }

            if ($level === ($namespaceFound && str_contains($content, "namespace {$namespace} {") ? 1 : 0)) {
                if (\is_array($token) && \in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \defined('T_ENUM') ? \T_ENUM : -1])) {
                    for ($j = $i + 1; $j < $tokenCount; ++$j) {
                        if (\is_array($tokens[$j]) && \T_STRING === $tokens[$j][0]) {
                            $className = $tokens[$j][1];
                            $potentialClasses[] = $namespace ? $namespace.'\\'.$className : $className;
                            $i = $j;
                            break;
                        }
                        if (';' === $tokens[$j] || '{' === $tokens[$j] || ')' === $tokens[$j]) {
                            break;
                        }
                    }
                }
            }
        }

        foreach ($potentialClasses as $potentialClass) {
            if (class_exists($potentialClass, true)) {
                return $potentialClass;
            }
        }

        if (!empty($potentialClasses)) {
            if (!class_exists($potentialClasses[0], false)) {
                $this->logger->debug('getClassFromFile returning potential non-class type. Are you sure this class has been autoloaded?', ['file' => $file->getPathname(), 'type' => $potentialClasses[0]]);
            }

            return $potentialClasses[0];
        }

        return null;
    }
}
