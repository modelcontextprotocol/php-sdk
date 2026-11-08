<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class IntArgumentTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{ProtocolVersion, string, array<string, mixed>}>
     */
    public static function provideOutOfRangeToolCalls(): iterable
    {
        foreach (self::provideEras() as $name => [$era]) {
            yield $name.', above the range, minimum' => [$era, 'at_least_one', ['qty' => 9300000000000000000]];
            yield $name.', wraps to zero, minimum' => [$era, 'at_least_one', ['qty' => 18446744073709551616]];
            yield $name.', below the range, maximum' => [$era, 'at_most_hundred', ['qty' => -9300000000000000000]];
            yield $name.', nullable' => [$era, 'nullable', ['qty' => 9300000000000000000]];
            yield $name.', variadic' => [$era, 'variadic', ['qty' => [9300000000000000000]]];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('provideOutOfRangeToolCalls')]
    public function testToolCallOutOfRangeIsAnsweredWithInvalidParams(ProtocolVersion $era, string $tool, array $arguments): void
    {
        $client = $this->connectOn($era);

        $this->assertInvalidParams(static fn () => $client->callTool($tool, $arguments));
    }

    #[DataProvider('provideEras')]
    public function testToolCallThatIsNotAnIntegerIsAnsweredWithInvalidParams(ProtocolVersion $era): void
    {
        $client = $this->connectOn($era);

        $this->assertInvalidParams(static fn () => $client->callTool('permissive', ['qty' => 'abc']));
        $this->assertInvalidParams(static fn () => $client->callTool('permissive', ['qty' => 1.5]));
        $this->assertInvalidParams(static fn () => $client->callTool('permissive', ['qty' => '99999999999999999999']));
    }

    #[DataProvider('provideEras')]
    public function testToolCallInRangeReachesTheTool(ProtocolVersion $era): void
    {
        $client = $this->connectOn($era);

        $this->assertEquals(new TextContent('got 5'), $client->callTool('at_least_one', ['qty' => 5])->content[0]);
        $this->assertEquals(new TextContent('got 5'), $client->callTool('at_least_one', ['qty' => 5.0])->content[0]);
    }

    /**
     * @return iterable<string, array{ProtocolVersion, string}>
     */
    public static function provideInvalidStrings(): iterable
    {
        foreach (self::provideEras() as $name => [$era]) {
            yield $name.', overflow' => [$era, '99999999999999999999'];
            yield $name.', negative overflow' => [$era, '-99999999999999999999'];
            yield $name.', double minus' => [$era, '--5'];
            yield $name.', not a number' => [$era, 'abc'];
        }
    }

    #[DataProvider('provideInvalidStrings')]
    public function testPromptArgumentIsAnsweredWithInvalidParams(ProtocolVersion $era, string $qty): void
    {
        $client = $this->connectOn($era);

        $this->assertInvalidParams(static fn () => $client->getPrompt('order', ['qty' => $qty]));
    }

    #[DataProvider('provideInvalidStrings')]
    public function testResourceVariableIsAnsweredWithInvalidParams(ProtocolVersion $era, string $id): void
    {
        $client = $this->connectOn($era);

        $this->assertInvalidParams(static fn () => $client->readResource('item://'.$id));
    }

    #[DataProvider('provideEras')]
    public function testPromptAndResourceInRangeReachTheHandler(ProtocolVersion $era): void
    {
        $client = $this->connectOn($era);

        $this->assertEquals(new TextContent('got 5'), $client->getPrompt('order', ['qty' => '5'])->messages[0]->content);

        $contents = $client->readResource('item://007')->contents[0];
        $this->assertInstanceOf(TextResourceContents::class, $contents);
        $this->assertSame('got 7', $contents->text);
    }

    private function connectOn(ProtocolVersion $era): Client
    {
        return $this->connect('int_arguments', $this->clientBuilder()->setProtocolVersion($era));
    }

    private function assertInvalidParams(callable $request): void
    {
        try {
            $request();
            $this->fail('The server must refuse the value instead of calling the handler.');
        } catch (RequestException $e) {
            $this->assertSame(Error::INVALID_PARAMS, $e->getCode(), $e->getMessage());
        }
    }
}
