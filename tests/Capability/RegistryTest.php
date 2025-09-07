<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability;

use Mcp\Capability\Registry;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\Tool;
use Mcp\Server\NotificationPublisher;
use PHPUnit\Framework\TestCase;

class RegistryTest extends TestCase
{
    public function testToolRegistration(): void
    {
        $tool = new Tool(
            'the-best-tool-name-ever',
            [
                'type' => 'object',
                'properties' => [
                    'param1' => ['type' => 'string'],
                ],
                'required' => [
                    'param1',
                ],
            ],
            null,
            null
        );

        $expected = [$tool->name => $tool];

        $notificationPublisher = $this->createMock(NotificationPublisher::class);
        $notificationPublisher->expects($this->once())
            ->method('enqueue')
            ->with(ToolListChangedNotification::class);

        $registry = new Registry($notificationPublisher);
        $registry->registerTool($tool, fn () => null);

        $this->assertSame($expected, $registry->getTools());
    }

    public function testResourceRegistration(): void
    {
        $resource = new Resource(
            'config://the-best-resource-uri-ever',
            'the-best-resource-name-ever',
        );

        $expected = [$resource->uri => $resource];

        $notificationPublisher = $this->createMock(NotificationPublisher::class);
        $notificationPublisher->expects($this->once())
            ->method('enqueue')
            ->with(ResourceListChangedNotification::class);

        $registry = new Registry($notificationPublisher);
        $registry->registerResource($resource, fn () => null);

        $this->assertSame($expected, $registry->getResources());
    }

    public function testPromptRegistration(): void
    {
        $prompt = new Prompt(
            'the-best-prompt-ever',
        );

        $expected = [$prompt->name => $prompt];

        $notificationPublisher = $this->createMock(NotificationPublisher::class);
        $notificationPublisher->expects($this->once())
            ->method('enqueue')
            ->with(PromptListChangedNotification::class);

        $registry = new Registry($notificationPublisher);
        $registry->registerPrompt($prompt, fn () => null);

        $this->assertSame($expected, $registry->getPrompts());
    }
}
