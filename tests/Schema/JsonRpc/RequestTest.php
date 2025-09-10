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

use Mcp\Schema\JsonRpc\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testMetaAndIdAreLoopedThrough(): void
    {
        $requestImplementation = new class extends Request {
            public static function getMethod(): string
            {
                return 'foo/bar';
            }

            public static function fromParams(?array $params): static
            {
                return new self();
            }

            protected function getParams(): ?array
            {
                return null;
            }
        };

        $notification = $requestImplementation::fromArray([
            'jsonrpc' => '2.0',
            'id' => '12345',
            'method' => 'foo/bar',
            'params' => [
                '_meta' => ['key' => 'value'],
            ],
        ]);

        $expectedMeta = [
            'jsonrpc' => '2.0',
            'id' => '12345',
            'method' => 'foo/bar',
            'params' => [
                '_meta' => ['key' => 'value'],
            ],
        ];

        $this->assertSame($expectedMeta, $notification->jsonSerialize());
    }
}
