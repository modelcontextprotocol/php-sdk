<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client;
use Mcp\Client\Configuration;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    #[TestDox('a non-positive initialization timeout of $seconds seconds is rejected')]
    #[DataProvider('provideNonPositiveTimeouts')]
    public function testNonPositiveInitTimeoutIsRejected(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The initialization timeout must be a positive number of seconds, got %d.', $seconds));

        $this->createConfiguration(initTimeout: $seconds);
    }

    #[TestDox('a non-positive request timeout of $seconds seconds is rejected')]
    #[DataProvider('provideNonPositiveTimeouts')]
    public function testNonPositiveRequestTimeoutIsRejected(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The request timeout must be a positive number of seconds, got %d.', $seconds));

        $this->createConfiguration(requestTimeout: $seconds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideNonPositiveTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[TestDox('the builder rejects a non-positive initialization timeout')]
    public function testBuilderRejectsNonPositiveInitTimeout(): void
    {
        $builder = Client::builder()->setInitTimeout(0);

        $this->expectException(InvalidArgumentException::class);

        $builder->build();
    }

    #[TestDox('the builder rejects a non-positive request timeout')]
    public function testBuilderRejectsNonPositiveRequestTimeout(): void
    {
        $builder = Client::builder()->setRequestTimeout(-5);

        $this->expectException(InvalidArgumentException::class);

        $builder->build();
    }

    #[TestDox('positive timeouts are accepted')]
    public function testPositiveTimeoutsAreAccepted(): void
    {
        $config = $this->createConfiguration(initTimeout: 1, requestTimeout: 1);

        $this->assertSame(1, $config->initTimeout);
        $this->assertSame(1, $config->requestTimeout);
    }

    private function createConfiguration(int $initTimeout = 30, int $requestTimeout = 120): Configuration
    {
        return new Configuration(
            clientInfo: new Implementation('test-client', '1.0.0'),
            capabilities: new ClientCapabilities(),
            initTimeout: $initTimeout,
            requestTimeout: $requestTimeout,
        );
    }
}
