<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\AuthorizationChallenge;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class AuthorizationChallengeTest extends TestCase
{
    #[TestDox('the resource metadata URL and scopes are read out of a full challenge')]
    public function testParsesFullChallenge(): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, [
            'WWW-Authenticate' => 'Bearer scope="mcp:read mcp:write", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp", error="insufficient_scope"',
        ]));

        $this->assertSame('https://mcp.example.com/.well-known/oauth-protected-resource/mcp', $challenge->getResourceMetadataUrl());
        $this->assertSame(['mcp:read', 'mcp:write'], $challenge->getScopes());
        $this->assertSame('insufficient_scope', $challenge->getError());
    }

    #[TestDox('a challenge without parameters yields nothing rather than failing')]
    public function testParsesBareChallenge(): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, ['WWW-Authenticate' => 'Bearer']));

        $this->assertNull($challenge->getResourceMetadataUrl());
        $this->assertSame([], $challenge->getScopes());
        $this->assertNull($challenge->getError());
    }

    #[TestDox('a response with no challenge header at all yields nothing')]
    public function testHandlesMissingHeader(): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401));

        $this->assertSame([], $challenge->parameters);
    }

    #[TestDox('unquoted parameter values are accepted')]
    public function testParsesUnquotedValues(): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, [
            'WWW-Authenticate' => 'Bearer error=invalid_token, scope=mcp:read',
        ]));

        $this->assertSame('invalid_token', $challenge->getError());
        $this->assertSame(['mcp:read'], $challenge->getScopes());
    }

    #[TestDox('a Basic challenge alongside Bearer does not shadow it')]
    public function testIgnoresOtherSchemes(): void
    {
        $response = (new Response(401))
            ->withAddedHeader('WWW-Authenticate', 'Basic realm="internal"')
            ->withAddedHeader('WWW-Authenticate', 'Bearer scope="mcp:read"');

        $this->assertSame(['mcp:read'], AuthorizationChallenge::fromResponse($response)->getScopes());
    }
}
