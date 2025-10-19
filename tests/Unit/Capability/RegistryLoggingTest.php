<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability;

use Mcp\Capability\Registry;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ServerCapabilities;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class RegistryLoggingTest extends TestCase
{
    private Registry $registry;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new Registry(null, $this->logger);
    }

    public function testLoggingDEnabledByDefault(): void
    {
        $this->assertTrue($this->registry->isLoggingEnabled());
    }

    public function testLoggingStateEnablement(): void
    {
        // Logging starts disabled
        $this->assertTrue($this->registry->isLoggingEnabled());

        // Test enabling logging
        $this->registry->disableLogging();
        $this->assertFalse($this->registry->isLoggingEnabled());

        // Enabling again should have no effect
        $this->registry->disableLogging();
        $this->assertFalse($this->registry->isLoggingEnabled());
    }

    public function testGetLogLevelReturnsWarningWhenNotSet(): void
    {
        $this->assertEquals(LoggingLevel::Warning->value, $this->registry->getLoggingLevel()->value);
    }

    public function testLogLevelManagement(): void
    {
        // Initially should be null
        $this->assertEquals(LoggingLevel::Warning->value, $this->registry->getLoggingLevel()->value);

        // Test setting and getting each log level
        $levels = [
            LoggingLevel::Debug,
            LoggingLevel::Info,
            LoggingLevel::Notice,
            LoggingLevel::Warning,
            LoggingLevel::Error,
            LoggingLevel::Critical,
            LoggingLevel::Alert,
            LoggingLevel::Emergency,
        ];

        foreach ($levels as $level) {
            $this->registry->setLoggingLevel($level);
            $this->assertEquals($level, $this->registry->getLoggingLevel());

            // Verify enum properties are preserved
            $retrievedLevel = $this->registry->getLoggingLevel();
            $this->assertEquals($level->value, $retrievedLevel->value);
            $this->assertEquals($level->getSeverityIndex(), $retrievedLevel->getSeverityIndex());
        }

        // Final state should be the last level
        $this->assertEquals(LoggingLevel::Emergency, $this->registry->getLoggingLevel());
    }

    public function testLoggingCapabilities(): void
    {
        // Test capabilities with logging disabled (default state)
        $this->logger
            ->expects($this->exactly(3))
            ->method('info')
            ->with('No capabilities registered on server.');

        $capabilities = $this->registry->getCapabilities();
        $this->assertInstanceOf(ServerCapabilities::class, $capabilities);
        $this->assertTrue($capabilities->logging);

        // Enable logging and test capabilities
        $this->registry->disableLogging();
        $capabilities = $this->registry->getCapabilities();
        $this->assertFalse($capabilities->logging);

        // Test with event dispatcher
        /** @var EventDispatcherInterface&MockObject $eventDispatcher */
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $registryWithDispatcher = new Registry($eventDispatcher, $this->logger);
        $registryWithDispatcher->disableLogging();

        $capabilities = $registryWithDispatcher->getCapabilities();
        $this->assertFalse($capabilities->logging);
        $this->assertTrue($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->promptsListChanged);
    }

    public function testLoggingStateIndependentOfLevel(): void
    {
        // Logging can be disabled - level should remain but logging should be disabled
        $this->registry->disableLogging();
        $this->assertFalse($this->registry->isLoggingEnabled());
        $this->assertEquals(LoggingLevel::Warning, $this->registry->getLoggingLevel()); // Default level

        // Level can be set after disabling logging
        $this->registry->setLoggingLevel(LoggingLevel::Info);
        $this->assertFalse($this->registry->isLoggingEnabled());
        $this->assertEquals(LoggingLevel::Info, $this->registry->getLoggingLevel());

        // Level can be set on a new registry without disabling logging
        $newRegistry = new Registry(null, $this->logger);
        $newRegistry->setLoggingLevel(LoggingLevel::Info);
        $this->assertTrue($newRegistry->isLoggingEnabled());
        $this->assertEquals(LoggingLevel::Info, $newRegistry->getLoggingLevel());

        // Test persistence: Set level then disable logging - level should persist
        $persistRegistry = new Registry(null, $this->logger);
        $persistRegistry->setLoggingLevel(LoggingLevel::Critical);
        $this->assertEquals(LoggingLevel::Critical, $persistRegistry->getLoggingLevel());

        $persistRegistry->disableLogging();
        $this->assertFalse($persistRegistry->isLoggingEnabled());
        $this->assertEquals(LoggingLevel::Critical, $persistRegistry->getLoggingLevel());
    }
}
