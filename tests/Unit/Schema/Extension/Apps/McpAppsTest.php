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

use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use PHPUnit\Framework\TestCase;

class McpAppsTest extends TestCase
{
    public function testServerExtensionInterface(): void
    {
        $extension = new McpApps();

        $this->assertSame('io.modelcontextprotocol/ui', $extension->getId());
        $this->assertSame(['mimeTypes' => ['text/html;profile=mcp-app']], $extension->getCapabilities());
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

        // Per spec, each requested permission is an empty object marker.
        $this->assertEquals(new \stdClass(), $serialized['camera']);
        $this->assertArrayNotHasKey('microphone', $serialized);
        $this->assertEquals(new \stdClass(), $serialized['geolocation']);
        $this->assertArrayNotHasKey('clipboardWrite', $serialized);
        $this->assertSame('{"camera":{},"geolocation":{}}', json_encode($perms));
    }

    public function testUiResourcePermissionsOmitsUnrequestedFields(): void
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
        // Spec wire shape: presence indicates a request; the value is an empty object.
        $perms = UiResourcePermissions::fromArray([
            'camera' => [],
            'clipboardWrite' => [],
        ]);

        $this->assertTrue($perms->camera);
        $this->assertFalse($perms->microphone);
        $this->assertFalse($perms->geolocation);
        $this->assertTrue($perms->clipboardWrite);
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
            'permissions' => ['clipboardWrite' => []],
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

    public function testUiToolMetaSerialization(): void
    {
        $meta = new UiToolMeta(
            resourceUri: 'ui://my-app',
            visibility: [ToolVisibility::Model, ToolVisibility::App],
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
        $this->assertSame([ToolVisibility::App], $meta->visibility);
    }

    public function testToolVisibilityEnum(): void
    {
        $this->assertSame('model', ToolVisibility::Model->value);
        $this->assertSame('app', ToolVisibility::App->value);
    }
}
