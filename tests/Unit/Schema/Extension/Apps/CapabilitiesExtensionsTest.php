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

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\ServerCapabilities;
use PHPUnit\Framework\TestCase;

class CapabilitiesExtensionsTest extends TestCase
{
    public function testServerCapabilitiesWithExtensions(): void
    {
        $extensions = [
            McpApps::EXTENSION_ID => McpApps::extensionCapability(),
        ];

        $caps = new ServerCapabilities(
            tools: true,
            resources: true,
            extensions: $extensions,
        );

        $this->assertSame($extensions, $caps->extensions);
    }

    public function testServerCapabilitiesExtensionsDefaultNull(): void
    {
        $caps = new ServerCapabilities();

        $this->assertNull($caps->extensions);
    }

    public function testServerCapabilitiesJsonSerializeWithExtensions(): void
    {
        $caps = new ServerCapabilities(
            tools: true,
            resources: true,
            prompts: false,
            extensions: [
                McpApps::EXTENSION_ID => McpApps::extensionCapability(),
            ],
        );

        $json = $caps->jsonSerialize();

        $this->assertArrayHasKey('extensions', $json);
        $this->assertObjectHasProperty(McpApps::EXTENSION_ID, $json['extensions']);
    }

    public function testServerCapabilitiesJsonSerializeWithoutExtensions(): void
    {
        $caps = new ServerCapabilities(tools: true);

        $json = $caps->jsonSerialize();

        $this->assertArrayNotHasKey('extensions', $json);
    }

    public function testServerCapabilitiesFromArrayWithExtensions(): void
    {
        $data = [
            'tools' => new \stdClass(),
            'extensions' => [
                McpApps::EXTENSION_ID => ['mimeTypes' => ['text/html;profile=mcp-app']],
            ],
        ];

        $caps = ServerCapabilities::fromArray($data);

        $this->assertTrue($caps->tools);
        $this->assertNotNull($caps->extensions);
        $this->assertArrayHasKey(McpApps::EXTENSION_ID, $caps->extensions);
        $this->assertSame(['text/html;profile=mcp-app'], $caps->extensions[McpApps::EXTENSION_ID]['mimeTypes']);
    }

    public function testServerCapabilitiesFromArrayWithoutExtensions(): void
    {
        $caps = ServerCapabilities::fromArray(['tools' => new \stdClass()]);

        $this->assertNull($caps->extensions);
    }

    public function testClientCapabilitiesWithExtensions(): void
    {
        $extensions = [
            McpApps::EXTENSION_ID => McpApps::extensionCapability(),
        ];

        $caps = new ClientCapabilities(
            extensions: $extensions,
        );

        $this->assertSame($extensions, $caps->extensions);
    }

    public function testClientCapabilitiesExtensionsDefaultNull(): void
    {
        $caps = new ClientCapabilities();

        $this->assertNull($caps->extensions);
    }

    public function testClientCapabilitiesJsonSerializeWithExtensions(): void
    {
        $caps = new ClientCapabilities(
            extensions: [
                McpApps::EXTENSION_ID => McpApps::extensionCapability(),
            ],
        );

        $json = $caps->jsonSerialize();

        $this->assertArrayHasKey('extensions', $json);
        $this->assertObjectHasProperty(McpApps::EXTENSION_ID, $json['extensions']);
    }

    public function testClientCapabilitiesJsonSerializeWithoutExtensions(): void
    {
        $caps = new ClientCapabilities();

        $json = $caps->jsonSerialize();

        $this->assertArrayNotHasKey('extensions', $json);
    }

    public function testClientCapabilitiesFromArrayWithExtensions(): void
    {
        $data = [
            'roots' => ['listChanged' => true],
            'extensions' => [
                McpApps::EXTENSION_ID => ['mimeTypes' => ['text/html;profile=mcp-app']],
            ],
        ];

        $caps = ClientCapabilities::fromArray($data);

        $this->assertTrue($caps->roots);
        $this->assertTrue($caps->rootsListChanged);
        $this->assertNotNull($caps->extensions);
        $this->assertArrayHasKey(McpApps::EXTENSION_ID, $caps->extensions);
    }

    public function testClientCapabilitiesFromArrayWithoutExtensions(): void
    {
        $caps = ClientCapabilities::fromArray(['roots' => ['listChanged' => true]]);

        $this->assertNull($caps->extensions);
    }

    public function testBackwardCompatibilityServerCapabilities(): void
    {
        $caps = new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: true,
            resourcesSubscribe: false,
            resourcesListChanged: false,
            prompts: true,
            promptsListChanged: false,
            logging: false,
            completions: false,
            experimental: null,
        );

        $this->assertNull($caps->extensions);

        $json = $caps->jsonSerialize();
        $this->assertArrayNotHasKey('extensions', $json);
    }

    public function testBackwardCompatibilityClientCapabilities(): void
    {
        $caps = new ClientCapabilities(
            roots: true,
            rootsListChanged: true,
            sampling: true,
            elicitation: true,
            experimental: null,
        );

        $this->assertNull($caps->extensions);

        $json = $caps->jsonSerialize();
        $this->assertArrayNotHasKey('extensions', $json);
    }
}
