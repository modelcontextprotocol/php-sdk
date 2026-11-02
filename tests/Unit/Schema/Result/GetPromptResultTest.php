<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Result;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Result\GetPromptResult;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class GetPromptResultTest extends TestCase
{
    /**
     * @param array{int|string, int|string} $keys
     */
    #[TestWith([['first', 'second']])]
    #[TestWith([[1, 3]])]
    public function testMessagesSerializeAsListForNonListKeys(array $keys): void
    {
        $first = new PromptMessage(Role::User, new TextContent('first'));
        $second = new PromptMessage(Role::Assistant, new TextContent('second'));

        $result = new GetPromptResult([$keys[0] => $first, $keys[1] => $second]);

        $this->assertSame([$first, $second], $result->jsonSerialize()['messages']);
    }
}
