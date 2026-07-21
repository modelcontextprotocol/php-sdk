<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry\Loader;

use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ExplicitElementLoaderTest extends TestCase
{
    public function testAddToolRegistersDefinitionAndDispatchesToHandler(): void
    {
        $tool = new Tool(
            name: 'demo',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']], 'required' => []],
            description: 'A demo tool',
            annotations: null,
        );
        $handler = new class implements ToolHandlerInterface {
            /** @var array<string, mixed>|null */
            public ?array $receivedArguments = null;
            public ?ClientGateway $receivedGateway = null;

            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                $this->receivedArguments = $arguments;
                $this->receivedGateway = $gateway;

                return 'tool-ok';
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($tool, $handler));

        $reference = $registry->getTool('demo');
        $this->assertSame('demo', $reference->tool->name);
        $this->assertSame('A demo tool', $reference->tool->description);
        $this->assertSame(['type' => 'object', 'properties' => ['foo' => ['type' => 'string']], 'required' => []], $reference->tool->inputSchema);

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'foo' => 'bar',
        ]);

        $this->assertSame('tool-ok', $result);
        $this->assertSame(['foo' => 'bar'], $handler->receivedArguments);
        $this->assertInstanceOf(ClientGateway::class, $handler->receivedGateway);
    }

    public function testAddResourceRegistersDefinitionAndDispatchesToHandler(): void
    {
        $resource = new ResourceDefinition(
            uri: 'config://demo',
            name: 'demo',
            description: 'A demo resource',
            mimeType: 'text/plain',
        );
        $handler = new class implements ResourceHandlerInterface {
            public ?string $receivedUri = null;
            public ?ClientGateway $receivedGateway = null;

            public function read(string $uri, ClientGateway $gateway): mixed
            {
                $this->receivedUri = $uri;
                $this->receivedGateway = $gateway;

                return ['contents' => 'resource-ok'];
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($resource, $handler));

        $reference = $registry->getResource('config://demo', false);
        $this->assertSame('config://demo', $reference->resource->uri);
        $this->assertSame('demo', $reference->resource->name);
        $this->assertSame('text/plain', $reference->resource->mimeType);

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'uri' => 'config://demo',
        ]);

        $this->assertSame(['contents' => 'resource-ok'], $result);
        $this->assertSame('config://demo', $handler->receivedUri);
        $this->assertInstanceOf(ClientGateway::class, $handler->receivedGateway);
    }

    public function testAddResourceTemplateRegistersDefinitionAndDispatchesToHandler(): void
    {
        $template = new ResourceTemplate(
            uriTemplate: 'config://{key}',
            name: 'config_template',
            description: 'A demo template',
        );
        $handler = new class implements ResourceTemplateHandlerInterface {
            public ?string $receivedUri = null;
            /** @var array<string, string>|null */
            public ?array $receivedVariables = null;
            public ?ClientGateway $receivedGateway = null;

            public function read(string $uri, array $variables, ClientGateway $gateway): mixed
            {
                $this->receivedUri = $uri;
                $this->receivedVariables = $variables;
                $this->receivedGateway = $gateway;

                return ['contents' => 'template-ok'];
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($template, $handler));

        $reference = $registry->getResourceTemplate('config://{key}');
        $this->assertSame('config://{key}', $reference->resourceTemplate->uriTemplate);
        $this->assertSame('config_template', $reference->resourceTemplate->name);

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'uri' => 'config://abc',
            'key' => 'abc',
        ]);

        $this->assertSame(['contents' => 'template-ok'], $result);
        $this->assertSame('config://abc', $handler->receivedUri);
        $this->assertSame(['key' => 'abc'], $handler->receivedVariables);
        $this->assertInstanceOf(ClientGateway::class, $handler->receivedGateway);
    }

    public function testAddPromptRegistersDefinitionAndDispatchesToHandler(): void
    {
        $prompt = new Prompt(
            name: 'demo_prompt',
            title: null,
            description: 'A demo prompt',
        );
        $handler = new class implements PromptHandlerInterface {
            /** @var array<string, mixed>|null */
            public ?array $receivedArguments = null;
            public ?ClientGateway $receivedGateway = null;

            public function get(array $arguments, ClientGateway $gateway): mixed
            {
                $this->receivedArguments = $arguments;
                $this->receivedGateway = $gateway;

                return 'prompt-ok';
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($prompt, $handler));

        $reference = $registry->getPrompt('demo_prompt');
        $this->assertSame('demo_prompt', $reference->prompt->name);
        $this->assertSame('A demo prompt', $reference->prompt->description);

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $result = (new ReferenceHandler())->handle($reference, [
            '_session' => $session,
            '_request' => new \stdClass(),
            'topic' => 'php',
        ]);

        $this->assertSame('prompt-ok', $result);
        $this->assertSame(['topic' => 'php'], $handler->receivedArguments);
        $this->assertInstanceOf(ClientGateway::class, $handler->receivedGateway);
    }

    public function testAddPromptForwardsCompletionProvidersToRegistry(): void
    {
        $prompt = new Prompt(name: 'greet', title: null, description: null);
        $handler = new class implements PromptHandlerInterface {
            public function get(array $arguments, ClientGateway $gateway): mixed
            {
                return null;
            }
        };
        $provider = new class implements ProviderInterface {
            public function getCompletions(string $currentValue): array
            {
                return ['alice', 'bob'];
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add(
            $prompt,
            $handler,
            ['name' => $provider],
        ));

        $reference = $registry->getPrompt('greet');
        $this->assertSame(['name' => $provider], $reference->completionProviders);
    }

    public function testAddResourceTemplateForwardsCompletionProvidersToRegistry(): void
    {
        $template = new ResourceTemplate(
            uriTemplate: 'config://{key}',
            name: 'config_template',
            description: null,
        );
        $handler = new class implements ResourceTemplateHandlerInterface {
            public function read(string $uri, array $variables, ClientGateway $gateway): mixed
            {
                return null;
            }
        };
        $provider = new class implements ProviderInterface {
            public function getCompletions(string $currentValue): array
            {
                return ['alpha', 'beta'];
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add(
            $template,
            $handler,
            ['key' => $provider],
        ));

        $reference = $registry->getResourceTemplate('config://{key}');
        $this->assertSame(['key' => $provider], $reference->completionProviders);
    }

    public function testAddPromptWithoutCompletionProvidersDefaultsToEmptyArray(): void
    {
        $prompt = new Prompt(name: 'no_completion', title: null, description: null);
        $handler = new class implements PromptHandlerInterface {
            public function get(array $arguments, ClientGateway $gateway): mixed
            {
                return null;
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($prompt, $handler));

        $this->assertSame([], $registry->getPrompt('no_completion')->completionProviders);
    }

    public function testAddToolWithCompletionProvidersThrows(): void
    {
        $tool = new Tool(
            name: 'noop',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            description: null,
            annotations: null,
        );
        $handler = new class implements ToolHandlerInterface {
            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                return null;
            }
        };
        $provider = new class implements ProviderInterface {
            public function getCompletions(string $currentValue): array
            {
                return [];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Completion providers are only supported on Prompt and ResourceTemplate');

        Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->add($tool, $handler, ['foo' => $provider]);
    }

    public function testAddResourceWithCompletionProvidersThrows(): void
    {
        $resource = new ResourceDefinition(uri: 'config://demo', name: 'demo');
        $handler = new class implements ResourceHandlerInterface {
            public function read(string $uri, ClientGateway $gateway): mixed
            {
                return null;
            }
        };
        $provider = new class implements ProviderInterface {
            public function getCompletions(string $currentValue): array
            {
                return [];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Completion providers are only supported on Prompt and ResourceTemplate');

        Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->add($resource, $handler, ['foo' => $provider]);
    }

    public function testMismatchedDefinitionAndHandlerThrowsInvalidArgumentException(): void
    {
        $prompt = new Prompt(name: 'mismatched', title: null, description: null);
        $toolHandler = new class implements ToolHandlerInterface {
            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                return null;
            }
        };

        $this->expectException(InvalidArgumentException::class);

        Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->add($prompt, $toolHandler);
    }

    public function testLoaderRegistersClosuresRatherThanHandlerInstances(): void
    {
        $tool = new Tool(
            name: 'closure_check',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            description: null,
            annotations: null,
        );
        $handler = new class implements ToolHandlerInterface {
            public function execute(array $arguments, ClientGateway $gateway): mixed
            {
                return null;
            }
        };

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->add($tool, $handler));

        $reference = $registry->getTool('closure_check');
        $this->assertInstanceOf(\Closure::class, $reference->handler);
    }

    /**
     * @param callable(Server\Builder): Server\Builder $configure
     */
    private function buildAndGetRegistry(callable $configure): RegistryInterface
    {
        // A caller-supplied registry is loaded eagerly at build, so it is populated once build() returns.
        $registry = new Registry();
        $configure(Server::builder()->setServerInfo('test', '1.0.0')->setRegistry($registry))->build();

        return $registry;
    }
}
