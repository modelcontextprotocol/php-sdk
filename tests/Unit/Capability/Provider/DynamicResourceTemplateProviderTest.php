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
use Mcp\Schema\ResourceTemplate;
use Mcp\Tests\Unit\Capability\Provider\Fixtures\TestDynamicResourceTemplateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DynamicResourceTemplateProviderTest extends TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry(null, new NullLogger());
    }

    public function testProviderRegistrationInRegistry(): void
    {
        $template = $this->createTemplate('test://resource/{id}');
        $provider = new TestDynamicResourceTemplateProvider([$template]);

        $this->registry->registerDynamicResourceTemplateProvider($provider);

        $providers = $this->registry->getDynamicResourceTemplateProviders();
        $this->assertCount(1, $providers);
        $this->assertSame($provider, $providers[0]);
    }

    public function testTemplateEnumerationFromDynamicProvider(): void
    {
        $template1 = $this->createTemplate('dynamic://resource/{id}');
        $template2 = $this->createTemplate('dynamic://entity/{type}/{id}');
        $provider = new TestDynamicResourceTemplateProvider([$template1, $template2]);

        $this->registry->registerDynamicResourceTemplateProvider($provider);

        $page = $this->registry->getResourceTemplates();
        $templates = $page->references;

        $this->assertCount(2, $templates);
        $this->assertArrayHasKey('dynamic://resource/{id}', $templates);
        $this->assertArrayHasKey('dynamic://entity/{type}/{id}', $templates);
        $this->assertSame($template1, $templates['dynamic://resource/{id}']);
        $this->assertSame($template2, $templates['dynamic://entity/{type}/{id}']);
    }

    public function testTemplateEnumerationFromMixedSources(): void
    {
        // Register a static resource template
        $staticTemplate = $this->createTemplate('static://template/{id}');
        $this->registry->registerResourceTemplate($staticTemplate, fn () => 'static content');

        // Register a dynamic provider
        $dynamicTemplate = $this->createTemplate('dynamic://template/{id}');
        $provider = new TestDynamicResourceTemplateProvider([$dynamicTemplate]);
        $this->registry->registerDynamicResourceTemplateProvider($provider);

        $page = $this->registry->getResourceTemplates();
        $templates = $page->references;

        $this->assertCount(2, $templates);
        $this->assertArrayHasKey('static://template/{id}', $templates);
        $this->assertArrayHasKey('dynamic://template/{id}', $templates);
    }

    public function testConflictDetectionStaticVsDynamic(): void
    {
        // Register a static resource template first
        $staticTemplate = $this->createTemplate('conflict://template/{id}');
        $this->registry->registerResourceTemplate($staticTemplate, fn () => 'static content');

        // Try to register a dynamic provider with the same template
        $dynamicTemplate = $this->createTemplate('conflict://template/{id}');
        $provider = new TestDynamicResourceTemplateProvider([$dynamicTemplate]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic resource template provider conflict: template "conflict://template/{id}" is already registered as a static resource template.');

        $this->registry->registerDynamicResourceTemplateProvider($provider);
    }

    public function testConflictDetectionDynamicVsDynamic(): void
    {
        // Register first dynamic provider
        $template1 = $this->createTemplate('shared://template/{id}');
        $provider1 = new TestDynamicResourceTemplateProvider([$template1]);
        $this->registry->registerDynamicResourceTemplateProvider($provider1);

        // Try to register second dynamic provider with the same template
        $template2 = $this->createTemplate('shared://template/{id}');
        $provider2 = new TestDynamicResourceTemplateProvider([$template2]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessage('Dynamic resource template provider conflict: template "shared://template/{id}" is already supported by another provider.');

        $this->registry->registerDynamicResourceTemplateProvider($provider2);
    }

    public function testHasResourceTemplatesIncludesDynamic(): void
    {
        $this->assertFalse($this->registry->hasResourceTemplates());

        $template = $this->createTemplate('test://template/{id}');
        $provider = new TestDynamicResourceTemplateProvider([$template]);
        $this->registry->registerDynamicResourceTemplateProvider($provider);

        $this->assertTrue($this->registry->hasResourceTemplates());
    }

    private function createTemplate(string $uriTemplate, ?string $mimeType = null): ResourceTemplate
    {
        // Generate a valid resource template name (only alphanumeric, underscores, hyphens)
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', basename(str_replace(['{', '}'], '', $uriTemplate)));
        $name = $name ?: 'template';

        return new ResourceTemplate(
            uriTemplate: $uriTemplate,
            name: $name,
            description: "Test template: {$uriTemplate}",
            mimeType: $mimeType ?? 'text/plain',
        );
    }
}
