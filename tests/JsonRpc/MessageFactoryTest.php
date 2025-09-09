<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\JsonRpc;

use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\GetPromptRequest;
use PHPUnit\Framework\TestCase;

final class MessageFactoryTest extends TestCase
{
    private MessageFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MessageFactory([
            CancelledNotification::class,
            InitializedNotification::class,
            GetPromptRequest::class,
        ]);
    }

    public function testCreateRequest()
    {
        $json = '{"jsonrpc": "2.0", "method": "prompts/get", "params": {"name": "create_story"}, "id": 123}';

        $result = $this->first($this->factory->create($json));

        $this->assertInstanceOf(GetPromptRequest::class, $result);
        $this->assertSame('prompts/get', $result::getMethod());
        $this->assertSame('create_story', $result->name);
        $this->assertSame(123, $result->getId());
    }

    public function testCreateNotification()
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": 12345}}';

        $result = $this->first($this->factory->create($json));

        $this->assertInstanceOf(CancelledNotification::class, $result);
        $this->assertSame('notifications/cancelled', $result::getMethod());
        $this->assertSame(12345, $result->requestId);
    }

    public function testInvalidJson()
    {
        $this->expectException(\JsonException::class);

        $this->first($this->factory->create('invalid json'));
    }

    public function testMissingMethod()
    {
        $result = $this->first($this->factory->create('{"jsonrpc": "2.0", "params": {}, "id": 1}'));
        $this->assertInstanceOf(InvalidInputMessageException::class, $result);
        $this->assertEquals('Invalid JSON-RPC request, missing valid "method".', $result->getMessage());
    }

    public function testBatchMissingMethod()
    {
        $results = $this->factory->create('[{"jsonrpc": "2.0", "params": {}, "id": 1}, {"jsonrpc": "2.0", "method": "notifications/initialized", "params": {}}]');

        $results = iterator_to_array($results);
        $result = array_shift($results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $result);
        $this->assertEquals('Invalid JSON-RPC request, missing valid "method".', $result->getMessage());

        $result = array_shift($results);
        $this->assertInstanceOf(InitializedNotification::class, $result);
    }

    /**
     * @param iterable<mixed> $items
     */
    private function first(iterable $items): mixed
    {
        foreach ($items as $item) {
            return $item;
        }

        return null;
    }
}
