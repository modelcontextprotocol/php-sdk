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

use Mcp\Client\Auth\AuthenticatingHttpClient;
use Mcp\Client\Auth\AuthenticatorInterface;
use Mcp\Client\Auth\BearerToken;
use Mcp\Exception\InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class AuthenticatingHttpClientTest extends TestCase
{
    #[TestDox('every request carries the credentials the authenticator provides')]
    public function testAuthenticatesEveryRequest(): void
    {
        $inner = new class implements ClientInterface {
            /** @var string[] */
            public array $seen = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen[] = $request->getHeaderLine('Authorization');

                return new Response(200);
            }
        };

        $client = new AuthenticatingHttpClient($inner, new BearerToken('secret'));
        $client->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame(['Bearer secret'], $inner->seen);
    }

    #[TestDox('a challenged request is retried once the authenticator makes progress')]
    public function testRetriesAfterAChallenge(): void
    {
        $inner = new RejectingClient(1);
        $client = new AuthenticatingHttpClient($inner, new CountingAuthenticator());

        $response = $client->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $inner->calls);
    }

    #[TestDox('a server that keeps rejecting is given up on rather than looped against')]
    public function testStopsAfterTheAttemptLimit(): void
    {
        $inner = new RejectingClient(\PHP_INT_MAX);
        $authenticator = new CountingAuthenticator();
        $client = new AuthenticatingHttpClient($inner, $authenticator, 3);

        $response = $client->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(3, $authenticator->challenges);
        $this->assertSame(4, $inner->calls);
        $this->assertTrue($response->getBody()->isReadable());
    }

    #[TestDox('an authenticator that cannot make progress ends the exchange immediately')]
    public function testStopsWhenTheAuthenticatorDeclines(): void
    {
        $inner = new RejectingClient(\PHP_INT_MAX);
        $client = new AuthenticatingHttpClient($inner, new BearerToken('secret'));

        $response = $client->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, $inner->calls);
        $this->assertTrue($response->getBody()->isReadable());
    }

    #[TestDox('a rejected response stays readable for the challenge and is closed before the retry')]
    public function testClosesTheRejectedResponseBeforeTheRetry(): void
    {
        $inner = new class implements ClientInterface {
            /** @var ResponseInterface[] */
            public array $responses = [];

            /** @var bool[] */
            public array $previousStillReadable = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ([] !== $this->responses) {
                    $this->previousStillReadable[] = $this->responses[0]->getBody()->isReadable();
                }

                return $this->responses[] = new Response([] === $this->responses ? 401 : 200, [], 'body');
            }
        };
        $authenticator = new CountingAuthenticator();

        $response = (new AuthenticatingHttpClient($inner, $authenticator))
            ->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame([true], $authenticator->readableBodies);
        $this->assertSame([false], $inner->previousStillReadable);
        $this->assertTrue($response->getBody()->isReadable());
    }

    #[TestDox('a body that cannot be replayed ends the exchange after the challenge')]
    public function testDoesNotRetryANonSeekableBody(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isSeekable')->willReturn(false);
        $body->expects($this->never())->method('rewind');

        $inner = new RejectingClient(1);
        $authenticator = new CountingAuthenticator();

        $response = (new AuthenticatingHttpClient($inner, $authenticator))
            ->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp')->withBody($body));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->getBody()->isReadable());
        $this->assertSame(1, $inner->calls);
        $this->assertSame(1, $authenticator->challenges);
    }

    #[TestDox('the request body is replayed rather than sent empty on the retry')]
    public function testRewindsTheBody(): void
    {
        $factory = new Psr17Factory();
        $inner = new class implements ClientInterface {
            /** @var string[] */
            public array $bodies = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->bodies[] = (string) $request->getBody();

                return new Response(1 === \count($this->bodies) ? 401 : 200);
            }
        };

        $request = $factory->createRequest('POST', 'https://mcp.example.com/mcp')
            ->withBody($factory->createStream('{"jsonrpc":"2.0"}'));

        (new AuthenticatingHttpClient($inner, new CountingAuthenticator()))->sendRequest($request);

        $this->assertSame(['{"jsonrpc":"2.0"}', '{"jsonrpc":"2.0"}'], $inner->bodies);
    }

    #[TestDox('the challenge handler sees the credentials the rejected request carried')]
    public function testChallengeSeesTheSentRequest(): void
    {
        $authenticator = new CountingAuthenticator();
        (new AuthenticatingHttpClient(new RejectingClient(1), $authenticator))
            ->sendRequest((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'));

        $this->assertSame(['Bearer token-0'], $authenticator->challenged);
    }

    #[TestDox('an attempt limit below one is rejected')]
    public function testRejectsAnImpossibleAttemptLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthenticatingHttpClient(new RejectingClient(0), new BearerToken('secret'), 0);
    }
}

/**
 * Answers 401 for the first call, 403 from then on, until the quota of rejections runs out.
 */
final class RejectingClient implements ClientInterface
{
    public int $calls = 0;

    public function __construct(private readonly int $rejections)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        if ($this->calls > $this->rejections) {
            return new Response(200);
        }

        return new Response(1 === $this->calls ? 401 : 403, ['WWW-Authenticate' => 'Bearer scope="mcp:admin"']);
    }
}

/**
 * Always claims it can make progress, and counts how often it was asked to.
 */
final class CountingAuthenticator implements AuthenticatorInterface
{
    public int $challenges = 0;

    /** @var string[] The credentials each rejected request went out with. */
    public array $challenged = [];

    /** @var bool[] Whether each rejecting response could still be read during its challenge. */
    public array $readableBodies = [];

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer token-'.$this->challenges);
    }

    public function handleChallenge(RequestInterface $request, ResponseInterface $response): bool
    {
        $this->challenged[] = $request->getHeaderLine('Authorization');
        $this->readableBodies[] = $response->getBody()->isReadable();
        ++$this->challenges;

        return true;
    }
}
