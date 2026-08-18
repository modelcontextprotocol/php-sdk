<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\JsonRpc;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ErrorCodesTest extends TestCase
{
    #[TestDox('header mismatch carries -32020')]
    public function testHeaderMismatch(): void
    {
        $error = Error::forHeaderMismatch('Header does not match body', 'req-1');

        $this->assertSame(Error::HEADER_MISMATCH, $error->code);
        $this->assertSame(-32020, $error->code);
    }

    #[TestDox('missing client capability carries -32021 and the required capabilities')]
    public function testMissingClientCapability(): void
    {
        $error = Error::forMissingRequiredClientCapability(
            'Sampling is required',
            new ClientCapabilities(sampling: true),
            'req-1',
        );

        $this->assertSame(-32021, $error->code);

        /** @var array{requiredCapabilities: ClientCapabilities} $data */
        $data = $error->data;
        $this->assertInstanceOf(ClientCapabilities::class, $data['requiredCapabilities']);

        $encoded = json_decode(json_encode($error) ?: '', true);
        $this->assertArrayHasKey('sampling', $encoded['error']['data']['requiredCapabilities']);
    }

    #[TestDox('unsupported version carries -32022 plus the requested and supported versions')]
    public function testUnsupportedProtocolVersion(): void
    {
        $error = Error::forUnsupportedProtocolVersion(
            '1900-01-01',
            [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_06_18],
            'req-1',
        );

        $this->assertSame(-32022, $error->code);
        $this->assertSame([
            'requested' => '1900-01-01',
            'supported' => ['2025-11-25', '2025-06-18'],
        ], $error->data);
    }

    #[TestDox('the supported list survives JSON encoding as a plain array')]
    public function testUnsupportedVersionEncoding(): void
    {
        $error = Error::forUnsupportedProtocolVersion('1900-01-01', ProtocolVersion::handshakeVersions(), 'req-1');

        $encoded = json_decode(json_encode($error) ?: '', true);

        $this->assertSame(
            array_map(static fn (ProtocolVersion $v): string => $v->value, ProtocolVersion::handshakeVersions()),
            $encoded['error']['data']['supported'],
        );
    }
}
