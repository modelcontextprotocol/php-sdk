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

use Mcp\Schema\Request\GetPromptRequest;
use PHPUnit\Framework\TestCase;

final class GetPromptRequestTest extends TestCase
{
    public function testJsonSerializationWithArguments(): void
    {
        $request = (new GetPromptRequest('greet', ['name' => 'Ada']))->withId(1);

        $this->assertSame(
            '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"greet","arguments":{"name":"Ada"}}}',
            json_encode($request, \JSON_UNESCAPED_SLASHES),
        );
    }

    public function testJsonSerializationOmitsEmptyArguments(): void
    {
        // `[]` would encode as a JSON array, which servers validating against
        // the spec's `arguments` object reject.
        $request = (new GetPromptRequest('greet', []))->withId(1);

        $this->assertSame(
            '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"greet"}}',
            json_encode($request, \JSON_UNESCAPED_SLASHES),
        );
    }
}
