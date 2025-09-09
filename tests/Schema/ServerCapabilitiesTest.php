<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Schema;

use Mcp\Schema\ServerCapabilities;
use PHPUnit\Framework\TestCase;

class ServerCapabilitiesTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $capabilities = new ServerCapabilities();

        $this->assertTrue($capabilities->tools);
        $this->assertFalse($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resources);
        $this->assertFalse($capabilities->resourcesSubscribe);
        $this->assertFalse($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->prompts);
        $this->assertFalse($capabilities->promptsListChanged);
        $this->assertFalse($capabilities->logging);
        $this->assertFalse($capabilities->completions);
        $this->assertNull($capabilities->experimental);
    }

    public function testConstructorWithAllParameters(): void
    {
        $experimental = ['feature1' => true, 'feature2' => 'enabled'];

        $capabilities = new ServerCapabilities(
            tools: false,
            toolsListChanged: true,
            resources: false,
            resourcesSubscribe: true,
            resourcesListChanged: true,
            prompts: false,
            promptsListChanged: true,
            logging: true,
            completions: true,
            experimental: $experimental
        );

        $this->assertFalse($capabilities->tools);
        $this->assertTrue($capabilities->toolsListChanged);
        $this->assertFalse($capabilities->resources);
        $this->assertTrue($capabilities->resourcesSubscribe);
        $this->assertTrue($capabilities->resourcesListChanged);
        $this->assertFalse($capabilities->prompts);
        $this->assertTrue($capabilities->promptsListChanged);
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->completions);
        $this->assertEquals($experimental, $capabilities->experimental);
    }

    public function testConstructorWithNullValues(): void
    {
        $capabilities = new ServerCapabilities(
            tools: null,
            toolsListChanged: null,
            resources: null,
            resourcesSubscribe: null,
            resourcesListChanged: null,
            prompts: null,
            promptsListChanged: null,
            logging: null,
            completions: null,
            experimental: null
        );

        $this->assertNull($capabilities->tools);
        $this->assertNull($capabilities->toolsListChanged);
        $this->assertNull($capabilities->resources);
        $this->assertNull($capabilities->resourcesSubscribe);
        $this->assertNull($capabilities->resourcesListChanged);
        $this->assertNull($capabilities->prompts);
        $this->assertNull($capabilities->promptsListChanged);
        $this->assertNull($capabilities->logging);
        $this->assertNull($capabilities->completions);
        $this->assertNull($capabilities->experimental);
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $capabilities = ServerCapabilities::fromArray([]);

        $this->assertFalse($capabilities->logging);
        $this->assertFalse($capabilities->completions);
        $this->assertFalse($capabilities->tools);
        $this->assertFalse($capabilities->prompts);
        $this->assertFalse($capabilities->resources);
        $this->assertNull($capabilities->toolsListChanged);
        $this->assertNull($capabilities->promptsListChanged);
        $this->assertNull($capabilities->resourcesSubscribe);
        $this->assertNull($capabilities->resourcesListChanged);
        $this->assertNull($capabilities->experimental);
    }

    public function testFromArrayWithBasicCapabilities(): void
    {
        $data = [
            'tools' => new \stdClass(),
            'resources' => new \stdClass(),
            'prompts' => new \stdClass(),
            'logging' => new \stdClass(),
            'completions' => new \stdClass(),
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->completions);
        $this->assertNull($capabilities->toolsListChanged);
        $this->assertNull($capabilities->promptsListChanged);
        $this->assertNull($capabilities->resourcesSubscribe);
        $this->assertNull($capabilities->resourcesListChanged);
    }

    public function testFromArrayWithPromptsArrayListChanged(): void
    {
        $data = [
            'prompts' => ['listChanged' => true],
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->promptsListChanged);
    }

    public function testFromArrayWithPromptsObjectListChanged(): void
    {
        $prompts = new \stdClass();
        $prompts->listChanged = true;

        $data = [
            'prompts' => $prompts,
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->promptsListChanged);
    }

    public function testFromArrayWithResourcesArraySubscribeAndListChanged(): void
    {
        $data = [
            'resources' => [
                'subscribe' => true,
                'listChanged' => false,
            ],
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->resourcesSubscribe);
        $this->assertFalse($capabilities->resourcesListChanged);
    }

    public function testFromArrayWithResourcesObjectSubscribeAndListChanged(): void
    {
        $resources = new \stdClass();
        $resources->subscribe = false;
        $resources->listChanged = true;

        $data = [
            'resources' => $resources,
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->resources);
        $this->assertFalse($capabilities->resourcesSubscribe);
        $this->assertTrue($capabilities->resourcesListChanged);
    }

    public function testFromArrayWithToolsArrayListChanged(): void
    {
        $data = [
            'tools' => ['listChanged' => false],
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->tools);
        $this->assertFalse($capabilities->toolsListChanged);
    }

    public function testFromArrayWithToolsObjectListChanged(): void
    {
        $tools = new \stdClass();
        $tools->listChanged = true;

        $data = [
            'tools' => $tools,
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->toolsListChanged);
    }

    public function testFromArrayWithExperimental(): void
    {
        $experimental = ['feature1' => true, 'feature2' => 'test'];
        $data = [
            'experimental' => $experimental,
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertEquals($experimental, $capabilities->experimental);
    }

    public function testFromArrayWithComplexData(): void
    {
        $data = [
            'tools' => ['listChanged' => true],
            'resources' => [
                'subscribe' => true,
                'listChanged' => false,
            ],
            'prompts' => ['listChanged' => true],
            'logging' => new \stdClass(),
            'completions' => new \stdClass(),
            'experimental' => ['customFeature' => 'enabled'],
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resources);
        $this->assertTrue($capabilities->resourcesSubscribe);
        $this->assertFalse($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->promptsListChanged);
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->completions);
        $this->assertEquals(['customFeature' => 'enabled'], $capabilities->experimental);
    }

    public function testJsonSerializeWithDefaults(): void
    {
        $capabilities = new ServerCapabilities();
        $json = $capabilities->jsonSerialize();

        $expected = [
            'tools' => new \stdClass(),
            'resources' => new \stdClass(),
            'prompts' => new \stdClass(),
        ];

        $this->assertEquals($expected, $json);
    }

    public function testJsonSerializeWithAllFeaturesEnabled(): void
    {
        $experimental = ['feature1' => true];
        $capabilities = new ServerCapabilities(
            tools: true,
            toolsListChanged: true,
            resources: true,
            resourcesSubscribe: true,
            resourcesListChanged: true,
            prompts: true,
            promptsListChanged: true,
            logging: true,
            completions: true,
            experimental: $experimental
        );

        $json = $capabilities->jsonSerialize();

        $this->assertArrayHasKey('logging', $json);
        $this->assertEquals(new \stdClass(), $json['logging']);

        $this->assertArrayHasKey('completions', $json);
        $this->assertEquals(new \stdClass(), $json['completions']);

        $this->assertArrayHasKey('prompts', $json);
        $this->assertTrue($json['prompts']->listChanged);

        $this->assertArrayHasKey('resources', $json);
        $this->assertTrue($json['resources']->subscribe);
        $this->assertTrue($json['resources']->listChanged);

        $this->assertArrayHasKey('tools', $json);
        $this->assertTrue($json['tools']->listChanged);

        $this->assertArrayHasKey('experimental', $json);
        $this->assertEquals((object) $experimental, $json['experimental']);
    }

    public function testJsonSerializeWithFalseValues(): void
    {
        $capabilities = new ServerCapabilities(
            tools: false,
            resources: false,
            prompts: false,
            logging: false,
            completions: false
        );

        $json = $capabilities->jsonSerialize();

        $this->assertEquals([], $json);
    }

    public function testJsonSerializeWithMixedValues(): void
    {
        $capabilities = new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: false,
            resourcesSubscribe: true,
            resourcesListChanged: true,
            prompts: true,
            promptsListChanged: false,
            logging: false,
            completions: true
        );

        $json = $capabilities->jsonSerialize();

        $expected = [
            'completions' => new \stdClass(),
            'prompts' => new \stdClass(),
            'resources' => (object) [
                'subscribe' => true,
                'listChanged' => true,
            ],
            'tools' => new \stdClass(),
        ];

        $this->assertEquals($expected, $json);
    }

    public function testJsonSerializeWithOnlyListChangedFlags(): void
    {
        $capabilities = new ServerCapabilities(
            tools: false,
            toolsListChanged: true,
            resources: false,
            resourcesListChanged: true,
            prompts: false,
            promptsListChanged: true
        );

        $json = $capabilities->jsonSerialize();

        $expected = [
            'prompts' => (object) ['listChanged' => true],
            'resources' => (object) ['listChanged' => true],
            'tools' => (object) ['listChanged' => true],
        ];

        $this->assertEquals($expected, $json);
    }

    public function testJsonSerializeWithNullExperimental(): void
    {
        $capabilities = new ServerCapabilities(
            tools: true,
            experimental: null
        );

        $json = $capabilities->jsonSerialize();

        $this->assertArrayNotHasKey('experimental', $json);
        $this->assertArrayHasKey('tools', $json);
    }

    public function testFromArrayHandlesEdgeCasesGracefully(): void
    {
        $data = [
            'prompts' => [],
            'resources' => [],
            'tools' => [],
        ];

        $capabilities = ServerCapabilities::fromArray($data);

        $this->assertTrue($capabilities->prompts);
        $this->assertNull($capabilities->promptsListChanged);
        $this->assertTrue($capabilities->resources);
        $this->assertNull($capabilities->resourcesSubscribe);
        $this->assertNull($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->tools);
        $this->assertNull($capabilities->toolsListChanged);
    }
}
