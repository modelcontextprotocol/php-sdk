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
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use PHPUnit\Framework\TestCase;

final class CreateSamplingMessageRequestTest extends TestCase
{
    public function testConstructorWithValidSetOfMessages()
    {
        $messages = [
            new SamplingMessage(Role::User, new TextContent('My name is George.')),
            new SamplingMessage(Role::Assistant, new TextContent('Hi George, nice to meet you!')),
            new SamplingMessage(Role::User, new TextContent('What is my name?')),
        ];

        $request = new CreateSamplingMessageRequest($messages, 150);

        $this->assertCount(3, $request->messages);
        $this->assertSame(150, $request->maxTokens);
    }

    public function testConstructorWithInvalidSetOfMessages()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Messages must be instance of SamplingMessage.');

        $messages = [
            new SamplingMessage(Role::User, new TextContent('My name is George.')),
            new SamplingMessage(Role::Assistant, new TextContent('Hi George, nice to meet you!')),
            new TextContent('What is my name?'),
        ];

        /* @phpstan-ignore argument.type */
        new CreateSamplingMessageRequest($messages, 150);
    }
}
