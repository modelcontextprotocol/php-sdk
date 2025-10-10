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

    public function testLoggingDisabledByDefault(): void
    {
        $this->assertFalse($this->registry->isLoggingMessageNotificationEnabled());
    }

    public function testLoggingStateEnablement(): void
    {
        // Logging starts disabled
        $this->assertFalse($this->registry->isLoggingMessageNotificationEnabled());

        // Test enabling logging
        $this->registry->enableLoggingMessageNotification();
        $this->assertTrue($this->registry->isLoggingMessageNotificationEnabled());

        // Enabling again should have no effect
        $this->registry->enableLoggingMessageNotification();
        $this->assertTrue($this->registry->isLoggingMessageNotificationEnabled());

        // Create new instances to test disabled state
        for ($i = 0; $i < 3; ++$i) {
            $newRegistry = new Registry(null, $this->logger);
            $this->assertFalse($newRegistry->isLoggingMessageNotificationEnabled());
            $newRegistry->enableLoggingMessageNotification();
            $this->assertTrue($newRegistry->isLoggingMessageNotificationEnabled());
        }
    }

    public function testLogLevelManagement(): void
    {
        // Initially should be null
        $this->assertNull($this->registry->getLoggingMessageNotificationLevel());

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
            $this->registry->setLoggingMessageNotificationLevel($level);
            $this->assertEquals($level, $this->registry->getLoggingMessageNotificationLevel());

            // Verify enum properties are preserved
            $retrievedLevel = $this->registry->getLoggingMessageNotificationLevel();
            $this->assertEquals($level->value, $retrievedLevel->value);
            $this->assertEquals($level->getSeverityIndex(), $retrievedLevel->getSeverityIndex());
        }

        // Final state should be the last level
        $this->assertEquals(LoggingLevel::Emergency, $this->registry->getLoggingMessageNotificationLevel());

        // Test multiple level changes
        $changeLevels = [
            LoggingLevel::Debug,
            LoggingLevel::Warning,
            LoggingLevel::Critical,
            LoggingLevel::Info,
        ];

        foreach ($changeLevels as $level) {
            $this->registry->setLoggingMessageNotificationLevel($level);
            $this->assertEquals($level, $this->registry->getLoggingMessageNotificationLevel());
        }
    }

    public function testGetLogLevelReturnsNullWhenNotSet(): void
    {
        // Verify default state
        $this->assertNull($this->registry->getLoggingMessageNotificationLevel());

        // Enable logging but don't set level
        $this->registry->enableLoggingMessageNotification();
        $this->assertNull($this->registry->getLoggingMessageNotificationLevel());
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
        $this->assertFalse($capabilities->logging);

        // Enable logging and test capabilities
        $this->registry->enableLoggingMessageNotification();
        $capabilities = $this->registry->getCapabilities();
        $this->assertTrue($capabilities->logging);

        // Test with event dispatcher
        /** @var EventDispatcherInterface&MockObject $eventDispatcher */
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $registryWithDispatcher = new Registry($eventDispatcher, $this->logger);
        $registryWithDispatcher->enableLoggingMessageNotification();

        $capabilities = $registryWithDispatcher->getCapabilities();
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->toolsListChanged);
        $this->assertTrue($capabilities->resourcesListChanged);
        $this->assertTrue($capabilities->promptsListChanged);
    }

    public function testLoggingStateIndependentOfLevel(): void
    {
        // Logging can be enabled without setting a level
        $this->registry->enableLoggingMessageNotification();
        $this->assertTrue($this->registry->isLoggingMessageNotificationEnabled());
        $this->assertNull($this->registry->getLoggingMessageNotificationLevel());

        // Level can be set after enabling logging
        $this->registry->setLoggingMessageNotificationLevel(LoggingLevel::Info);
        $this->assertTrue($this->registry->isLoggingMessageNotificationEnabled());
        $this->assertEquals(LoggingLevel::Info, $this->registry->getLoggingMessageNotificationLevel());

        // Level can be set on a new registry without enabling logging
        $newRegistry = new Registry(null, $this->logger);
        $newRegistry->setLoggingMessageNotificationLevel(LoggingLevel::Info);
        $this->assertFalse($newRegistry->isLoggingMessageNotificationEnabled());
        $this->assertEquals(LoggingLevel::Info, $newRegistry->getLoggingMessageNotificationLevel());

        // Test persistence: Set level then enable logging - level should persist
        $persistRegistry = new Registry(null, $this->logger);
        $persistRegistry->setLoggingMessageNotificationLevel(LoggingLevel::Critical);
        $this->assertEquals(LoggingLevel::Critical, $persistRegistry->getLoggingMessageNotificationLevel());

        $persistRegistry->enableLoggingMessageNotification();
        $this->assertTrue($persistRegistry->isLoggingMessageNotificationEnabled());
        $this->assertEquals(LoggingLevel::Critical, $persistRegistry->getLoggingMessageNotificationLevel());
    }

    public function testRegistryIntegration(): void
    {
        // Test registry with default constructor
        $defaultRegistry = new Registry();
        $this->assertFalse($defaultRegistry->isLoggingMessageNotificationEnabled());
        $this->assertNull($defaultRegistry->getLoggingMessageNotificationLevel());

        // Test integration with other registry functionality
        $this->registry->enableLoggingMessageNotification();
        $this->registry->setLoggingMessageNotificationLevel(LoggingLevel::Error);

        // Verify logging state doesn't interfere with other functionality
        $this->assertTrue($this->registry->isLoggingMessageNotificationEnabled());
        $this->assertEquals(LoggingLevel::Error, $this->registry->getLoggingMessageNotificationLevel());

        // Basic capability check
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('No capabilities registered on server.');

        $capabilities = $this->registry->getCapabilities();
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->completions);
    }
}
