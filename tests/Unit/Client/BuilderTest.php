<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client\Builder;
use Mcp\Client\Client;
use Mcp\Client\Configuration;
use Mcp\Client\Exception\LogicException;
use Mcp\Client\Protocol;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Exception\UnknownMethodException;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\MessageFactory;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Tests\Unit\Client\Extension\ThingExtension;
use Mcp\Tests\Unit\Client\Extension\ThingListHandler;
use Mcp\Tests\Unit\Client\Extension\ThingSeenHandler;
use Mcp\Tests\Unit\Server\Extension\ThingListRequest;
use Mcp\Tests\Unit\Server\Extension\ThingSeenNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase
{
    #[TestDox('setClientInfo() forwards the title to the advertised clientInfo')]
    public function testSetClientInfoForwardsTitle(): void
    {
        $client = Client::builder()
            ->setClientInfo('my-client', '1.0.0', 'A test client', 'My Client')
            ->build();

        $clientInfo = $this->extractConfiguration($client)->clientInfo;

        $this->assertSame('my-client', $clientInfo->name);
        $this->assertSame('1.0.0', $clientInfo->version);
        $this->assertSame('A test client', $clientInfo->description);
        $this->assertSame('My Client', $clientInfo->title);
    }

    #[TestDox('setClientInfo() leaves the title absent when it is not given')]
    public function testSetClientInfoTitleIsOptional(): void
    {
        $client = Client::builder()
            ->setClientInfo('my-client', '1.0.0')
            ->build();

        $this->assertNull($this->extractConfiguration($client)->clientInfo->title);
    }

    #[TestDox('enableExtension() announces the extension under capabilities.extensions')]
    public function testEnableExtensionAdvertisesExtension(): void
    {
        $client = Client::builder()
            ->enableExtension(new McpApps())
            ->build();

        $capabilities = $this->extractConfiguration($client)->capabilities;

        $this->assertSame([McpApps::EXTENSION_ID => ['mimeTypes' => [McpApps::MIME_TYPE]]], $capabilities->extensions);
    }

    #[TestDox('enableExtension() extensions are merged into capabilities set via setCapabilities()')]
    public function testEnableExtensionMergesIntoCustomCapabilities(): void
    {
        $client = Client::builder()
            ->setCapabilities(new ClientCapabilities(elicitation: true, extensions: ['io.example/other' => []]))
            ->enableExtension(new McpApps())
            ->build();

        $capabilities = $this->extractConfiguration($client)->capabilities;

        $this->assertTrue($capabilities->elicitation);
        $this->assertSame(['io.example/other', McpApps::EXTENSION_ID], array_keys($capabilities->extensions ?? []));
    }

    #[TestDox('enableExtension() throws when the same extension is enabled twice')]
    public function testEnableExtensionRejectsDuplicate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(McpApps::EXTENSION_ID);

        Client::builder()->enableExtension(new McpApps(), new McpApps());
    }

    #[TestDox('An extension contributes the message classes its methods decode into')]
    public function testEnableExtensionRegistersItsMessages(): void
    {
        $client = Client::builder()->enableExtension(new ThingExtension())->build();

        $this->assertInstanceOf(ThingListRequest::class, $this->messageFactory($client)->create('{"jsonrpc":"2.0","id":1,"method":"com.example/things.list"}'));
    }

    #[TestDox('A client extension contributes the handlers its requests and notifications reach')]
    public function testEnableExtensionRegistersItsHandlers(): void
    {
        $builder = Client::builder()->enableExtension(new ThingExtension());

        $this->assertEquals([new ThingListHandler()], (new \ReflectionProperty(Builder::class, 'requestHandlers'))->getValue($builder));
        $this->assertEquals([new ThingSeenHandler()], (new \ReflectionProperty(Builder::class, 'notificationHandlers'))->getValue($builder));
    }

    /**
     * @param class-string<Request>|class-string<Notification> $message
     */
    #[DataProvider('provideCoreMessages')]
    #[TestDox('enableExtension() throws when an extension defines a core method')]
    public function testEnableExtensionRejectsCoreMethod(string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('Extension "com.example/things" cannot define "%s"', $message::getMethod()));

        Client::builder()->enableExtension(new ThingExtension(messages: [$message]));
    }

    public static function provideCoreMessages(): iterable
    {
        yield 'request' => [CreateSamplingMessageRequest::class];
        yield 'notification' => [CancelledNotification::class];
    }

    #[TestDox('enableExtension() throws when two enabled extensions define the same RPC method')]
    public function testEnableExtensionRejectsClaimedMethod(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('com.example/things.list');

        Client::builder()->enableExtension(new ThingExtension('com.example/things-a'), new ThingExtension('com.example/things-b'));
    }

    #[TestDox('A rejected extension leaves none of its contributions installed')]
    public function testRejectedExtensionLeavesNothingInstalled(): void
    {
        $builder = Client::builder()->enableExtension(new ThingExtension('com.example/seen', [ThingSeenNotification::class]));

        try {
            $builder->enableExtension(new ThingExtension('com.example/things', [ThingListRequest::class, ThingSeenNotification::class]));
            $this->fail('The extension should have been rejected.');
        } catch (LogicException) {
        }

        $client = $builder->build();

        $this->assertSame(['com.example/seen'], array_keys($this->extractConfiguration($client)->capabilities->extensions ?? []));
        $this->assertCount(1, (new \ReflectionProperty(Builder::class, 'requestHandlers'))->getValue($builder));

        $this->expectException(UnknownMethodException::class);
        $this->messageFactory($client)->create('{"jsonrpc":"2.0","id":1,"method":"com.example/things.list"}');
    }

    #[TestDox('build() throws when an enabled extension requires one that is not enabled')]
    public function testBuildRejectsExtensionWithMissingDependency(): void
    {
        $builder = Client::builder()->enableExtension(new ThingExtension(requires: ['com.example/catalog']));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Extension "com.example/things" requires extension "com.example/catalog", which is not enabled.');

        $builder->build();
    }

    #[TestDox('An extension may be enabled after the extension that requires it')]
    public function testDependencyMayBeEnabledAfterTheDependent(): void
    {
        $client = Client::builder()
            ->enableExtension(new ThingExtension(requires: ['com.example/catalog']))
            ->enableExtension(new ThingExtension('com.example/catalog', []))
            ->build();

        $this->assertSame(['com.example/things', 'com.example/catalog'], array_keys($this->extractConfiguration($client)->capabilities->extensions ?? []));
    }

    private function messageFactory(Client $client): MessageFactory
    {
        $protocol = (new \ReflectionProperty(Client::class, 'protocol'))->getValue($client);

        return (new \ReflectionProperty(Protocol::class, 'messageFactory'))->getValue($protocol);
    }

    private function extractConfiguration(Client $client): Configuration
    {
        $config = (new \ReflectionClass($client))->getProperty('config')->getValue($client);
        $this->assertInstanceOf(Configuration::class, $config);

        return $config;
    }
}
