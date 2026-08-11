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

final class ClientCapabilitiesSamplingTest extends TestCase
{
    public function testSamplingSubCapabilitiesRoundTrip(): void
    {
        $capabilities = new ClientCapabilities(sampling: true, samplingContext: true, samplingTools: true);
        $serialized = $capabilities->jsonSerialize();

        $this->assertObjectHasProperty('context', $serialized['sampling']);
        $this->assertObjectHasProperty('tools', $serialized['sampling']);

        $hydrated = ClientCapabilities::fromArray(json_decode(json_encode($serialized, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR));
        $this->assertTrue($hydrated->sampling);
        $this->assertTrue($hydrated->samplingContext);
        $this->assertTrue($hydrated->samplingTools);
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
