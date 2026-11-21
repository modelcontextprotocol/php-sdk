<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Request;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\ResourceReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletionCompleteRequestTest extends TestCase
{
    public function testFromArrayReadsAPromptReference(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/prompt', 'name' => 'book_seat'],
            'argument' => ['name' => 'seat', 'value' => '12'],
        ]);

        $this->assertInstanceOf(PromptReference::class, $request->ref);
        $this->assertSame('book_seat', $request->ref->name);
        $this->assertSame(['name' => 'seat', 'value' => '12'], $request->argument);
    }

    public function testFromArrayReadsAResourceReference(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/resource', 'uri' => 'user://{userId}/profile'],
            'argument' => ['name' => 'userId', 'value' => '10'],
        ]);

        $this->assertInstanceOf(ResourceReference::class, $request->ref);
        $this->assertSame('user://{userId}/profile', $request->ref->uri);
    }

    /**
     * An empty value is the client asking what every completion is, which is a
     * question worth answering — unlike a value that is not there at all.
     */
    public function testEmptyArgumentValueIsAccepted(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/prompt', 'name' => 'book_seat'],
            'argument' => ['name' => 'seat', 'value' => ''],
        ]);

        $this->assertSame(['name' => 'seat', 'value' => ''], $request->argument);
    }

    public function testContextIsAbsentWhenNotSent(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code_review'],
            'argument' => ['name' => 'framework', 'value' => 'fla'],
        ]);

        $this->assertNull($request->context);
    }

    public function testFromArrayReadsContextArguments(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code_review'],
            'argument' => ['name' => 'framework', 'value' => 'fla'],
            'context' => ['arguments' => ['language' => 'python']],
        ]);

        $this->assertSame(['arguments' => ['language' => 'python']], $request->context);
    }

    public function testContextWithoutArgumentsIsEmpty(): void
    {
        $request = self::fromParams([
            'ref' => ['type' => 'ref/prompt', 'name' => 'code_review'],
            'argument' => ['name' => 'framework', 'value' => 'fla'],
            'context' => [],
        ]);

        $this->assertSame([], $request->context);
    }

    public function testContextIsSerialized(): void
    {
        $request = (new CompletionCompleteRequest(
            new PromptReference('code_review'),
            ['name' => 'framework', 'value' => 'fla'],
            ['arguments' => ['language' => 'python']],
        ))->withId(1);

        $params = json_decode(json_encode($request, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR)['params'];

        $this->assertSame(['arguments' => ['language' => 'python']], $params['context']);
    }

    public function testEmptyContextIsSerializedAsObjects(): void
    {
        $request = (new CompletionCompleteRequest(
            new PromptReference('code_review'),
            ['name' => 'framework', 'value' => 'fla'],
            ['arguments' => []],
        ))->withId(1);

        $this->assertStringContainsString('"context":{"arguments":{}}', json_encode($request, \JSON_THROW_ON_ERROR));
    }

    public function testContextIsOmittedWhenNull(): void
    {
        $request = (new CompletionCompleteRequest(
            new PromptReference('code_review'),
            ['name' => 'framework', 'value' => 'fla'],
        ))->withId(1);

        $this->assertStringNotContainsString('"context"', json_encode($request, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('provideMalformedParams')]
    public function testMalformedParamsAreRejected(array $params, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        self::fromParams($params);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideMalformedParams(): iterable
    {
        $ref = ['type' => 'ref/prompt', 'name' => 'book_seat'];
        $argument = ['name' => 'seat', 'value' => '12'];

        yield 'missing ref' => [
            ['argument' => $argument],
            'Missing or invalid "ref" parameter for completion/complete.',
        ];

        yield 'unknown ref type' => [
            ['ref' => ['type' => 'ref/nothing'], 'argument' => $argument],
            'Invalid "ref" parameter for completion/complete.',
        ];

        yield 'prompt ref without a name' => [
            ['ref' => ['type' => 'ref/prompt'], 'argument' => $argument],
            'Missing or invalid "ref.name" parameter for completion/complete.',
        ];

        yield 'resource ref without a uri' => [
            ['ref' => ['type' => 'ref/resource'], 'argument' => $argument],
            'Missing or invalid "ref.uri" parameter for completion/complete.',
        ];

        yield 'missing argument' => [
            ['ref' => $ref],
            'Missing or invalid "argument" parameter for completion/complete.',
        ];

        yield 'argument without a name' => [
            ['ref' => $ref, 'argument' => ['value' => '12']],
            'Missing or invalid "argument.name" parameter for completion/complete.',
        ];

        yield 'argument without a value' => [
            ['ref' => $ref, 'argument' => ['name' => 'seat']],
            'Missing or invalid "argument.value" parameter for completion/complete.',
        ];

        yield 'argument value that is not a string' => [
            ['ref' => $ref, 'argument' => ['name' => 'seat', 'value' => 12]],
            'Missing or invalid "argument.value" parameter for completion/complete.',
        ];

        yield 'context that is not an object' => [
            ['ref' => $ref, 'argument' => $argument, 'context' => 'row=12'],
            'Invalid "context" parameter for completion/complete.',
        ];

        yield 'context arguments that are not an object' => [
            ['ref' => $ref, 'argument' => $argument, 'context' => ['arguments' => 'row=12']],
            'Invalid "context.arguments" parameter for completion/complete.',
        ];

        yield 'context argument value that is not a string' => [
            ['ref' => $ref, 'argument' => $argument, 'context' => ['arguments' => ['row' => 12]]],
            'Invalid "context.arguments.row" parameter for completion/complete.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function fromParams(array $params): CompletionCompleteRequest
    {
        return CompletionCompleteRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'completion/complete',
            'params' => $params,
        ]);
    }
}
