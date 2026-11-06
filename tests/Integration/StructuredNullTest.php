<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @author Ian Morland
 */
final class StructuredNullTest extends IntegrationTestCase
{
    public function testModernNullAlsoHasSerializedJsonText(): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28));
        $result = $client->callTool('nullable');

        $this->assertEquals(new TextContent('null'), $result->content[0]);
    }

    public function testJsonSerializableNullIsPreserved(): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28));
        $result = $client->callTool('serialized_null');

        $this->assertFalse($result->isError);
        $this->assertTrue($result->hasStructuredContent());
        $this->assertNull($result->structuredContent);
    }

    public function testExplicitLegacyResultsAreNotRewritten(): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2025_11_25));
        $result = $client->callTool('explicit_null');

        $this->assertFalse($result->isError);
        $this->assertTrue($result->hasStructuredContent());
        $this->assertNull($result->structuredContent);
    }

    public function testExplicitNullResultsSurviveClientDecoding(): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28));
        $result = $client->callTool('explicit_null');

        $this->assertFalse($result->isError);
        $this->assertTrue($result->hasStructuredContent());
        $this->assertArrayHasKey('structuredContent', $result->jsonSerialize());
        $this->assertNull($result->structuredContent);
    }

    public function testModernNullResultsAreValidatedAgainstTheOutputSchema(): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28));

        foreach (['invalid_null', 'invalid_explicit_null'] as $tool) {
            $result = $client->callTool($tool);

            $this->assertTrue($result->isError, $tool);
            $this->assertFalse($result->hasStructuredContent());
        }
    }

    #[DataProvider('provideEras')]
    public function testNullStructuredContentFollowsTheDeclaredSchemaAndRevision(ProtocolVersion $era): void
    {
        $client = $this->connect('structured_null', $this->clientBuilder()->setProtocolVersion($era));
        $result = $client->callTool('nullable');

        $this->assertFalse($result->isError);
        $this->assertSame($era->isModern(), $result->hasStructuredContent());
        $this->assertSame($era->isModern(), \array_key_exists('structuredContent', $result->jsonSerialize()));
        $this->assertNull($result->structuredContent);

        $plain = $client->callTool('plain');
        $this->assertFalse($plain->isError);
        $this->assertFalse($plain->hasStructuredContent());
        $this->assertArrayNotHasKey('structuredContent', $plain->jsonSerialize());
    }
}
