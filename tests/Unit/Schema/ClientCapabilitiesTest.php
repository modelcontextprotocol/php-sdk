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
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ClientCapabilitiesTest extends TestCase
{
    public function testSerializesRootsWithoutListChanged(): void
    {
        $capabilities = new ClientCapabilities(roots: true);

        $data = json_decode(json_encode($capabilities, \JSON_THROW_ON_ERROR), true);

        $this->assertArrayHasKey('roots', $data);
        $this->assertSame([], $data['roots']);
    }

    public function testSerializesRootsWithListChanged(): void
    {
        $capabilities = new ClientCapabilities(roots: true, rootsListChanged: true);

        $data = json_decode(json_encode($capabilities, \JSON_THROW_ON_ERROR), true);

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

        $data = json_decode(json_encode($capabilities, \JSON_THROW_ON_ERROR), true);
        $restored = ClientCapabilities::fromArray($data);

        $this->assertTrue($restored->roots);
        $this->assertTrue($restored->rootsListChanged);
    }

    public function testRoundTripPreservesSamplingSubCapabilities(): void
    {
        $capabilities = new ClientCapabilities(sampling: true, samplingContext: true, samplingTools: true);

        $serialized = $capabilities->jsonSerialize();
        $this->assertIsArray($serialized);
        $sampling = $serialized['sampling'] ?? null;
        $this->assertIsObject($sampling);
        $this->assertObjectHasProperty('context', $sampling);
        $this->assertObjectHasProperty('tools', $sampling);

        $restored = ClientCapabilities::fromArray(json_decode(json_encode($capabilities, \JSON_THROW_ON_ERROR), true));

        $this->assertTrue($restored->sampling);
        $this->assertTrue($restored->samplingContext);
        $this->assertTrue($restored->samplingTools);
    }

    public function testPlainSamplingLeavesSubCapabilitiesOff(): void
    {
        $capabilities = new ClientCapabilities(sampling: true);

        $serialized = $capabilities->jsonSerialize();
        $this->assertIsArray($serialized);
        $sampling = $serialized['sampling'] ?? null;
        $this->assertIsObject($sampling);
        $this->assertObjectNotHasProperty('context', $sampling);
        $this->assertObjectNotHasProperty('tools', $sampling);

        $restored = ClientCapabilities::fromArray(json_decode(json_encode($capabilities, \JSON_THROW_ON_ERROR), true));

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

    #[TestDox('reads the elicitation sub-capabilities')]
    public function testReadsElicitationSubCapabilities(): void
    {
        $capabilities = ClientCapabilities::fromArray([
            'elicitation' => ['form' => [], 'url' => []],
        ]);

        $this->assertTrue($capabilities->elicitation);
        $this->assertTrue($capabilities->elicitationForm);
        $this->assertTrue($capabilities->elicitationUrl);
    }

    #[TestDox('an elicitation capability naming no mode declares form')]
    public function testElicitationWithoutModeImpliesForm(): void
    {
        $capabilities = ClientCapabilities::fromArray(['elicitation' => []]);

        $this->assertTrue($capabilities->elicitationForm);
        $this->assertFalse($capabilities->elicitationUrl);
    }

    #[TestDox('naming url alone does not declare form')]
    public function testElicitationUrlAloneIsNotForm(): void
    {
        $capabilities = ClientCapabilities::fromArray(['elicitation' => ['url' => []]]);

        $this->assertFalse($capabilities->elicitationForm);
        $this->assertTrue($capabilities->elicitationUrl);
    }

    #[TestDox('an absent elicitation capability declares no mode either way')]
    public function testAbsentElicitationLeavesModesNull(): void
    {
        $capabilities = ClientCapabilities::fromArray([]);

        $this->assertNull($capabilities->elicitation);
        $this->assertNull($capabilities->elicitationForm);
        $this->assertNull($capabilities->elicitationUrl);
    }

    #[TestDox('round-trips the elicitation sub-capabilities')]
    public function testRoundTripPreservesElicitationSubCapabilities(): void
    {
        $capabilities = new ClientCapabilities(elicitation: true, elicitationForm: true, elicitationUrl: true);

        $encoded = json_encode($capabilities) ?: '';
        $this->assertStringContainsString('"elicitation":{"form":{},"url":{}}', $encoded);

        $restored = ClientCapabilities::fromArray(json_decode($encoded, true));

        $this->assertTrue($restored->elicitationForm);
        $this->assertTrue($restored->elicitationUrl);
    }

    #[TestDox('declaring only a mode still advertises elicitation')]
    public function testElicitationModeImpliesParent(): void
    {
        $capabilities = new ClientCapabilities(elicitationUrl: true);

        $encoded = json_decode(json_encode($capabilities) ?: '', true);

        $this->assertArrayHasKey('elicitation', $encoded);
        $this->assertArrayHasKey('url', $encoded['elicitation']);
    }
}
