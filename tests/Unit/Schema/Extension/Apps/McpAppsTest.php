<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension\Apps;

use Mcp\Schema\Enum\ToolVisibility;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\Resource;
use PHPUnit\Framework\TestCase;

class McpAppsTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame('io.modelcontextprotocol/ui', McpApps::EXTENSION_ID);
        $this->assertSame('text/html;profile=mcp-app', McpApps::MIME_TYPE);
        $this->assertSame('ui', McpApps::URI_SCHEME);
    }

    public function testExtensionCapability(): void
    {
        $capability = McpApps::extensionCapability();

        $this->assertSame(['mimeTypes' => ['text/html;profile=mcp-app']], $capability);
    }

    public function testIsUiResourceReturnsTrueForUiResource(): void
    {
        $resource = new Resource(
            uri: 'ui://my-app',
            name: 'my-app',
            mimeType: McpApps::MIME_TYPE,
        );

        $this->assertTrue(McpApps::isUiResource($resource));
    }

    public function testIsUiResourceReturnsFalseForNonUiScheme(): void
    {
        $resource = new Resource(
            uri: 'file://my-app',
            name: 'my-app',
            mimeType: McpApps::MIME_TYPE,
        );

        $this->assertFalse(McpApps::isUiResource($resource));
    }

    public function testIsUiResourceReturnsFalseForNonUiMimeType(): void
    {
        $resource = new Resource(
            uri: 'ui://my-app',
            name: 'my-app',
            mimeType: 'text/html',
        );

        $this->assertFalse(McpApps::isUiResource($resource));
    }

    public function testUiResourceCspSerialization(): void
    {
        $csp = new UiResourceCsp(
            connectDomains: ['https://api.example.com'],
            resourceDomains: ['https://cdn.example.com'],
            frameDomains: ['https://embed.example.com'],
            baseUriDomains: ['https://example.com'],
        );

        $serialized = $csp->jsonSerialize();

        $this->assertSame(['https://api.example.com'], $serialized['connectDomains']);
        $this->assertSame(['https://cdn.example.com'], $serialized['resourceDomains']);
        $this->assertSame(['https://embed.example.com'], $serialized['frameDomains']);
        $this->assertSame(['https://example.com'], $serialized['baseUriDomains']);
    }

    public function testUiResourceCspOmitsNullFields(): void
    {
        $csp = new UiResourceCsp(connectDomains: ['https://api.example.com']);

        $serialized = $csp->jsonSerialize();

        $this->assertArrayHasKey('connectDomains', $serialized);
        $this->assertArrayNotHasKey('resourceDomains', $serialized);
        $this->assertArrayNotHasKey('frameDomains', $serialized);
        $this->assertArrayNotHasKey('baseUriDomains', $serialized);
    }

    public function testUiResourceCspFromArray(): void
    {
        $csp = UiResourceCsp::fromArray([
            'connectDomains' => ['https://api.example.com'],
            'frameDomains' => ['https://embed.example.com'],
        ]);

        $this->assertSame(['https://api.example.com'], $csp->connectDomains);
        $this->assertNull($csp->resourceDomains);
        $this->assertSame(['https://embed.example.com'], $csp->frameDomains);
        $this->assertNull($csp->baseUriDomains);
    }

    public function testUiResourcePermissionsSerialization(): void
    {
        $perms = new UiResourcePermissions(
            camera: true,
            microphone: false,
            geolocation: true,
            clipboardWrite: false,
        );

        $serialized = $perms->jsonSerialize();

        $this->assertTrue($serialized['camera']);
        $this->assertFalse($serialized['microphone']);
        $this->assertTrue($serialized['geolocation']);
        $this->assertFalse($serialized['clipboardWrite']);
    }

    public function testUiResourcePermissionsOmitsNullFields(): void
    {
        $perms = new UiResourcePermissions(clipboardWrite: true);

        $serialized = $perms->jsonSerialize();

        $this->assertArrayNotHasKey('camera', $serialized);
        $this->assertArrayNotHasKey('microphone', $serialized);
        $this->assertArrayNotHasKey('geolocation', $serialized);
        $this->assertArrayHasKey('clipboardWrite', $serialized);
    }

    public function testUiResourcePermissionsFromArray(): void
    {
        $perms = UiResourcePermissions::fromArray([
            'camera' => true,
            'clipboardWrite' => false,
        ]);

        $this->assertTrue($perms->camera);
        $this->assertNull($perms->microphone);
        $this->assertNull($perms->geolocation);
        $this->assertFalse($perms->clipboardWrite);
    }

    public function testUiResourceContentMetaSerialization(): void
    {
        $meta = new UiResourceContentMeta(
            csp: new UiResourceCsp(connectDomains: ['https://api.example.com']),
            permissions: new UiResourcePermissions(clipboardWrite: true),
            domain: 'example.com',
            prefersBorder: true,
        );

        $serialized = $meta->jsonSerialize();

        $this->assertArrayHasKey('csp', $serialized);
        $this->assertArrayHasKey('permissions', $serialized);
        $this->assertSame('example.com', $serialized['domain']);
        $this->assertTrue($serialized['prefersBorder']);
    }

    public function testUiResourceContentMetaOmitsNullFields(): void
    {
        $meta = new UiResourceContentMeta(prefersBorder: true);

        $serialized = $meta->jsonSerialize();

        $this->assertArrayNotHasKey('csp', $serialized);
        $this->assertArrayNotHasKey('permissions', $serialized);
        $this->assertArrayNotHasKey('domain', $serialized);
        $this->assertArrayHasKey('prefersBorder', $serialized);
    }

    public function testUiResourceContentMetaFromArray(): void
    {
        $meta = UiResourceContentMeta::fromArray([
            'csp' => ['connectDomains' => ['https://api.example.com']],
            'permissions' => ['clipboardWrite' => true],
            'domain' => 'example.com',
            'prefersBorder' => false,
        ]);

        $this->assertInstanceOf(UiResourceCsp::class, $meta->csp);
        $this->assertSame(['https://api.example.com'], $meta->csp->connectDomains);
        $this->assertInstanceOf(UiResourcePermissions::class, $meta->permissions);
        $this->assertTrue($meta->permissions->clipboardWrite);
        $this->assertSame('example.com', $meta->domain);
        $this->assertFalse($meta->prefersBorder);
    }

    public function testUiResourceContentMetaToMetaArray(): void
    {
        $meta = new UiResourceContentMeta(
            csp: new UiResourceCsp(connectDomains: ['https://api.example.com']),
            prefersBorder: true,
        );

        $metaArray = $meta->toMetaArray();

        $this->assertArrayHasKey('ui', $metaArray);
        $this->assertArrayHasKey('csp', $metaArray['ui']);
        $this->assertArrayHasKey('prefersBorder', $metaArray['ui']);
    }

    public function testUiToolMetaSerialization(): void
    {
        $meta = new UiToolMeta(
            resourceUri: 'ui://my-app',
            visibility: [ToolVisibility::Model->value, ToolVisibility::App->value],
        );

        $serialized = $meta->jsonSerialize();

        $this->assertSame('ui://my-app', $serialized['resourceUri']);
        $this->assertSame(['model', 'app'], $serialized['visibility']);
    }

    public function testUiToolMetaOmitsNullFields(): void
    {
        $meta = new UiToolMeta(resourceUri: 'ui://my-app');

        $serialized = $meta->jsonSerialize();

        $this->assertArrayHasKey('resourceUri', $serialized);
        $this->assertArrayNotHasKey('visibility', $serialized);
    }

    public function testUiToolMetaFromArray(): void
    {
        $meta = UiToolMeta::fromArray([
            'resourceUri' => 'ui://my-app',
            'visibility' => ['app'],
        ]);

        $this->assertSame('ui://my-app', $meta->resourceUri);
        $this->assertSame(['app'], $meta->visibility);
    }

    public function testUiToolMetaToMetaArray(): void
    {
        $meta = new UiToolMeta(
            resourceUri: 'ui://dashboard',
            visibility: ['model', 'app'],
        );

        $metaArray = $meta->toMetaArray();

        $this->assertArrayHasKey('ui', $metaArray);
        $this->assertSame('ui://dashboard', $metaArray['ui']['resourceUri']);
        $this->assertSame(['model', 'app'], $metaArray['ui']['visibility']);
    }

    public function testToolVisibilityEnum(): void
    {
        $this->assertSame('model', ToolVisibility::Model->value);
        $this->assertSame('app', ToolVisibility::App->value);
    }
}
