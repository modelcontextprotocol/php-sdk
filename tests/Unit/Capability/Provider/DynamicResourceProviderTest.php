<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Provider;

use Mcp\Capability\Registry;
use Mcp\Exception\RegistryException;
use Mcp\Schema\Resource;
use Mcp\Tests\Unit\Capability\Provider\Fixtures\TestDynamicResourceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DynamicResourceProviderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry(null, new NullLogger());
    }

    public function testProviderRegistrationInRegistry(): void
    {
        $resource = $this->createResource('test://resource');
        $provider = new TestDynamicResourceProvider([$resource]);

        $this->registry->registerDynamicResourceProvider($provider);

        $providers = $this->registry->getDynamicResourceProviders();
        $this->assertCount(1, $providers);
        $this->assertSame($provider, $providers[0]);
    }

    public function testResourceEnumerationFromDynamicProvider(): void
    {
        $resource1 = $this->createResource('dynamic://resource1');
        $resource2 = $this->createResource('dynamic://resource2');
        $provider = new TestDynamicResourceProvider([$resource1, $resource2]);

        $this->registry->registerDynamicResourceProvider($provider);

        $page = $this->registry->getResources();
        $resources = $page->references;

        $this->assertCount(2, $resources);
        $this->assertArrayHasKey('dynamic://resource1', $resources);
        $this->assertArrayHasKey('dynamic://resource2', $resources);
        $this->assertSame($resource1, $resources['dynamic://resource1']);
        $this->assertSame($resource2, $resources['dynamic://resource2']);
    }

    public function testResourceEnumerationFromMixedSources(): void
    {
        // Register a static resource
        $staticResource = $this->createResource('static://resource');
        $this->registry->registerResource($staticResource, fn () => 'static content');

        // Register a dynamic provider
        $dynamicResource = $this->createResource('dynamic://resource');
        $provider = new TestDynamicResourceProvider([$dynamicResource]);
        $this->registry->registerDynamicResourceProvider($provider);

        $page = $this->registry->getResources();
        $resources = $page->references;

        $this->assertCount(2, $resources);
        $this->assertArrayHasKey('static://resource', $resources);
        $this->assertArrayHasKey('dynamic://resource', $resources);
    }

    public function testConflictDetectionStaticVsDynamic(): void
    {
        // Register a static resource first
        $staticResource = $this->createResource('conflict://resource');
        $this->registry->registerResource($staticResource, fn () => 'static content');

        // Try to register a dynamic provider with the same resource URI
        $dynamicResource = $this->createResource('conflict://resource');
        $provider = new TestDynamicResourceProvider([$dynamicResource]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic resource provider conflict: resource "conflict://resource" is already registered as a static resource.');

        $this->registry->registerDynamicResourceProvider($provider);
    }

    public function testConflictDetectionDynamicVsDynamic(): void
    {
        // Register first dynamic provider
        $resource1 = $this->createResource('shared://resource');
        $provider1 = new TestDynamicResourceProvider([$resource1]);
        $this->registry->registerDynamicResourceProvider($provider1);

        // Try to register second dynamic provider with the same resource URI
        $resource2 = $this->createResource('shared://resource');
        $provider2 = new TestDynamicResourceProvider([$resource2]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic resource provider conflict: resource "shared://resource" is already supported by another provider.');

        $this->registry->registerDynamicResourceProvider($provider2);
    }

    private function createResource(string $uri, ?string $mimeType = null): Resource
    {
        // Generate a valid resource name (only alphanumeric, underscores, hyphens)
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', basename($uri));
        $name = $name ?: 'resource';

        return new Resource(
            uri: $uri,
            name: $name,
            description: "Test resource: {$uri}",
            mimeType: $mimeType ?? 'text/plain',
        );
    }
}
