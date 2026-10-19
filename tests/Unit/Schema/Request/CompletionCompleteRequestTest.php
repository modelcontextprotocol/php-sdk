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

use Mcp\Exception\InvalidArgumentException;
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
