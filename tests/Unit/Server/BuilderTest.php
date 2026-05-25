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

use Mcp\Capability\Discovery\PropertyDescriber\DateTimePropertyDescriber;
use Mcp\Capability\Discovery\PropertyDescriberInterface;
use Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\ListToolsHandler;
use Mcp\Server\Session\SessionInterface;
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

    #[TestDox('addPropertyDescriber() applies to generated tool input schemas')]
    public function testAddPropertyDescriberAppliesToGeneratedToolSchema(): void
    {
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addPropertyDescriber(new DateTimePropertyDescriber())
            ->addTool(static fn (\DateTimeImmutable $when): string => 'ok', name: 'dt_tool', description: 'A tool')
            ->build();

        $schema = $this->toolInputSchema($server, 'dt_tool');

        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $schema['properties']['when']);
    }

    #[TestDox('addPropertyDescriber() consults describers in registration order (first match wins)')]
    public function testAddPropertyDescriberConsultsInRegistrationOrder(): void
    {
        $custom = new class implements PropertyDescriberInterface {
            public static function supportedClass(): string
            {
                return \DateTimeInterface::class;
            }

            public function describe(): array
            {
                return ['type' => 'string', 'format' => 'custom'];
            }
        };

        // Registered before the default, so the custom describer wins for DateTime types.
        $server = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->addPropertyDescriber($custom)
            ->addPropertyDescriber(new DateTimePropertyDescriber())
            ->addTool(static fn (\DateTimeImmutable $when): string => 'ok', name: 'dt_tool', description: 'A tool')
            ->build();

        $schema = $this->toolInputSchema($server, 'dt_tool');

        $this->assertSame(['type' => 'string', 'format' => 'custom'], $schema['properties']['when']);
    }

    #[TestDox('addPropertyDescriber() cannot be combined with setSchemaGenerator()')]
    public function testAddPropertyDescriberConflictsWithExplicitGenerator(): void
    {
        $builder = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setSchemaGenerator($this->createStub(SchemaGeneratorInterface::class))
            ->addPropertyDescriber(new DateTimePropertyDescriber());

        $this->expectException(InvalidArgumentException::class);

        $builder->build();
    }

    /**
     * @return array<string, mixed>
     */
    private function toolInputSchema(Server $server, string $toolName): array
    {
        $protocol = (new \ReflectionClass($server))->getProperty('protocol')->getValue($server);
        $requestHandlers = (new \ReflectionClass($protocol))->getProperty('requestHandlers')->getValue($protocol);

        foreach ($requestHandlers as $handler) {
            if ($handler instanceof ListToolsHandler) {
                $request = (new ListToolsRequest())->withId('test-1');
                $response = $handler->handle($request, $this->createStub(SessionInterface::class));
                \assert($response->result instanceof ListToolsResult);

                foreach ($response->result->tools as $tool) {
                    if ($tool->name === $toolName) {
                        return $tool->inputSchema;
                    }
                }

                $this->fail(\sprintf('Tool "%s" not found in tools/list result', $toolName));
            }
        }

        $this->fail('ListToolsHandler not found in request handlers');
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
}
