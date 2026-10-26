<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Stateless;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class OriginatingRequestBinderTest extends TestCase
{
    /**
     * @param list<string> $scopes
     */
    private static function token(string $subject = 'alice', string $clientId = 'app', array $scopes = ['read']): AccessToken
    {
        return new AccessToken($scopes, ['iss' => 'https://auth.example.com', 'client_id' => $clientId, 'sub' => $subject]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(string $tool = 'book', array $arguments = ['to' => 'BER', 'seats' => 2]): CallToolRequest
    {
        return (new CallToolRequest($tool, $arguments))->withId(1);
    }

    #[TestDox('a retry of the same call binds to the same value')]
    public function testRetryBindsToTheSameValue(): void
    {
        $binder = new OriginatingRequestBinder();

        // A retry carries another id, another `_meta`, and a refreshed token.
        $first = self::call()->withMeta(['progressToken' => 'p1']);
        $retry = (new CallToolRequest('book', ['seats' => 2, 'to' => 'BER']))->withId(2)->withMeta(['progressToken' => 'p2']);

        $this->assertSame(
            $binder->bind($first, self::token(scopes: ['read'])),
            $binder->bind($retry, self::token(scopes: ['read', 'write'])),
        );
    }

    /**
     * @return iterable<string, array{Request, ?AccessToken}>
     */
    public static function otherRequests(): iterable
    {
        yield 'other tool' => [self::call(tool: 'cancel'), self::token()];
        yield 'other arguments' => [self::call(arguments: ['to' => 'CDG', 'seats' => 2]), self::token()];
        yield 'nested arguments differ' => [self::call(arguments: ['to' => 'BER', 'seats' => [2]]), self::token()];
        yield 'other subject' => [self::call(), self::token(subject: 'mallory')];
        yield 'other client' => [self::call(), self::token(clientId: 'other-app')];
        yield 'no token' => [self::call(), null];
        yield 'prompt of the same name' => [(new GetPromptRequest('book', ['to' => 'BER', 'seats' => '2']))->withId(1), self::token()];
        yield 'other method' => [(new ListToolsRequest())->withId(1), self::token()];
    }

    #[DataProvider('otherRequests')]
    #[TestDox('another request or principal binds to another value: $_dataName')]
    public function testOtherRequestsBindElsewhere(Request $request, ?AccessToken $token): void
    {
        $binder = new OriginatingRequestBinder();

        $this->assertNotSame($binder->bind(self::call(), self::token()), $binder->bind($request, $token));
    }

    #[TestDox('a resource read binds to its URI')]
    public function testResourceReadBindsToItsUri(): void
    {
        $binder = new OriginatingRequestBinder();

        $this->assertSame(
            $binder->bind((new ReadResourceRequest('file:///a'))->withId(1), null),
            $binder->bind((new ReadResourceRequest('file:///a'))->withId(2), null),
        );
        $this->assertNotSame(
            $binder->bind((new ReadResourceRequest('file:///a'))->withId(1), null),
            $binder->bind((new ReadResourceRequest('file:///b'))->withId(1), null),
        );
    }
}
