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

use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Exception\ConfigurationException;
use Mcp\Schema\PromptArgument;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\RunTimeHandlerInterface;
use Mcp\Server\Handler\RunTimeHandlerTrait;
use PHPUnit\Framework\TestCase;

final class ArrayLoaderRunTimeHandlerTest extends TestCase
{
    public function testTraitOnlyHandlerReturnsNullFromAllMetadataAccessors(): void
    {
        $handler = new TraitOnlyRuntimeHandler();

        $this->assertNull($handler->getInputSchema());
        $this->assertNull($handler->getOutputSchema());
        $this->assertNull($handler->getPromptArguments());
        $this->assertNull($handler->getCompletionProviders());
    }

    public function testAddToolUsesInputSchemaFromHandlerWhenNoKwarg(): void
    {
        $handler = new SchemaToolHandler();

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: $handler,
            name: 'demo',
            description: 'Demo tool',
        ));

        $reference = $registry->getTool('demo');
        $this->assertSame('demo', $reference->tool->name);
        $this->assertSame('Demo tool', $reference->tool->description);
        $this->assertSame($handler->getInputSchema(), $reference->tool->inputSchema);
    }

    public function testAddToolPrefersInputSchemaKwargOverHandler(): void
    {
        $handler = new SchemaToolHandler();
        $kwargSchema = ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]];

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: $handler,
            name: 'demo',
            description: 'Demo tool',
            inputSchema: $kwargSchema,
        ));

        $this->assertSame($kwargSchema, $registry->getTool('demo')->tool->inputSchema);
    }

    public function testAddToolPrefersOutputSchemaKwargOverHandler(): void
    {
        $handler = new OutputSchemaToolHandler();
        $kwargOutput = ['type' => 'object', 'properties' => ['from' => ['const' => 'kwarg']]];

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: $handler,
            name: 'demo',
            description: 'Demo tool',
            outputSchema: $kwargOutput,
        ));

        $this->assertSame($kwargOutput, $registry->getTool('demo')->tool->outputSchema);
    }

    public function testAddToolUsesOutputSchemaFromHandlerWhenNoKwarg(): void
    {
        $handler = new OutputSchemaToolHandler();

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: $handler,
            name: 'demo',
            description: 'Demo tool',
        ));

        $this->assertSame($handler->getOutputSchema(), $registry->getTool('demo')->tool->outputSchema);
    }

    public function testAddToolWithoutNameRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(SchemaToolHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: new SchemaToolHandler(),
            description: 'no name',
        ));
    }

    public function testAddToolWithoutDescriptionRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(SchemaToolHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: new SchemaToolHandler(),
            name: 'demo',
        ));
    }

    public function testAddToolWithoutAnyInputSchemaRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(TraitOnlyRuntimeHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addTool(
            handler: new TraitOnlyRuntimeHandler(),
            name: 'demo',
            description: 'no schema source',
        ));
    }

    public function testAddResourceRegistersRuntimeHandler(): void
    {
        $handler = new TraitOnlyRuntimeHandler();

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addResource(
            handler: $handler,
            uri: 'config://app/settings',
            name: 'app_settings',
            description: 'App settings',
            mimeType: 'application/json',
        ));

        $reference = $registry->getResource('config://app/settings', false);
        $this->assertSame('app_settings', $reference->resource->name);
        $this->assertSame('App settings', $reference->resource->description);
        $this->assertSame('application/json', $reference->resource->mimeType);
    }

    public function testAddResourceWithoutNameRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(TraitOnlyRuntimeHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addResource(
            handler: new TraitOnlyRuntimeHandler(),
            uri: 'config://x',
            description: 'no name',
        ));
    }

    public function testAddResourceTemplateRegistersRuntimeHandlerWithCompletionProviders(): void
    {
        $handler = new ResourceTemplateRuntimeHandler();

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addResourceTemplate(
            handler: $handler,
            uriTemplate: 'user://{userId}/profile',
            name: 'user_profile',
            description: 'User profile by ID',
            mimeType: 'application/json',
        ));

        $reference = $registry->getResourceTemplate('user://{userId}/profile');
        $this->assertSame('user_profile', $reference->resourceTemplate->name);
        $this->assertSame('application/json', $reference->resourceTemplate->mimeType);
        $this->assertArrayHasKey('userId', $reference->completionProviders);
        $this->assertInstanceOf(ListCompletionProvider::class, $reference->completionProviders['userId']);
    }

    public function testAddResourceTemplateWithoutDescriptionRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(TraitOnlyRuntimeHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addResourceTemplate(
            handler: new TraitOnlyRuntimeHandler(),
            uriTemplate: 'user://{userId}',
            name: 'user',
        ));
    }

    public function testAddPromptRegistersRuntimeHandlerWithArgumentsFromHandler(): void
    {
        $handler = new PromptRuntimeHandler();

        $registry = $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addPrompt(
            handler: $handler,
            name: 'ask',
            description: 'Ask the assistant a question',
        ));

        $reference = $registry->getPrompt('ask');
        $this->assertSame('ask', $reference->prompt->name);
        $this->assertEquals($handler->getPromptArguments(), $reference->prompt->arguments);
        $this->assertArrayHasKey('q', $reference->completionProviders);
        $this->assertInstanceOf(ListCompletionProvider::class, $reference->completionProviders['q']);
    }

    public function testAddPromptWithoutNameRaisesConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote(PromptRuntimeHandler::class, '/').'/');

        $this->buildAndGetRegistry(static fn (Server\Builder $b) => $b->addPrompt(
            handler: new PromptRuntimeHandler(),
            description: 'no name',
        ));
    }

    /**
     * @param callable(Server\Builder): Server\Builder $configure
     */
    private function buildAndGetRegistry(callable $configure): \Mcp\Capability\RegistryInterface
    {
        $registry = new \Mcp\Capability\Registry();
        $builder = Server::builder()
            ->setServerInfo('test', '1.0.0')
            ->setRegistry($registry);
        $configure($builder)->build();

        return $registry;
    }
}

final class TraitOnlyRuntimeHandler implements RunTimeHandlerInterface
{
    use RunTimeHandlerTrait;

    public function filterArguments(array $arguments): array
    {
        return [];
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return null;
    }
}

final class SchemaToolHandler implements RunTimeHandlerInterface
{
    use RunTimeHandlerTrait;

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
    }

    public function filterArguments(array $arguments): array
    {
        return $arguments;
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return ['ok' => true];
    }
}

final class OutputSchemaToolHandler implements RunTimeHandlerInterface
{
    use RunTimeHandlerTrait;

    public function getInputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function getOutputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['from' => ['const' => 'handler']]];
    }

    public function filterArguments(array $arguments): array
    {
        return $arguments;
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return ['from' => 'handler'];
    }
}

final class ResourceTemplateRuntimeHandler implements RunTimeHandlerInterface
{
    use RunTimeHandlerTrait;

    public function getCompletionProviders(): array
    {
        return ['userId' => new ListCompletionProvider(['alice', 'bob'])];
    }

    public function filterArguments(array $arguments): array
    {
        return $arguments;
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return ['ok' => true];
    }
}

final class PromptRuntimeHandler implements RunTimeHandlerInterface
{
    use RunTimeHandlerTrait;

    public function getPromptArguments(): array
    {
        return [new PromptArgument('q', 'The question', true)];
    }

    public function getCompletionProviders(): array
    {
        return ['q' => new ListCompletionProvider(['hello', 'world'])];
    }

    public function filterArguments(array $arguments): array
    {
        return $arguments;
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return [];
    }
}
