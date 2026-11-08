<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\OAuth;

use Mcp\Exception\JwksRefreshRateLimitException;
use Mcp\Server\Transport\Http\OAuth\RateLimitedJwksClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RateLimitedJwksClientTest extends TestCase
{
    private const JWKS_URI = 'https://auth.example.com/jwks';

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    public function testThrowsWithRemainingWindowAfterTenRequests(): void
    {
        $client = new RateLimitedJwksClient($this->upstream(10), new ArrayAdapter());
        $this->sendRequests($client, 10);

        try {
            $client->sendRequest($this->factory->createRequest('GET', self::JWKS_URI));
            $this->fail('The eleventh request must not reach the upstream client.');
        } catch (JwksRefreshRateLimitException $e) {
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
            $this->assertLessThanOrEqual(60, $e->retryAfter);
        }
    }

    public function testSharesTheBudgetThroughTheCache(): void
    {
        $cache = new ArrayAdapter();
        $this->sendRequests(new RateLimitedJwksClient($this->upstream(10), $cache), 10);

        $this->expectException(JwksRefreshRateLimitException::class);

        (new RateLimitedJwksClient($this->upstream(0), $cache))->sendRequest($this->factory->createRequest('GET', self::JWKS_URI));
    }

    public function testCountsFailedResponses(): void
    {
        $upstream = $this->createMock(ClientInterface::class);
        $upstream->expects($this->exactly(10))->method('sendRequest')->willReturn($this->factory->createResponse(503));
        $client = new RateLimitedJwksClient($upstream, new ArrayAdapter());
        $this->sendRequests($client, 10);

        $this->expectException(JwksRefreshRateLimitException::class);

        $client->sendRequest($this->factory->createRequest('GET', self::JWKS_URI));
    }

    public function testFetchesAgainOnceTheWindowIsGone(): void
    {
        $cache = new ArrayAdapter();
        $client = new RateLimitedJwksClient($this->upstream(11), $cache);
        $this->sendRequests($client, 10);

        $cache->clear();

        $this->assertSame(200, $client->sendRequest($this->factory->createRequest('GET', self::JWKS_URI))->getStatusCode());
    }

    public function testKeepsSeparateBudgetsPerUri(): void
    {
        $cache = new ArrayAdapter();
        $this->sendRequests(new RateLimitedJwksClient($this->upstream(10), $cache), 10);

        $response = (new RateLimitedJwksClient($this->upstream(1), $cache))->sendRequest($this->factory->createRequest('GET', 'https://other.example.com/jwks'));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function sendRequests(ClientInterface $client, int $count): void
    {
        for ($attempt = 0; $attempt < $count; ++$attempt) {
            $client->sendRequest($this->factory->createRequest('GET', self::JWKS_URI));
        }
    }

    private function upstream(int $expectedRequests): ClientInterface
    {
        $upstream = $this->createMock(ClientInterface::class);
        $upstream->expects($this->exactly($expectedRequests))->method('sendRequest')->willReturn($this->factory->createResponse());

        return $upstream;
    }
}
