<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\LogicException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use Mcp\Tests\Unit\Server\Extension\ThingExtension;
use Mcp\Tests\Unit\Server\Extension\ThingListHandler;
use Mcp\Tests\Unit\Server\Extension\ThingListRequest;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    #[TestDox('setReferenceHandler() returns the builder for fluent chaining')]
    public function testSetReferenceHandlerReturnsSelf(): void
    {
        $referenceHandler = $this->createStub(ReferenceHandlerInterface::class);

        $builder = Server::builder();
        $result = $builder->setReferenceHandler($referenceHandler);

        $this->assertSame($builder, $result);
    }

    #[TestDox('build() succeeds with a custom ReferenceHandler')]
    public function testBuildWithCustomReferenceHandler(): void
    {
        $referenceHandler = $this->createStub(ReferenceHandlerInterface::class);

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setReferenceHandler($referenceHandler)
            ->build();

        $this->assertInstanceOf(Server::class, $server);
    }

    #[TestDox('build() succeeds without a custom ReferenceHandler (uses default)')]
    public function testBuildWithoutCustomReferenceHandler(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->build();

        $this->assertInstanceOf(Server::class, $server);
    }

    #[TestDox('Custom ReferenceHandler is used when calling a tool')]
    public function testCustomReferenceHandlerIsUsedForToolCalls(): void
    {
        $referenceHandler = $this->createMock(ReferenceHandlerInterface::class);
        $referenceHandler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(static function (ElementReference $reference, array $arguments): string {
                return 'intercepted';
            });

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setReferenceHandler($referenceHandler)
            ->addTool(static fn (): string => 'original', name: 'test_tool', description: 'A test tool')
            ->build();

        $result = $this->callTool($server, 'test_tool');

        $this->assertSame('intercepted', $result);
    }

    #[TestDox('A pre-built instance handler with constructor dependencies is registered and invoked on that instance')]
    public function testPreBuiltInstanceHandlerIsInvokedOnTheGivenInstance(): void
    {
        // The handler's constructor requires an argument the container-less
        // `new $className()` fallback can never satisfy. If the tool call
        // succeeds, it can only be because the pre-built instance — carrying
        // its injected dependency — was the one invoked.
        $handler = new GreetingService('World');

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addTool(handler: [$handler, 'greet'], name: 'greet', description: 'Greets using the injected name')
            ->build();

        $result = $this->callTool($server, 'greet');

        $this->assertSame('Hello, World', $result);
    }

    #[TestDox('enableExtension() registers an extension and announces its capability payload')]
    public function testEnableExtensionRegistersExtension(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->enableExtension(new McpApps())
            ->build();

        $capabilities = $this->extractServerCapabilities($server);

        $this->assertNotNull($capabilities->extensions);
        $this->assertArrayHasKey(McpApps::EXTENSION_ID, $capabilities->extensions);
        $this->assertSame(['mimeTypes' => [McpApps::MIME_TYPE]], $capabilities->extensions[McpApps::EXTENSION_ID]);
    }

    #[TestDox('enableExtension() throws when the same extension is enabled twice')]
    public function testEnableExtensionRejectsDuplicate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(McpApps::EXTENSION_ID);

        Server::builder()->enableExtension(new McpApps(), new McpApps());
    }

    #[TestDox('enableExtension() extensions are merged into capabilities set via setCapabilities()')]
    public function testEnableExtensionMergesIntoCustomCapabilities(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setCapabilities(new ServerCapabilities(tools: true))
            ->enableExtension(new McpApps())
            ->build();

        $capabilities = $this->extractServerCapabilities($server);

        $this->assertNotNull($capabilities->extensions);
        $this->assertArrayHasKey(McpApps::EXTENSION_ID, $capabilities->extensions);
    }

    #[TestDox('build() advertises tools capability for a pre-populated registry set via setRegistry()')]
    public function testBuildAdvertisesToolsForPreloadedCustomRegistry(): void
    {
        $registry = new Registry();
        $registry->registerTool(
            new Tool(name: 'test_tool', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: 'A test tool', annotations: null),
            static fn (): string => 'result',
        );

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setRegistry($registry)
            ->build();

        $capabilities = $this->extractServerCapabilities($server);

        $this->assertTrue($capabilities->tools);
    }

    #[TestDox('setLazyLoading() returns the builder for fluent chaining')]
    public function testSetLazyLoadingReturnsSelf(): void
    {
        $builder = Server::builder();

        $this->assertSame($builder, $builder->setLazyLoading(false));
    }

    #[TestDox('One builder configuration is resolved once, however many dispatchers come out of it')]
    public function testAssembledPartsAreSharedAcrossEras(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        // Twice would mean two registries behind one endpoint, and a change
        // made through one of them invisible to the other.
        $loader->expects($this->once())->method('load');

        $builder = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setLazyLoading(false)
            ->addLoader($loader);

        $builder->build();
        $builder->buildStateless();
    }

    #[TestDox('A built server carries a dispatcher for each era, so one endpoint serves both')]
    public function testBuildProducesBothEras(): void
    {
        $server = Server::builder()->setServerInfo('test', '1.0.0')->build();

        $this->assertInstanceOf(StatelessProtocol::class, self::statelessProtocol($server));
    }

    #[TestDox('withoutModernEra() leaves the server with the handshake era alone')]
    public function testWithoutModernEra(): void
    {
        $builder = Server::builder()->setServerInfo('test', '1.0.0');

        $this->assertSame($builder, $builder->withoutModernEra());
        $this->assertNull(self::statelessProtocol($builder->build()));
    }

    #[TestDox('setModernVersions() narrows what the modern leg answers for')]
    public function testSetModernVersions(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setModernVersions([ProtocolVersion::V2026_07_28])
            ->build();

        $this->assertSame([ProtocolVersion::V2026_07_28], self::statelessProtocol($server)?->supportedVersions());
    }

    #[TestDox('setModernVersions() rejects a handshake-era revision, which the classifier would never route there')]
    public function testSetModernVersionsRejectsHandshakeRevision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ProtocolVersion::V2025_11_25->value);

        Server::builder()->setModernVersions([ProtocolVersion::V2025_11_25]);
    }

    private static function statelessProtocol(Server $server): ?StatelessProtocol
    {
        $property = new \ReflectionProperty(Server::class, 'statelessProtocol');

        return $property->getValue($server);
    }

    #[TestDox('An extension identifier must be a valid _meta prefix')]
    public function testEnableExtensionRejectsUnprefixedIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no prefix');

        Server::builder()->enableExtension(new ThingExtension('things'));
    }

    #[TestDox('A method-providing extension contributes the message classes its methods decode into')]
    public function testEnableExtensionRegistersItsMessages(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->enableExtension(new ThingExtension())
            ->build();

        $factory = (new \ReflectionProperty(Protocol::class, 'messageFactory'))
            ->getValue((new \ReflectionProperty(Server::class, 'protocol'))->getValue($server));

        $decoded = $factory->create('{"jsonrpc":"2.0","id":1,"method":"com.example/things.list"}');

        $this->assertInstanceOf(ThingListRequest::class, $decoded[0]);
    }

    #[TestDox('enableExtension() throws when two enabled extensions define the same RPC method')]
    public function testEnableExtensionRejectsClaimedMethod(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('com.example/things.list');

        Server::builder()->enableExtension(new ThingExtension('com.example/things-a'), new ThingExtension('com.example/things-b'));
    }

    #[TestDox('A method-providing extension contributes the handlers serving its methods')]
    public function testEnableExtensionRegistersItsHandlers(): void
    {
        $builder = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->enableExtension(new ThingExtension());

        $handlers = (new \ReflectionProperty(Builder::class, 'requestHandlers'))->getValue($builder);

        $this->assertContainsOnlyInstancesOf(ThingListHandler::class, $handlers);
    }

    #[TestDox('Lazy loading (default) advertises tools from configured sources without running loaders')]
    public function testLazyLoadingAdvertisesFromConfiguredSourcesWithoutLoading(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->never())->method('load');

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addLoader($loader)
            ->build();

        $capabilities = $this->extractServerCapabilities($server);

        // A custom loader is opaque, so its presence advertises tools even though it never ran.
        $this->assertTrue($capabilities->tools);
    }

    #[TestDox('Eager loading runs the loaders at build time and advertises from the loaded registry')]
    public function testEagerLoadingAdvertisesFromLoadedRegistry(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setLazyLoading(false)
            ->addLoader($loader)
            ->build();

        $capabilities = $this->extractServerCapabilities($server);

        // The loader ran but registered nothing, so the loaded registry advertises no tools.
        $this->assertFalse($capabilities->tools);
    }

    #[TestDox('setServerInfo() forwards the title to the advertised serverInfo')]
    public function testSetServerInfoForwardsTitle(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0', 'A test server', null, 'https://example.com', 'Test Server')
            ->build();

        $serverInfo = $this->extractServerInfo($server);

        $this->assertSame('test', $serverInfo->name);
        $this->assertSame('A test server', $serverInfo->description);
        $this->assertSame('https://example.com', $serverInfo->websiteUrl);
        $this->assertSame('Test Server', $serverInfo->title);
    }

    #[TestDox('setServerInfo() leaves the title absent when it is not given')]
    public function testSetServerInfoTitleIsOptional(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->build();

        $this->assertNull($this->extractServerInfo($server)->title);
    }

    private function extractServerInfo(Server $server): Implementation
    {
        $protocol = (new \ReflectionClass($server))->getProperty('protocol')->getValue($server);
        $requestHandlers = (new \ReflectionClass($protocol))->getProperty('requestHandlers')->getValue($protocol);

        foreach ($requestHandlers as $handler) {
            if ($handler instanceof InitializeHandler) {
                return $handler->configuration->serverInfo;
            }
        }

        $this->fail('InitializeHandler not found in request handlers');
    }

    private function extractServerCapabilities(Server $server): ServerCapabilities
    {
        $protocol = (new \ReflectionClass($server))->getProperty('protocol')->getValue($server);
        $requestHandlers = (new \ReflectionClass($protocol))->getProperty('requestHandlers')->getValue($protocol);

        foreach ($requestHandlers as $handler) {
            if ($handler instanceof InitializeHandler) {
                return $handler->configuration->capabilities;
            }
        }

        $this->fail('InitializeHandler not found in request handlers');
    }

    private function callTool(Server $server, string $toolName): mixed
    {
        $protocol = (new \ReflectionClass($server))->getProperty('protocol')->getValue($server);
        $requestHandlers = (new \ReflectionClass($protocol))->getProperty('requestHandlers')->getValue($protocol);

        foreach ($requestHandlers as $handler) {
            if ($handler instanceof CallToolHandler) {
                $request = CallToolRequest::fromArray([
                    'jsonrpc' => '2.0',
                    'method' => 'tools/call',
                    'id' => 'test-1',
                    'params' => ['name' => $toolName, 'arguments' => []],
                ]);
                $session = $this->createStub(SessionInterface::class);

                $response = $handler->handle($request, $session);

                if ($response instanceof Response) {
                    $content = $response->result->content[0] ?? null;

                    return $content instanceof TextContent ? $content->text : null;
                }

                $this->fail('Expected Response, got '.$response::class);
            }
        }

        $this->fail('CallToolHandler not found in request handlers');
    }

    public function testBuildingWithASuppliedRegistryDoesNotAnnounceTheLoadAsAChange(): void
    {
        // Otherwise every build publishes one list_changed per element, for no change.
        $bus = new InMemoryNotificationBus();
        $registry = new Registry(new PublishingEventDispatcher($bus));

        Server::builder()
            ->setRegistry($registry)
            ->setNotificationBus($bus)
            ->addTool(static fn (): string => 'ok', 'alpha')
            ->addTool(static fn (): string => 'ok', 'beta')
            ->build();

        $this->assertSame(0, $bus->cursor());
        $this->assertTrue($registry->hasTool('alpha'));
        $this->assertTrue($registry->hasTool('beta'));

        // A real change after the build is still published.
        $registry->unregisterTool('alpha');

        $this->assertSame(1, $bus->cursor());
    }

    public function testAThirdPartyRegistryIsStillLoadedThroughThePlainLoader(): void
    {
        $registry = $this->createMock(RegistryInterface::class);
        $registry->expects($this->once())
            ->method('registerTool')
            ->with($this->callback(static fn (Tool $tool): bool => 'alpha' === $tool->name));

        Server::builder()
            ->setRegistry($registry)
            ->addTool(static fn (): string => 'ok', 'alpha')
            ->build();
    }
}

/**
 * A handler whose constructor dependency cannot be satisfied by the
 * container-less `new $className()` fallback, so it must be registered as a
 * pre-built instance.
 */
final class GreetingService
{
    public function __construct(private string $name)
    {
    }

    public function greet(): string
    {
        return 'Hello, '.$this->name;
    }
}
