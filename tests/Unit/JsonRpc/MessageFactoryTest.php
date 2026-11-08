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
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Request\PingRequest;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testToolArgumentsKeepTheirJsonTypes(): void
    {
        $message = MessageFactory::make()->create('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"t","arguments":{"object":{},"list":[],"numeric":{"0":"x"},"nested":[{"a":{}}]}}}');

        $this->assertInstanceOf(CallToolRequest::class, $message);
        $this->assertEquals(new \stdClass(), $message->arguments['object']);
        $this->assertSame([], $message->arguments['list']);
        $this->assertEquals((object) ['0' => 'x'], $message->arguments['numeric']);
        $this->assertEquals([(object) ['a' => new \stdClass()]], $message->arguments['nested']);
    }

    public function testRootArgumentsWithNumericKeysStayAnObjectOnTheWire(): void
    {
        $message = MessageFactory::make()->create('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"t","arguments":{"0":"x"}}}');

        $this->assertInstanceOf(CallToolRequest::class, $message);
        $this->assertStringContainsString('"arguments":{"0":"x"}', json_encode($message, \JSON_THROW_ON_ERROR));
    }

    public function testOtherParamsAreDecodedToArrays(): void
    {
        $message = MessageFactory::make()->create('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"x","version":"1"}}}');

        $this->assertInstanceOf(InitializeRequest::class, $message);
    }

    public function testEmptyObjectIsRejected(): void
    {
        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('missing "method"');

        $this->factory->create('{}');
    }

    public function testMalformedJsonAfterAMemberNameStartingWithNulIsAParseError(): void
    {
        $this->expectException(\JsonException::class);

        $this->factory->create('{"id":1,"\u0000x":1,}');
    }

    public function testAnObjectWithNumericKeysIsNotABatch(): void
    {
        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('missing "method"');

        $this->factory->create('{"0":{"jsonrpc":"2.0","id":1,"method":"ping"}}');
    }

    public function testCreateRequestWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "prompts/get", "params": {"name": "create_story"}, "id": 123}';

        /** @var GetPromptRequest $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(GetPromptRequest::class, $result);
        $this->assertSame('prompts/get', $result::getMethod());
        $this->assertSame('create_story', $result->name);
        $this->assertSame(123, $result->getId());
    }

    public function testCreateRequestWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "ping", "id": "abc-123"}';

        /** @var PingRequest $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(PingRequest::class, $result);
        $this->assertSame('ping', $result::getMethod());
        $this->assertSame('abc-123', $result->getId());
    }

    public function testCreateNotification(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": 12345}}';

        /** @var CancelledNotification $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(CancelledNotification::class, $result);
        $this->assertSame('notifications/cancelled', $result::getMethod());
        $this->assertSame(12345, $result->requestId);
    }

    public function testCreateNotificationWithoutParams(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/initialized"}';

        /** @var InitializedNotification $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(InitializedNotification::class, $result);
        $this->assertSame('notifications/initialized', $result::getMethod());
    }

    public function testCreateResponseWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 456, "result": {"content": [{"type": "text", "text": "Hello"}]}}';

        /** @var Response<array<string, mixed>> $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(456, $result->getId());
        $this->assertIsArray($result->result);
        $this->assertArrayHasKey('content', $result->result);
    }

    public function testCreateResponseWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": "response-1", "result": {"status": "ok"}}';

        /** @var Response<array<string, mixed>> $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('response-1', $result->getId());
        $this->assertEquals(['status' => 'ok'], $result->result);
    }

    public function testCreateErrorWithIntegerId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 789, "error": {"code": -32601, "message": "Method not found"}}';

        /** @var Error $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(789, $result->getId());
        $this->assertSame(-32601, $result->code);
        $this->assertSame('Method not found', $result->message);
        $this->assertNull($result->data);
    }

    public function testCreateErrorWithStringId(): void
    {
        $json = '{"jsonrpc": "2.0", "id": "err-1", "error": {"code": -32600, "message": "Invalid request"}}';

        /** @var Error $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame('err-1', $result->getId());
        $this->assertSame(-32600, $result->code);
        $this->assertSame('Invalid request', $result->message);
    }

    public function testCreateErrorWithData(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32000, "message": "Server error", "data": {"details": "Something went wrong"}}}';

        /** @var Error $result */
        $result = $this->factory->create($json);
        $this->assertInstanceOf(Error::class, $result);
        $this->assertEquals(['details' => 'Something went wrong'], $result->data);
    }

    public function testInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->factory->create('invalid json');
    }

    public function testMissingJsonRpcVersion(): void
    {
        $json = '{"method": "ping", "id": 1}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('jsonrpc');

        $this->factory->create($json);
    }

    public function testInvalidJsonRpcVersion(): void
    {
        $json = '{"jsonrpc": "1.0", "method": "ping", "id": 1}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('jsonrpc');

        $this->factory->create($json);
    }

    public function testMissingAllIdentifyingFields(): void
    {
        $json = '{"jsonrpc": "2.0", "params": {}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('missing');

        $this->factory->create($json);
    }

    public function testUnknownMethod(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "unknown/method", "id": 1}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('Unknown method');

        $this->factory->create($json);
    }

    public function testUnknownNotificationMethod(): void
    {
        $json = '{"jsonrpc": "2.0", "method": "notifications/unknown"}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('Unknown method');

        $this->factory->create($json);
    }

    public function testNotificationMethodUsedAsRequest(): void
    {
        // When a notification method is used with an id, it should still create the notification
        // The fromArray validation will handle any issues
        $json = '{"jsonrpc": "2.0", "method": "notifications/initialized", "id": 1}';

        // The notification class will reject the id in fromArray validation
        $this->expectException(InvalidInputMessageException::class);

        $this->factory->create($json);
    }

    public function testErrorMissingId(): void
    {
        // Well-formed: an error response leaves the member out when the id
        // could not be read off the request it answers.
        $json = '{"jsonrpc": "2.0", "error": {"code": -32600, "message": "Invalid"}}';

        $result = $this->factory->create($json);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertNull($result->getId());
    }

    public function testErrorMissingCode(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"message": "Invalid"}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('code');

        $this->factory->create($json);
    }

    public function testErrorMissingMessage(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32600}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('message');

        $this->factory->create($json);
    }

    public function testMakeFactoryWithDefaultMessages(): void
    {
        $factory = MessageFactory::make();
        $json = '{"jsonrpc": "2.0", "method": "ping", "id": 1}';

        $result = $factory->create($json);

        $this->assertInstanceOf(PingRequest::class, $result);
    }

    public function testMakeFactoryParsesElicitationCreate(): void
    {
        $factory = MessageFactory::make();
        $json = '{"jsonrpc": "2.0", "method": "elicitation/create", "id": 1, "params": {"message": "Your name?", "requestedSchema": {"type": "object", "properties": {"name": {"type": "string", "title": "Name"}}, "required": ["name"]}}}';

        /** @var ElicitRequest $result */
        $result = $factory->create($json);
        $this->assertInstanceOf(ElicitRequest::class, $result);
        $this->assertSame('elicitation/create', $result::getMethod());
        $this->assertSame('Your name?', $result->message);
        $this->assertSame(1, $result->getId());
    }

    public function testResponseWithInvalidIdType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": true, "result": {"status": "ok"}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('id');

        $this->factory->create($json);
    }

    public function testErrorWithNullId(): void
    {
        // JSON-RPC 2.0 spells the same thing as an explicit null.
        $json = '{"jsonrpc": "2.0", "id": null, "error": {"code": -32600, "message": "Invalid"}}';

        $result = $this->factory->create($json);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertNull($result->getId());
    }

    public function testErrorWithInvalidIdType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": {"not": "an id"}, "error": {"code": -32600, "message": "Invalid"}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('id');

        $this->factory->create($json);
    }

    public function testResponseWithNonArrayResult(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "result": "not an array"}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('result');

        $this->factory->create($json);
    }

    public function testErrorWithNonArrayErrorField(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": "not an object"}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('error');

        $this->factory->create($json);
    }

    public function testErrorWithInvalidCodeType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": "not-a-number", "message": "Invalid"}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('code');

        $this->factory->create($json);
    }

    public function testErrorWithInvalidMessageType(): void
    {
        $json = '{"jsonrpc": "2.0", "id": 1, "error": {"code": -32600, "message": 123}}';

        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('message');

        $this->factory->create($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInputsThatAreNotOneObject(): iterable
    {
        yield 'integer' => ['5'];
        yield 'string' => ['"hello"'];
        yield 'null' => ['null'];
        yield 'empty array' => ['[]'];
        yield 'list of scalars' => ['[1, 2]'];
        yield 'batch' => ['[{"jsonrpc": "2.0", "method": "ping", "id": 1}, {"jsonrpc": "2.0", "method": "ping", "id": 2}]'];
        yield 'batch of one' => ['[{"jsonrpc": "2.0", "method": "ping", "id": 1}]'];
    }

    #[DataProvider('provideInputsThatAreNotOneObject')]
    public function testInputThatIsNotOneObjectIsRejected(string $input): void
    {
        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('must be a single JSON object');

        $this->factory->create($input);
    }

    /**
     * @return iterable<string, array{string, string|int|null}>
     */
    public static function provideInvalidMessagesAndTheirIds(): iterable
    {
        yield 'integer id' => ['{"jsonrpc": "2.0", "method": "unknown/method", "id": 7}', 7];
        yield 'string id' => ['{"jsonrpc": "2.0", "method": "unknown/method", "id": "a"}', 'a'];
        yield 'no id' => ['{"jsonrpc": "2.0", "method": "unknown/method"}', null];
        yield 'malformed id' => ['{"jsonrpc": "2.0", "method": "unknown/method", "id": [1]}', null];
    }

    #[DataProvider('provideInvalidMessagesAndTheirIds')]
    public function testInvalidMessageCarriesItsRequestId(string $input, string|int|null $id): void
    {
        try {
            $this->factory->create($input);
        } catch (InvalidInputMessageException $e) {
            $this->assertSame($id, $e->getRequestId());

            return;
        }

        $this->fail('The message should have been rejected.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonStringMethods(): iterable
    {
        yield 'object' => ['{"evil": true}'];
        yield 'array' => ['[1, 2, 3]'];
        yield 'int' => ['5'];
        yield 'bool' => ['true'];
    }

    #[DataProvider('provideNonStringMethods')]
    public function testNonStringMethodIsRejected(string $method): void
    {
        $this->expectException(InvalidInputMessageException::class);
        $this->expectExceptionMessage('"method" must be a string');

        $this->factory->create(\sprintf('{"jsonrpc": "2.0", "id": 1, "method": %s}', $method));
    }

    public function testLeadingWhitespaceObjectIsParsedAsSingleMessage(): void
    {
        $json = "  \n  {\"jsonrpc\": \"2.0\", \"method\": \"ping\", \"id\": 1}";

        $result = $this->factory->create($json);

        $this->assertInstanceOf(PingRequest::class, $result);
    }
}
