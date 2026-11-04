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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[TestDox('a quoted backslash unescapes one character, not a C escape sequence')]
    public function testUnescapesTheHttpWay(): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, [
            'WWW-Authenticate' => 'Bearer error="say \\"hi\\" now", scope="a\\nb"',
        ]));

        $this->assertSame('say "hi" now', $challenge->getError());
        $this->assertSame(['anb'], $challenge->getScopes());
    }

    #[TestDox('a Basic challenge alongside Bearer does not shadow it')]
    public function testIgnoresOtherSchemes(): void
    {
        $response = (new Response(401))
            ->withAddedHeader('WWW-Authenticate', 'Basic realm="internal"')
            ->withAddedHeader('WWW-Authenticate', 'Bearer scope="mcp:read"');

        $this->assertSame(['mcp:read'], AuthorizationChallenge::fromResponse($response)->getScopes());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function combinedHeaderProvider(): iterable
    {
        yield 'Bearer after another scheme' => ['Basic realm="internal", Bearer resource_metadata="https://good"', ['resource_metadata' => 'https://good']];
        yield 'Bearer after a token68 scheme' => ['Negotiate abc==, Bearer scope="a"', ['scope' => 'a']];
        yield 'Bearer after a bare scheme' => ['Negotiate, Bearer scope="a"', ['scope' => 'a']];
        yield 'Bearer before a token68 scheme' => ['Bearer scope="a", Negotiate abc==', ['scope' => 'a']];
        yield 'parameters of a later scheme do not override' => ['Bearer resource_metadata="https://good", scope="a", Digest realm="x", scope="evil", resource_metadata="https://evil"', ['resource_metadata' => 'https://good', 'scope' => 'a']];
        yield 'parameters of an earlier scheme are not picked up' => ['Basic realm="simple", Newauth realm="apps", type=1, title="Login to \\"apps\\"", Bearer scope="a"', ['scope' => 'a']];
        yield 'a scheme inside a quoted value is no scheme' => ['Basic realm="a, Bearer scope=\\"evil\\""', []];
        yield 'a comma inside a quoted value does not split' => ['Bearer error="use Basic realm=x, scope=evil", scope="a"', ['error' => 'use Basic realm=x, scope=evil', 'scope' => 'a']];
        yield 'an empty list member is skipped' => ['Bearer scope="a",, error="b"', ['scope' => 'a', 'error' => 'b']];
        yield 'several spaces before the equals sign' => ['Bearer scope="read", error  ="invalid_token", resource_metadata="https://good"', ['scope' => 'read', 'error' => 'invalid_token', 'resource_metadata' => 'https://good']];
        yield 'several tabs before the equals sign' => ["Bearer scope=\"read\", error\t\t=\"t\"", ['scope' => 'read', 'error' => 't']];
        yield 'several spaces around the equals sign' => ['Bearer scope="read", error  =  "t"', ['scope' => 'read', 'error' => 't']];
        yield 'the scheme is case-insensitive' => ['bEARER scope="a"', ['scope' => 'a']];
        yield 'a scheme merely starting with Bearer' => ['Bearer-x scope="a"', []];
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('combinedHeaderProvider')]
    #[TestDox('the Bearer challenge is isolated for: $_dataName')]
    public function testIsolatesTheBearerChallenge(string $header, array $expected): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, ['WWW-Authenticate' => $header]));

        $this->assertSame($expected, $challenge->parameters);
    }

    /**
     * @return iterable<string, array{string, string[]}>
     */
    public static function scopeProvider(): iterable
    {
        yield 'zero among others' => ['Bearer scope="0 mcp:read"', ['0', 'mcp:read']];
        yield 'bare zero' => ['Bearer scope=0', ['0']];
        yield 'surrounding and repeated whitespace' => ['Bearer scope="  a   0  b "', ['a', '0', 'b']];
        yield 'empty' => ['Bearer scope=""', []];
        yield 'only whitespace' => ['Bearer scope="   "', []];
    }

    /**
     * @param string[] $expected
     */
    #[DataProvider('scopeProvider')]
    #[TestDox('the scopes are split for: $_dataName')]
    public function testSplitsScopes(string $header, array $expected): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, ['WWW-Authenticate' => $header]));

        $this->assertSame($expected, $challenge->getScopes());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quotedPairProvider(): iterable
    {
        yield 'an escaped zero is a zero' => ['"a\\0b"', 'a0b'];
        yield 'an escaped backslash stays one backslash' => ['"a\\\\0b"', 'a\\0b'];
        yield 'an escaped quote' => ['"a\\"b"', 'a"b'];
        yield 'a bare value is left alone' => ['a\\0b', 'a\\0b'];
    }

    #[DataProvider('quotedPairProvider')]
    #[TestDox('the value is decoded for: $_dataName')]
    public function testDecodesQuotedPairs(string $value, string $expected): void
    {
        $challenge = AuthorizationChallenge::fromResponse(new Response(401, ['WWW-Authenticate' => 'Bearer error='.$value]));

        $this->assertSame($expected, $challenge->getError());
    }
}
