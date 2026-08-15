<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Schema\ClientCapabilities;
use PHPUnit\Framework\TestCase;

class ClientCapabilitiesTest extends TestCase
{
    public function testSerializesRootsWithoutListChanged(): void
    {
        $capabilities = new ClientCapabilities(roots: true);

        $data = json_decode(json_encode($capabilities), true);

        $this->assertArrayHasKey('roots', $data);
        $this->assertSame([], $data['roots']);
    }

    public function testSerializesRootsWithListChanged(): void
    {
        $capabilities = new ClientCapabilities(roots: true, rootsListChanged: true);

        $data = json_decode(json_encode($capabilities), true);

        $this->assertSame(['listChanged' => true], $data['roots']);
    }

    public function testSerializesEmptyCapabilitiesAsObject(): void
    {
        $capabilities = new ClientCapabilities();

        $this->assertSame('{}', json_encode($capabilities));
    }

    public function testFromArrayReadsRootsListChanged(): void
    {
        $capabilities = ClientCapabilities::fromArray(['roots' => ['listChanged' => true]]);

        $this->assertTrue($capabilities->roots);
        $this->assertTrue($capabilities->rootsListChanged);
    }

    public function testFromArrayRootsWithoutListChanged(): void
    {
        $capabilities = ClientCapabilities::fromArray(['roots' => []]);

        $this->assertTrue($capabilities->roots);
        $this->assertNull($capabilities->rootsListChanged);
    }

    public function testRoundTripPreservesRootsListChanged(): void
    {
        $capabilities = new ClientCapabilities(roots: true, rootsListChanged: true);

        $data = json_decode(json_encode($capabilities), true);
        $restored = ClientCapabilities::fromArray($data);

        $this->assertTrue($restored->roots);
        $this->assertTrue($restored->rootsListChanged);
    }

    public function testRoundTripPreservesSamplingSubCapabilities(): void
    {
        $capabilities = new ClientCapabilities(sampling: true, samplingContext: true, samplingTools: true);

        $serialized = $capabilities->jsonSerialize();
        $this->assertObjectHasProperty('context', $serialized['sampling']);
        $this->assertObjectHasProperty('tools', $serialized['sampling']);

        $restored = ClientCapabilities::fromArray(json_decode(json_encode($capabilities), true));

        $this->assertTrue($restored->sampling);
        $this->assertTrue($restored->samplingContext);
        $this->assertTrue($restored->samplingTools);
    }

    public function testPlainSamplingLeavesSubCapabilitiesOff(): void
    {
        $capabilities = new ClientCapabilities(sampling: true);

        $serialized = $capabilities->jsonSerialize();
        $this->assertObjectNotHasProperty('context', $serialized['sampling']);
        $this->assertObjectNotHasProperty('tools', $serialized['sampling']);

        $restored = ClientCapabilities::fromArray(json_decode(json_encode($capabilities), true));

        $this->assertTrue($restored->sampling);
        $this->assertFalse($restored->samplingContext);
        $this->assertFalse($restored->samplingTools);
    }

    public function testSamplingSubCapabilitiesAreHydratedFromObject(): void
    {
        $sampling = new \stdClass();
        $sampling->context = new \stdClass();
        $sampling->tools = new \stdClass();

        $capabilities = ClientCapabilities::fromArray(['sampling' => $sampling]);

        $this->assertTrue($capabilities->sampling);
        $this->assertTrue($capabilities->samplingContext);
        $this->assertTrue($capabilities->samplingTools);
    }
}
