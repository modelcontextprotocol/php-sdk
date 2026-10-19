<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\JsonRpc;

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

    public function testMetaDoesNotOverrideMetaFromParams(): void
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

            protected function getParams(): array
            {
                return [
                    '_meta' => ['key' => 'from-params'],
                ];
            }
        };

        $notification = $notificationImplementation::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/dummy',
            'params' => [
                '_meta' => ['key' => 'from-meta'],
            ],
        ]);

        $expected = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/dummy',
            'params' => [
                '_meta' => ['key' => 'from-params'],
            ],
        ];

        $this->assertSame($expected, $notification->jsonSerialize());
    }
}
