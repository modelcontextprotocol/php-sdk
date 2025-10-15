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
use Psr\Log\LoggerInterface;

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
    public function testLoggingDisabledByDefault(): void
    {
        $builder = new Builder();

        $this->assertFalse($this->getBuilderLoggingState($builder), 'Builder should start with logging disabled');

        $server = $builder->setServerInfo('Test Server', '1.0.0')->build();
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testEnableClientLoggingConfiguresBuilder(): void
    {
        $builder = new Builder();

        $result = $builder->enableClientLogging();

        // Test method chaining
        $this->assertSame($builder, $result, 'enableClientLogging should return builder for chaining');

        // Test internal state
        $this->assertTrue($this->getBuilderLoggingState($builder), 'enableClientLogging should set internal flag');

        // Test server builds successfully
        $server = $builder->setServerInfo('Test Server', '1.0.0')->build();
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testMultipleEnableCallsAreIdempotent(): void
    {
        $builder = new Builder();

        $builder->enableClientLogging()
                ->enableClientLogging()
                ->enableClientLogging();

        $this->assertTrue($this->getBuilderLoggingState($builder), 'Multiple enable calls should maintain enabled state');
    }

    public function testLoggingStatePreservedAcrossBuilds(): void
    {
        $builder = new Builder();
        $builder->setServerInfo('Test Server', '1.0.0')->enableClientLogging();

        $server1 = $builder->build();
        $server2 = $builder->build();

        // State should persist after building
        $this->assertTrue($this->getBuilderLoggingState($builder), 'Builder state should persist after builds');
        $this->assertInstanceOf(Server::class, $server1);
        $this->assertInstanceOf(Server::class, $server2);
    }

    public function testLoggingWithOtherBuilderConfiguration(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        \assert($logger instanceof LoggerInterface);
        $builder = new Builder();

        $server = $builder
            ->setServerInfo('Test Server', '1.0.0', 'Test description')
            ->setLogger($logger)
            ->enableClientLogging()
            ->setPaginationLimit(50)
            ->addTool(fn () => 'test', 'test_tool', 'Test tool')
            ->build();

        $this->assertInstanceOf(Server::class, $server);
        $this->assertTrue($this->getBuilderLoggingState($builder), 'Logging should work with other configurations');
    }

    public function testIndependentBuilderInstances(): void
    {
        $builderWithLogging = new Builder();
        $builderWithoutLogging = new Builder();

        $builderWithLogging->enableClientLogging();
        // Don't enable on second builder

        $this->assertTrue($this->getBuilderLoggingState($builderWithLogging), 'First builder should have logging enabled');
        $this->assertFalse($this->getBuilderLoggingState($builderWithoutLogging), 'Second builder should have logging disabled');

        // Both should build successfully
        $server1 = $builderWithLogging->setServerInfo('Test1', '1.0.0')->build();
        $server2 = $builderWithoutLogging->setServerInfo('Test2', '1.0.0')->build();

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
        $property = $reflection->getProperty('loggingMessageNotificationEnabled');
        $property->setAccessible(true);

        return $property->getValue($builder);
    }
}
