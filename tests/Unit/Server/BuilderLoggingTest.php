<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MCP logging capability configuration through the Builder.
 *
 * This consolidates all builder logging tests to avoid duplication and
 * focuses on essential scenarios for comprehensive coverage.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class BuilderLoggingTest extends TestCase
{
    public function testLoggingEnabledByDefault(): void
    {
        $builder = new Builder();

        $this->assertTrue($this->getBuilderLoggingState($builder), 'Builder should start with logging enabled');

        $server = $builder->setServerInfo('Test Server', '1.0.0')->build();
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testDisableClientLoggingConfiguresBuilder(): void
    {
        $builder = new Builder();

        $result = $builder->disableClientLogging();

        // Test method chaining
        $this->assertSame($builder, $result, 'disableClientLogging should return builder for chaining');

        // Test internal state
        $this->assertFalse($this->getBuilderLoggingState($builder), 'disableClientLogging should set internal flag');

        // Test server builds successfully
        $server = $builder->setServerInfo('Test Server', '1.0.0')->build();
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testMultipleDisableCallsAreIdempotent(): void
    {
        $builder = new Builder();

        $builder->disableClientLogging()
            ->disableClientLogging()
            ->disableClientLogging();

        $this->assertFalse($this->getBuilderLoggingState($builder), 'Multiple disable calls should maintain disabled state');
    }

    public function testLoggingStatePreservedAcrossBuilds(): void
    {
        $builder = new Builder();
        $builder->setServerInfo('Test Server', '1.0.0')->disableClientLogging();

        $server1 = $builder->build();
        $server2 = $builder->build();

        // State should persist after building
        $this->assertFalse($this->getBuilderLoggingState($builder), 'Builder state should persist after builds');
        $this->assertInstanceOf(Server::class, $server1);
        $this->assertInstanceOf(Server::class, $server2);
    }

    /**
     * Get the internal logging state of the builder using reflection.
     * This directly tests the builder's internal configuration.
     */
    private function getBuilderLoggingState(Builder $builder): bool
    {
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('logging');
        $property->setAccessible(true);

        return $property->getValue($builder);
    }
}
