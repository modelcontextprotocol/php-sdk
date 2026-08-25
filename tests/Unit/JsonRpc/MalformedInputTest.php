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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Type-confused payloads must be reported as invalid input, never as a PHP TypeError, ValueError or
 * warning. Those escape every catch on the way out of the protocol and kill the server process.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class MalformedInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedPayloads(): iterable
    {
        yield 'method is an object' => ['{"jsonrpc":"2.0","id":1,"method":{"evil":true}}'];
        yield 'method is an array' => ['{"jsonrpc":"2.0","id":1,"method":[1,2,3]}'];

        yield 'initialize with array protocolVersion' => ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":[1],"capabilities":{},"clientInfo":{"name":"x","version":"1"}}}'];
        yield 'initialize with string capabilities' => ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":"x","clientInfo":{"name":"x","version":"1"}}}'];
        yield 'initialize with string clientInfo' => ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":"x"}}'];
        yield 'initialize with non-array icons entry' => ['{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"x","version":"1","icons":["x"]}}}'];

        yield 'completion ref/prompt with array name' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/prompt","name":[1]},"argument":{"name":"x","value":"y"}}}'];
        yield 'completion ref/prompt without name' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/prompt"},"argument":{"name":"x","value":"y"}}}'];
        yield 'completion ref/resource with object uri' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/resource","uri":{}},"argument":{"name":"x","value":"y"}}}'];

        yield 'setLevel with unknown enum value' => ['{"jsonrpc":"2.0","id":1,"method":"logging/setLevel","params":{"level":"not-a-real-level"}}'];
        yield 'logging notification with unknown enum value' => ['{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"nope","data":"x"}}'];

        yield 'tools/list with array cursor' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"cursor":[1]}}'];
        yield 'cancelled notification with array reason' => ['{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1,"reason":[1]}}'];
        yield 'progress notification with array total' => ['{"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"t","progress":1,"total":[1]}}'];

        yield 'sampling with string preferences' => ['{"jsonrpc":"2.0","id":1,"method":"sampling/createMessage","params":{"messages":[],"maxTokens":1,"preferences":"x"}}'];
        yield 'sampling with array systemPrompt' => ['{"jsonrpc":"2.0","id":1,"method":"sampling/createMessage","params":{"messages":[],"maxTokens":1,"systemPrompt":[1]}}'];
        yield 'sampling with unknown role' => ['{"jsonrpc":"2.0","id":1,"method":"sampling/createMessage","params":{"messages":[{"role":"nope","content":{"type":"text","text":"x"}}],"maxTokens":1}}'];
        yield 'sampling with array content type' => ['{"jsonrpc":"2.0","id":1,"method":"sampling/createMessage","params":{"messages":[{"role":"user","content":{"type":[1],"text":"x"}}],"maxTokens":1}}'];
        yield 'sampling with non-string stopSequence' => ['{"jsonrpc":"2.0","id":1,"method":"sampling/createMessage","params":{"messages":[],"maxTokens":1,"stopSequences":[[]]}}'];

        yield 'elicitation with string required' => ['{"jsonrpc":"2.0","id":1,"method":"elicitation/create","params":{"message":"m","requestedSchema":{"type":"object","properties":{"a":{"type":"string","title":"T"}},"required":"a"}}}'];
        yield 'elicitation with array in required' => ['{"jsonrpc":"2.0","id":1,"method":"elicitation/create","params":{"message":"m","requestedSchema":{"type":"object","properties":{"a":{"type":"string","title":"T"}},"required":[[]]}}}'];
    }

    #[DataProvider('provideMalformedPayloads')]
    #[TestDox('Malformed payload is reported as invalid input: $_dataName')]
    public function testMalformedPayloadIsReportedAsInvalidInput(string $payload): void
    {
        $results = MessageFactory::make()->create($payload);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[0]);
    }

    #[TestDox('A malformed message in a batch does not discard the valid ones')]
    public function testMalformedMessageInBatchDoesNotDiscardValidMessages(): void
    {
        $payload = '[{"jsonrpc":"2.0","id":1,"method":"tools/list"},{"jsonrpc":"2.0","id":2,"method":{}}]';

        $results = MessageFactory::make()->create($payload);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(\Mcp\Schema\Request\ListToolsRequest::class, $results[0]);
        $this->assertInstanceOf(InvalidInputMessageException::class, $results[1]);
    }
}
