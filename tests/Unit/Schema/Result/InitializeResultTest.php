<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Result;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class InitializeResultTest extends TestCase
{
    #[TestDox('offers the newest handshake revision when none is given')]
    public function testJsonSerializeDefaultsToLatestHandshakeVersion(): void
    {
        $result = new InitializeResult(new ServerCapabilities(), new Implementation('server', '1.0.0'));

        $protocolVersion = $result->jsonSerialize()['protocolVersion'];

        $this->assertSame(ProtocolVersion::latestHandshake()->value, $protocolVersion);
        $this->assertFalse(ProtocolVersion::from($protocolVersion)->isModern());
    }

    #[TestDox('keeps an explicitly given revision')]
    public function testJsonSerializeKeepsExplicitVersion(): void
    {
        $result = new InitializeResult(new ServerCapabilities(), new Implementation('server', '1.0.0'), protocolVersion: ProtocolVersion::V2024_11_05);

        $this->assertSame(ProtocolVersion::V2024_11_05->value, $result->jsonSerialize()['protocolVersion']);
    }
}
