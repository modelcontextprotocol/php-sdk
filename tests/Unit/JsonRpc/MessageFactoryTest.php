<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\JsonRpc;

use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\PingRequest;
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
            PingRequest::class,
        ]);
    }

    public function testCreateRequestWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "prompts/get", "params": {"name": "create_story"}, "id": 123}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var GetPromptRequest $result */
        $result = $results[0];
        $this->assertInstanceOf(GetPromptRequest::class, $result);
        $this->assertSame('prompts/get', $result::getMethod());
        $this->assertSame('create_story', $result->name);
        $this->assertSame(123, $result->getId());
    }

    public function testCreateRequestWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "ping", "id": "abc-123"}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var PingRequest $result */
        $result = $results[0];
        $this->assertInstanceOf(PingRequest::class, $result);
        $this->assertSame('ping', $result::getMethod());
        $this->assertSame('abc-123', $result->getId());
    }

    public function testCreateNotification(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": 12345}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var CancelledNotification $result */
        $result = $results[0];
        $this->assertInstanceOf(CancelledNotification::class, $result);
        $this->assertSame('notifications/cancelled', $result::getMethod());
        $this->assertSame(12345, $result->requestId);
    }

    public function testCreateNotificationWithoutParams(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/initialized"}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var InitializedNotification $result */
        $result = $results[0];
        $this->assertInstanceOf(InitializedNotification::class, $result);
        $this->assertSame('notifications/initialized', $result::getMethod());
    }

    public function testCreateResponseWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 456, "result": {"content": [{"type": "text", "text": "Hello"}]}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var Response<array<string, mixed>> $result */
        $result = $results[0];
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(456, $result->getId());
        $this->assertIsArray($result->result);
        $this->assertArrayHasKey('content', $result->result);
    }

    public function testCreateResponseWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": "response-1", "result": {"status": "ok"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var Response<array<string, mixed>> $result */
        $result = $results[0];
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('response-1', $result->getId());
        $this->assertEquals(['status' => 'ok'], $result->result);
    }

    public function testCreateErrorWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 789, "error": {"code": -32601, "message": "Method not found"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var Error $result */
        $result = $results[0];
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(789, $result->getId());
        $this->assertSame(-32601, $result->code);
        $this->assertSame('Method not found', $result->message);
        $this->assertNull($result->data);
    }

    public function testCreateErrorWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": "err-1", "error": {"code": -32600, "message": "Invalid request"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var Error $result */
        $result = $results[0];
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame('err-1', $result->getId());
        $this->assertSame(-32600, $result->code);
        $this->assertSame('Invalid request', $result->message);
    }

    public function testCreateErrorWithData(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32000, "message": "Server error", "data": {"details": "Something went wrong"}}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        /** @var Error $result */
        $result = $results[0];
        $this->assertInstanceOf(Error::class, $result);
        $this->assertEquals(['details' => 'Something went wrong'], $result->data);
    }

    public function testBatchRequests(): void
    {
        $json = '[
            {"jsonrpc": "2.0", "method": "ping", "id": 1},
            {"jsonrpc": "2.0", "method": "prompts/get", "params": {"name": "test"}, "id": 2},
            {"jsonrpc": "2.0", "method": "notifications/initialized"}
        ]';

        $results = $this->factory->create($json);

        $this->assertCount(3, $results);
        $this->assertInstanceOf(PingRequest::class, $results[0]);
        $this->assertInstanceOf(GetPromptRequest::class, $results[1]);
        $this->assertInstanceOf(InitializedNotification::class, $results[2]);
    }

    public function testBatchWithMixedMessages(): void
    {
        $json = '[
            {"jsonrpc": "2.0", "method": "ping", "id": 1},
            {"jsonrpc": "2.0", "id": 2, "result": {"status": "ok"}},
            {"jsonrpc": "2.0", "id": 3, "error": {"code": -32600, "message": "Invalid"}},
            {"jsonrpc": "2.0", "method": "notifications/initialized"}
        ]';

        $results = $this->factory->create($json);

        $this->assertCount(4, $results);
        $this->assertInstanceOf(PingRequest::class, $results[0]);
        $this->assertInstanceOf(Response::class, $results[1]);
        $this->assertInstanceOf(Error::class, $results[2]);
        $this->assertInstanceOf(InitializedNotification::class, $results[3]);
    }

    public function testInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->factory->create('invalid json');
    }

    public function testMissingJsonRpcVersion(): void
    {
        $json = '{"method": "ping", "id": 1}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('jsonrpc', $results[0]->getMessage());
    }

    public function testInvalidJsonRpcVersion(): void
    {
        $json = '{"jsonrpc": "1.0", "method": "ping", "id": 1}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('jsonrpc', $results[0]->getMessage());
    }

    public function testMissingAllIdentifyingFields(): void
    {
        $json = '{"jsonrpc": "2.0", "params": {}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('missing', $results[0]->getMessage());
    }

    public function testUnknownMethod(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "unknown/method", "id": 1}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('Unknown method', $results[0]->getMessage());
    }

    public function testUnknownNotificationMethod(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/unknown"}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('Unknown method', $results[0]->getMessage());
    }

    public function testNotificationMethodUsedAsRequest(): void
    {
        // When a notification method is used with an id, it should still create the notification
        // The fromArray validation will handle any issues
        $json = '{"jsonrpc": "2.0", "method": "notifications/initialized", "id": 1}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        // The notification class will reject the id in fromArray validation
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
    }

    public function testErrorMissingId(): void
    {
        $json = '{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('id', $results[0]->getMessage());
    }

    public function testErrorMissingCode(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"message": "Invalid"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('code', $results[0]->getMessage());
    }

    public function testErrorMissingMessage(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32600}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('message', $results[0]->getMessage());
    }

    public function testBatchWithErrors(): void
    {
        $json = '[
            {"jsonrpc": "2.0", "method": "ping", "id": 1},
            {"jsonrpc": "2.0", "params": {}, "id": 2},
            {"jsonrpc": "2.0", "method": "unknown/method", "id": 3},
            {"jsonrpc": "2.0", "method": "notifications/initialized"}
        ]';

        $results = $this->factory->create($json);

        $this->assertCount(4, $results);
        $this->assertInstanceOf(PingRequest::class, $results[0]);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[1]);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[2]);
        $this->assertInstanceOf(InitializedNotification::class, $results[3]);
    }

    public function testMakeFactoryWithDefaultMessages(): void
    {
        $factory = MessageFactory::make();
        $json = '{"jsonrpc": "2.0", "method": "ping", "id": 1}';

        $results = $factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PingRequest::class, $results[0]);
    }

    public function testResponseWithInvalidIdType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": true, "result": {"status": "ok"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('id', $results[0]->getMessage());
    }

    public function testErrorWithInvalidIdType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": null, "error": {"code": -32600, "message": "Invalid"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('id', $results[0]->getMessage());
    }

    public function testResponseWithNonArrayResult(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "result": "not an array"}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('result', $results[0]->getMessage());
    }

    public function testErrorWithNonArrayErrorField(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": "not an object"}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('error', $results[0]->getMessage());
    }

    public function testErrorWithInvalidCodeType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": "not-a-number", "message": "Invalid"}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('code', $results[0]->getMessage());
    }

    public function testErrorWithInvalidMessageType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32600, "message": 123}}';

        $results = $this->factory->create($json);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
        $this->assertStringContainsString('message', $results[0]->getMessage());
    }
}
