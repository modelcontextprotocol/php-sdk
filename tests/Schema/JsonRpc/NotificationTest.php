<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Schema\JsonRpc;

use Mcp\Schema\JsonRpc\Notification;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testMetaIsLoopedThrough(): void
    {
        $notificationImplementation = new class extends Notification {
            public static function getMethod(): string
            {
                return 'notifications/dummy';
            }

            public static function fromParams(?array $params): self
            {
                return new self();
            }

            protected function getParams(): ?array
            {
                return null;
            }
        };

        $notification = $notificationImplementation::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/dummy',
            'params' => [
                '_meta' => ['key' => 'value'],
            ],
        ]);

        $expectedMeta = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/dummy',
            'params' => [
                '_meta' => ['key' => 'value'],
            ],
        ];

        $this->assertSame($expectedMeta, $notification->jsonSerialize());
    }
}
