<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability;

use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

class RegistryTest extends TestCase
{
    private Registry $registry;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new Registry(null, $this->logger);
    }

    public function testHasserReturnFalseForEmptyRegistry(): void
    {
        $this->assertFalse($this->registry->hasTools());
        $this->assertFalse($this->registry->hasResources());
        $this->assertFalse($this->registry->hasResourceTemplates());
        $this->assertFalse($this->registry->hasPrompts());
    }

    public function testHasToolsReturnsTrueWhenToolIsRegistered(): void
    {
        $tool = $this->createValidTool('test_tool');
        $this->registry->registerTool($tool, static fn () => 'result');

        $this->assertTrue($this->registry->hasTools());
    }

    public function testGetToolsReturnsAllRegisteredTools(): void
    {
        $tool1 = $this->createValidTool('tool1');
        $tool2 = $this->createValidTool('tool2');

        $this->registry->registerTool($tool1, static fn () => 'result1');
        $this->registry->registerTool($tool2, static fn () => 'result2');

        $tools = $this->registry->getTools();
        $this->assertCount(2, $tools);
        $this->assertArrayHasKey('tool1', $tools->references);
        $this->assertArrayHasKey('tool2', $tools->references);
        $this->assertInstanceOf(Tool::class, $tools->references['tool1']);
        $this->assertInstanceOf(Tool::class, $tools->references['tool2']);
    }

    public function testGetToolReturnsRegisteredTool(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = static fn () => 'result';

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertInstanceOf(ToolReference::class, $toolRef);
        $this->assertEquals($tool->name, $toolRef->tool->name);
        $this->assertEquals($handler, $toolRef->handler);
    }

    public function testRegisterToolOverwritesPriorRegistration(): void
    {
        $first = $this->createValidTool('test_tool');
        $second = $this->createValidTool('test_tool');

        $this->registry->registerTool($first, static fn () => 'first');
        $this->registry->registerTool($second, static fn () => 'second');

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals('second', ($toolRef->handler)());
    }

    public function testGetToolThrowsExceptionForUnregisteredTool(): void
    {
        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessage('Tool not found: "non_existent_tool".');

        $this->registry->getTool('non_existent_tool');
    }

    public function testHasResourceReturnsTrueWhenResourceIsRegistered(): void
    {
        $resource = $this->createValidResource('test://resource');
        $this->registry->registerResource($resource, static fn () => 'content');

        $this->assertTrue($this->registry->hasResources());
    }

    public function testGetResourcesReturnsAllRegisteredResources(): void
    {
        $resource1 = $this->createValidResource('test://resource1');
        $resource2 = $this->createValidResource('test://resource2');

        $this->registry->registerResource($resource1, static fn () => 'content1');
        $this->registry->registerResource($resource2, static fn () => 'content2');

        $resources = $this->registry->getResources();
        $this->assertCount(2, $resources);
        $this->assertArrayHasKey('test://resource1', $resources->references);
        $this->assertArrayHasKey('test://resource2', $resources->references);
        $this->assertInstanceOf(ResourceDefinition::class, $resources->references['test://resource1']);
        $this->assertInstanceOf(ResourceDefinition::class, $resources->references['test://resource2']);
    }

    public function testGetResourceReturnsRegisteredResource(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = static fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->resource->uri);
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testRegisterResourceOverwritesPriorRegistration(): void
    {
        $first = $this->createValidResource('test://resource');
        $second = $this->createValidResource('test://resource');

        $this->registry->registerResource($first, static fn () => 'first');
        $this->registry->registerResource($second, static fn () => 'second');

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertEquals('second', ($resourceRef->handler)());
    }

    public function testGetResourceThrowsExceptionForUnregisteredResource(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://non_existent".');

        $this->registry->getResource('test://non_existent');
    }

    public function testHasResourceTemplatesReturnsTrueWhenResourceTemplateIsRegistered(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $this->registry->registerResourceTemplate($template, static fn () => 'content');

        $this->assertTrue($this->registry->hasResourceTemplates());
    }

    public function testGetResourceTemplatesReturnsAllRegisteredTemplates(): void
    {
        $template1 = $this->createValidResourceTemplate('test1://{id}');
        $template2 = $this->createValidResourceTemplate('test2://{category}');

        $this->registry->registerResourceTemplate($template1, static fn () => 'content1');
        $this->registry->registerResourceTemplate($template2, static fn () => 'content2');

        $templates = $this->registry->getResourceTemplates();
        $this->assertCount(2, $templates);
        $this->assertArrayHasKey('test1://{id}', $templates->references);
        $this->assertArrayHasKey('test2://{category}', $templates->references);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test1://{id}']);
        $this->assertInstanceOf(ResourceTemplate::class, $templates->references['test2://{category}']);
    }

    public function testGetResourceTemplateReturnsRegisteredTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertInstanceOf(ResourceTemplateReference::class, $templateRef);
        $this->assertEquals($template->uriTemplate, $templateRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $templateRef->handler);
    }

    public function testGetResourcePrefersDirectResourceOverTemplate(): void
    {
        $resource = $this->createValidResource('test://123');
        $resourceHandler = static fn () => 'direct resource';

        $template = $this->createValidResourceTemplate('test://{id}');
        $templateHandler = static fn (string $id) => "template for {$id}";

        $this->registry->registerResource($resource, $resourceHandler);
        $this->registry->registerResourceTemplate($template, $templateHandler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceReference::class, $resourceRef);
        $this->assertEquals($resource->uri, $resourceRef->resource->uri);
    }

    public function testGetResourceMatchesResourceTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $resourceRef = $this->registry->getResource('test://123');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals($template->uriTemplate, $resourceRef->resourceTemplate->uriTemplate);
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testGetResourceWithIncludeTemplatesFalseThrowsException(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $handler = static fn (string $id) => "content for {$id}";

        $this->registry->registerResourceTemplate($template, $handler);

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://123".');

        $this->registry->getResource('test://123', false);
    }

    public function testRegisterResourceTemplateWithCompletionProviders(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $completionProviders = ['id' => EnumCompletionProvider::class];

        $this->registry->registerResourceTemplate($template, static fn () => 'content', $completionProviders);

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertEquals($completionProviders, $templateRef->completionProviders);
    }

    public function testRegisterResourceTemplateOverwritesPriorRegistration(): void
    {
        $first = $this->createValidResourceTemplate('test://{id}');
        $second = $this->createValidResourceTemplate('test://{id}');

        $this->registry->registerResourceTemplate($first, static fn () => 'first');
        $this->registry->registerResourceTemplate($second, static fn () => 'second');

        $templateRef = $this->registry->getResourceTemplate('test://{id}');
        $this->assertEquals('second', ($templateRef->handler)());
    }

    public function testResourceTemplateMatchingPrefersMoreSpecificMatches(): void
    {
        $specificTemplate = $this->createValidResourceTemplate('test://users/{userId}/profile');
        $genericTemplate = $this->createValidResourceTemplate('test://users/{userId}');

        $this->registry->registerResourceTemplate($genericTemplate, static fn () => 'generic');
        $this->registry->registerResourceTemplate($specificTemplate, static fn () => 'specific');

        // Should match the more specific template first
        $resourceRef = $this->registry->getResource('test://users/123/profile');
        $this->assertInstanceOf(ResourceTemplateReference::class, $resourceRef);
        $this->assertEquals('test://users/{userId}/profile', $resourceRef->resourceTemplate->uriTemplate);
    }

    public function testGetResourceTemplateThrowsExceptionForUnregisteredTemplate(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Resource not found for uri: "test://{non_existent}".');

        $this->registry->getResourceTemplate('test://{non_existent}');
    }

    public function testHasPromptsReturnsTrueWhenPromptIsRegistered(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $this->registry->registerPrompt($prompt, static fn () => []);

        $this->assertTrue($this->registry->hasPrompts());
    }

    public function testGetPromptsReturnsAllRegisteredPrompts(): void
    {
        $prompt1 = $this->createValidPrompt('prompt1');
        $prompt2 = $this->createValidPrompt('prompt2');

        $this->registry->registerPrompt($prompt1, static fn () => []);
        $this->registry->registerPrompt($prompt2, static fn () => []);

        $prompts = $this->registry->getPrompts();
        $this->assertCount(2, $prompts);
        $this->assertArrayHasKey('prompt1', $prompts->references);
        $this->assertArrayHasKey('prompt2', $prompts->references);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt1']);
        $this->assertInstanceOf(Prompt::class, $prompts->references['prompt2']);
    }

    public function testGetPromptReturnsRegisteredPrompt(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $handler = static fn () => ['role' => 'user', 'content' => 'test message'];

        $this->registry->registerPrompt($prompt, $handler);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertInstanceOf(PromptReference::class, $promptRef);
        $this->assertEquals($prompt->name, $promptRef->prompt->name);
        $this->assertEquals($handler, $promptRef->handler);
    }

    public function testRegisterPromptWithCompletionProviders(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $completionProviders = ['param' => EnumCompletionProvider::class];

        $this->registry->registerPrompt($prompt, static fn () => [], $completionProviders);

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertEquals($completionProviders, $promptRef->completionProviders);
    }

    public function testRegisterPromptOverwritesPriorRegistration(): void
    {
        $first = $this->createValidPrompt('test_prompt');
        $second = $this->createValidPrompt('test_prompt');

        $this->registry->registerPrompt($first, static fn () => 'first');
        $this->registry->registerPrompt($second, static fn () => 'second');

        $promptRef = $this->registry->getPrompt('test_prompt');
        $this->assertEquals('second', ($promptRef->handler)());
    }

    public function testGetPromptThrowsExceptionForUnregisteredPrompt(): void
    {
        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessage('Prompt not found: "non_existent_prompt".');

        $this->registry->getPrompt('non_existent_prompt');
    }

    public function testUnregisterToolRemovesRegisteredTool(): void
    {
        $tool = $this->createValidTool('test_tool');
        $this->registry->registerTool($tool, static fn () => 'result');

        $this->registry->unregisterTool('test_tool');

        $this->expectException(ToolNotFoundException::class);
        $this->registry->getTool('test_tool');
    }

    public function testUnregisterToolIsIdempotentForAbsentName(): void
    {
        $this->registry->unregisterTool('never_registered');

        $this->assertFalse($this->registry->hasTools());
    }

    public function testUnregisterResourceRemovesRegisteredResource(): void
    {
        $resource = $this->createValidResource('test://resource');
        $this->registry->registerResource($resource, static fn () => 'content');

        $this->registry->unregisterResource('test://resource');

        $this->expectException(ResourceNotFoundException::class);
        $this->registry->getResource('test://resource', false);
    }

    public function testUnregisterResourceTemplateRemovesRegisteredTemplate(): void
    {
        $template = $this->createValidResourceTemplate('test://{id}');
        $this->registry->registerResourceTemplate($template, static fn () => 'content');

        $this->registry->unregisterResourceTemplate('test://{id}');

        $this->expectException(ResourceNotFoundException::class);
        $this->registry->getResourceTemplate('test://{id}');
    }

    public function testUnregisterPromptRemovesRegisteredPrompt(): void
    {
        $prompt = $this->createValidPrompt('test_prompt');
        $this->registry->registerPrompt($prompt, static fn () => []);

        $this->registry->unregisterPrompt('test_prompt');

        $this->expectException(PromptNotFoundException::class);
        $this->registry->getPrompt('test_prompt');
    }

    public function testRegisterToolHandlesStringHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = 'TestClass::testMethod';

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals($handler, $toolRef->handler);
    }

    public function testRegisterToolHandlesArrayHandler(): void
    {
        $tool = $this->createValidTool('test_tool');
        $handler = ['TestClass', 'testMethod'];

        $this->registry->registerTool($tool, $handler);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals($handler, $toolRef->handler);
    }

    public function testRegisterResourceHandlesCallableHandler(): void
    {
        $resource = $this->createValidResource('test://resource');
        $handler = static fn () => 'content';

        $this->registry->registerResource($resource, $handler);

        $resourceRef = $this->registry->getResource('test://resource');
        $this->assertEquals($handler, $resourceRef->handler);
    }

    public function testMultipleRegistrationsOfSameElementWithSameType(): void
    {
        $tool1 = $this->createValidTool('test_tool');
        $tool2 = $this->createValidTool('test_tool');

        $this->registry->registerTool($tool1, static fn () => 'first');
        $this->registry->registerTool($tool2, static fn () => 'second');

        // Second registration should override the first
        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals('second', ($toolRef->handler)());
    }

    public function testExtractStructuredContentReturnsNullWhenOutputSchemaIsNull(): void
    {
        $tool = $this->createValidTool('test_tool', null);
        $this->registry->registerTool($tool, static fn () => 'result');

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertNull($toolRef->extractStructuredContent('result'));
    }

    public function testExtractStructuredContentReturnsArrayMatchingSchema(): void
    {
        $tool = $this->createValidTool('test_tool', [
            'type' => 'object',
            'properties' => [
                'param' => ['type' => 'string'],
            ],
            'required' => ['param'],
        ]);
        $this->registry->registerTool($tool, static fn () => [
            'param' => 'test',
        ]);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals([
            'param' => 'test',
        ], $toolRef->extractStructuredContent([
            'param' => 'test',
        ]));
    }

    public function testExtractStructuredContentReturnsArrayDirectlyForAdditionalProperties(): void
    {
        $tool = $this->createValidTool('test_tool', [
            'type' => 'object',
            'additionalProperties' => true,
        ]);
        $this->registry->registerTool($tool, static fn () => ['success' => true, 'message' => 'done']);

        $toolRef = $this->registry->getTool('test_tool');
        $this->assertEquals(['success' => true, 'message' => 'done'], $toolRef->extractStructuredContent(['success' => true, 'message' => 'done']));
    }

    /**
     * @dataProvider provideHandshakeVersions
     */
    public function testExtractStructuredContentDropsListResultsBeforeSep2106(?ProtocolVersion $version): void
    {
        // Up to 2025-11-25 a PHP list serializes to something `structuredContent`
        // does not allow — a JSON array — and `Tool::fromArray()` enforces the
        // matching rule by rejecting any outputSchema whose type is not "object".
        $outputSchema = [
            'type' => 'object',
            'properties' => [
                'foo' => ['type' => 'string'],
            ],
            'required' => ['foo'],
        ];

        $tool = $this->createValidTool('list_static_data', $outputSchema);
        $toolReturnValue = [
            ['foo' => 'bar'],
            ['foo' => 'bar'],
        ];

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('list_static_data');
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, $version));
    }

    /**
     * The revision is optional, and omitting it has to keep the strict rule: it is
     * what every revision reachable through the `initialize` handshake requires.
     *
     * @return iterable<string, array{?ProtocolVersion}>
     */
    public static function provideHandshakeVersions(): iterable
    {
        yield 'unspecified' => [null];

        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield $version->value => [$version];
        }
    }

    public function testExtractStructuredContentKeepsListResultsFromSep2106On(): void
    {
        // SEP-2106 widened `structuredContent` to any JSON value conforming to
        // `outputSchema`, and `outputSchema` to any JSON Schema 2020-12 — the spec's
        // own example of a legal result is a list of records like this one.
        $outputSchema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['foo' => ['type' => 'string']],
            ],
        ];

        $tool = $this->createValidTool('list_static_data', $outputSchema);
        $toolReturnValue = [
            ['foo' => 'bar'],
            ['foo' => 'baz'],
        ];

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('list_static_data');
        $this->assertSame($toolReturnValue, $toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2026_07_28));
    }

    public function testExtractStructuredContentDropsListOfScalarsBeforeSep2106(): void
    {
        $tool = $this->createValidTool('list_ids', null);
        $toolReturnValue = ['101', '102', '103'];

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('list_ids');
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2025_11_25));
        $this->assertSame($toolReturnValue, $toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2026_07_28));
    }

    public function testExtractStructuredContentEncodesObjectResults(): void
    {
        $tool = $this->createValidTool('describe_thing', null);
        $toolReturnValue = new \stdClass();
        $toolReturnValue->id = 1;
        $toolReturnValue->label = 'thing';

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('describe_thing');
        $this->assertSame(['id' => 1, 'label' => 'thing'], $toolRef->extractStructuredContent($toolReturnValue));
    }

    public function testExtractStructuredContentAppliesTheListRuleToObjectResultsToo(): void
    {
        // `JsonSerializable` can hand back a list just as a raw array result can,
        // and it is no more — and no less — valid for having come from an object.
        $tool = $this->createValidTool('list_things', null);
        $toolReturnValue = new class implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return [['id' => 1], ['id' => 2]];
            }
        };

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('list_things');
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2025_11_25));
        $this->assertSame([['id' => 1], ['id' => 2]], $toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2026_07_28));
    }

    public function testExtractStructuredContentReturnsNullForObjectsSerializingToAScalar(): void
    {
        // SEP-2106 allows a scalar `structuredContent`, but `CallToolResult` types
        // the field as `?array` and cannot carry one — so it is dropped in every
        // revision until that type widens.
        $tool = $this->createValidTool('count_things', null);
        $toolReturnValue = new class implements \JsonSerializable {
            public function jsonSerialize(): int
            {
                return 42;
            }
        };

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('count_things');
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2025_11_25));
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2026_07_28));
    }

    public function testExtractStructuredContentReturnsNullForArrayOfContentItems(): void
    {
        // Unlike the list rule, this one is revision-independent: the items are
        // already carried in the result's `content`.
        $tool = $this->createValidTool('lookup_thing', null);
        $toolReturnValue = [
            new TextContent('Found it.'),
            new ResourceLink(uri: 'thing://1', name: 'thing_1'),
        ];

        $this->registry->registerTool($tool, static fn () => $toolReturnValue);

        $toolRef = $this->registry->getTool('lookup_thing');
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2025_11_25));
        $this->assertNull($toolRef->extractStructuredContent($toolReturnValue, ProtocolVersion::V2026_07_28));
    }

    public function testConfiguredLoaderIsNotRunUntilFirstRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->never())->method('load');

        // Constructing (and registering) must not trigger the loader.
        $registry = new Registry(null, $this->logger, loader: $loader);
        $registry->registerTool($this->createValidTool('manual'), 'handler');
    }

    public function testConfiguredLoaderRunsOnFirstReadAndPopulatesTheRegistry(): void
    {
        $loader = $this->toolLoader($this->createValidTool('loaded'));
        $registry = new Registry(null, $this->logger, loader: $loader);

        $this->assertTrue($registry->hasTools());
        $this->assertArrayHasKey('loaded', $registry->getTools()->references);
    }

    public function testConfiguredLoaderRunsExactlyOnceAcrossManyReads(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $registry = new Registry(null, $this->logger, loader: $loader);
        $registry->hasTools();
        $registry->getTools();
        $registry->hasResources();
        $registry->getPrompts();
    }

    public function testRuntimeRegistrationsSurviveTheDeferredLoad(): void
    {
        $loader = $this->toolLoader($this->createValidTool('loaded'));
        $registry = new Registry(null, $this->logger, loader: $loader);
        // Registered before the first read; the deferred load must be additive, not replacing.
        $registry->registerTool($this->createValidTool('runtime'), 'handler');

        $tools = $registry->getTools()->references;
        $this->assertArrayHasKey('runtime', $tools);
        $this->assertArrayHasKey('loaded', $tools);
    }

    public function testConfiguredLoaderRetriesAfterAFailedLoad(): void
    {
        $tool = $this->createValidTool('loaded');
        $loader = new class($tool) implements LoaderInterface {
            private int $calls = 0;

            public function __construct(private readonly Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                ++$this->calls;
                if (1 === $this->calls) {
                    throw new \RuntimeException('data source not ready');
                }

                $registry->registerTool($this->tool, 'handler');
            }
        };

        $registry = new Registry(null, $this->logger, loader: $loader);

        try {
            $registry->hasTools();
            $this->fail('Expected the first load to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('data source not ready', $e->getMessage());
        }

        $this->assertArrayHasKey('loaded', $registry->getTools()->references);
    }

    public function testListChangedEventsAreSuppressedDuringTheDeferredLoad(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $loader = new class($this->createValidTool('loaded'), $this->createValidResource('file:///loaded'), $this->createValidResourceTemplate('file:///loaded/{id}'), $this->createValidPrompt('loaded_prompt')) implements LoaderInterface {
            public function __construct(
                private readonly Tool $tool,
                private readonly ResourceDefinition $resource,
                private readonly ResourceTemplate $template,
                private readonly Prompt $prompt,
            ) {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, 'handler');
                $registry->registerResource($this->resource, 'handler');
                $registry->registerResourceTemplate($this->template, 'handler');
                $registry->registerPrompt($this->prompt, 'handler');
            }
        };

        $registry = new Registry($eventDispatcher, $this->logger, loader: $loader);

        $this->assertTrue($registry->hasTools());
        $this->assertTrue($registry->hasPrompts());
    }

    public function testListChangedEventsAreStillDispatchedForRuntimeRegistrations(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ToolListChangedEvent::class))
            ->willReturnArgument(0);

        $loader = $this->toolLoader($this->createValidTool('loaded'));
        $registry = new Registry($eventDispatcher, $this->logger, loader: $loader);
        $registry->load();

        $registry->registerTool($this->createValidTool('runtime'), 'handler');
    }

    public function testLoadRunsTheConfiguredLoaderEagerly(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $registry = new Registry(null, $this->logger, loader: $loader);
        $registry->load();
    }

    public function testLoadIsANoopWithoutAConfiguredLoader(): void
    {
        $registry = new Registry(null, $this->logger);
        $registry->load();

        $this->assertFalse($registry->hasTools());
    }

    private function toolLoader(Tool $tool): LoaderInterface
    {
        return new class($tool) implements LoaderInterface {
            public function __construct(private readonly Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, 'handler');
            }
        };
    }

    public function testExtractStructuredContentKeepsAScalarFromSep2106On(): void
    {
        $tool = $this->createValidTool('test_tool', ['type' => 'string']);
        $this->registry->registerTool($tool, static fn () => 'sunny');

        $toolRef = $this->registry->getTool('test_tool');

        $this->assertSame('sunny', $toolRef->extractStructuredContent('sunny', ProtocolVersion::V2026_07_28));
        $this->assertNull($toolRef->extractStructuredContent('sunny', ProtocolVersion::V2025_11_25));
    }

    public function testExtractStructuredContentDropsAScalarWithoutAnOutputSchema(): void
    {
        $tool = $this->createValidTool('test_tool', null);
        $this->registry->registerTool($tool, static fn () => 'sunny');

        $toolRef = $this->registry->getTool('test_tool');

        // Without a declared schema the value is already carried in `content`;
        // advertising it as structured too would duplicate it.
        $this->assertNull($toolRef->extractStructuredContent('sunny', ProtocolVersion::V2026_07_28));
    }

    public function testExtractStructuredContentKeepsAJsonSerializableScalarFromSep2106On(): void
    {
        $tool = $this->createValidTool('test_tool', ['type' => 'number']);
        $result = new class implements \JsonSerializable {
            public function jsonSerialize(): float
            {
                return 22.5;
            }
        };
        $this->registry->registerTool($tool, static fn () => $result);

        $toolRef = $this->registry->getTool('test_tool');

        $this->assertSame(22.5, $toolRef->extractStructuredContent($result, ProtocolVersion::V2026_07_28));
        $this->assertNull($toolRef->extractStructuredContent($result, ProtocolVersion::V2025_11_25));
    }

    private function createValidTool(string $name, ?array $outputSchema = null): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'param' => ['type' => 'string'],
                ],
                'required' => null,
            ],
            description: "Test tool: {$name}",
            annotations: null,
            icons: null,
            meta: null,
            outputSchema: $outputSchema
        );
    }

    private function createValidResource(string $uri): ResourceDefinition
    {
        return new ResourceDefinition(
            uri: $uri,
            name: 'test_resource',
            description: 'Test resource',
            mimeType: 'text/plain',
        );
    }

    private function createValidResourceTemplate(string $uriTemplate): ResourceTemplate
    {
        return new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: 'test_template',
            description: 'Test resource template',
            mimeType: 'text/plain',
        );
    }

    private function createValidPrompt(string $name): Prompt
    {
        return new Prompt(
            name: $name,
            description: "Test prompt: {$name}",
            arguments: [],
        );
    }
}
