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

use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Result\ReadResourceResult;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ReadResourceResultTest extends TestCase
{
    /**
     * @param array{int|string, int|string} $keys
     */
    #[TestWith([['first', 'second']])]
    #[TestWith([[1, 3]])]
    public function testContentsSerializeAsListForNonListKeys(array $keys): void
    {
        $first = new TextResourceContents('file://test', 'text/plain', 'first');
        $second = new TextResourceContents('file://test', 'text/plain', 'second');

        $result = new ReadResourceResult([$keys[0] => $first, $keys[1] => $second]);

        $this->assertSame([$first, $second], $result->jsonSerialize()['contents']);
    }
}
