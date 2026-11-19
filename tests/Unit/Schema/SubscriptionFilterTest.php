<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\MessageFactory;
use Mcp\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Mcp\Schema\Request\SubscriptionsListenRequest;
use Mcp\Schema\SubscriptionFilter;
use PHPUnit\Framework\TestCase;

final class SubscriptionFilterTest extends TestCase
{
    public function testSerializesOnlyWhatIsOptedIn(): void
    {
        $filter = new SubscriptionFilter(toolsListChanged: true, resourceSubscriptions: ['file:///a.txt']);

        $this->assertSame('{"toolsListChanged":true,"resourceSubscriptions":["file:///a.txt"]}', json_encode($filter, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $this->assertEquals($filter, SubscriptionFilter::fromArray(['toolsListChanged' => true, 'promptsListChanged' => false, 'resourceSubscriptions' => ['file:///a.txt']]));
    }

    public function testAnEmptyFilterSerializesAsAnObject(): void
    {
        $this->assertTrue((new SubscriptionFilter())->isEmpty());
        $this->assertSame('{}', json_encode(new SubscriptionFilter(), \JSON_THROW_ON_ERROR));
    }

    public function testRejectsAFlagThatIsNoBoolean(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscriptionFilter::fromArray(['toolsListChanged' => 'yes']);
    }

    public function testRejectsResourceSubscriptionsThatAreNoListOfUris(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscriptionFilter::fromArray(['resourceSubscriptions' => ['a' => 'file:///a.txt']]);
    }

    public function testRejectsAnEmptyUri(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionFilter(resourceSubscriptions: ['']);
    }

    public function testListenRequestCarriesTheFilter(): void
    {
        $request = (new SubscriptionsListenRequest(new SubscriptionFilter(resourcesListChanged: true)))->withId(4);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'subscriptions/listen', 'params' => ['notifications' => ['resourcesListChanged' => true]]],
            $request->jsonSerialize(),
        );
    }

    public function testAcknowledgmentIsParsedWithItsSubscriptionId(): void
    {
        $notification = MessageFactory::make()->create(json_encode([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'method' => 'notifications/subscriptions/acknowledged',
            'params' => [
                '_meta' => ['io.modelcontextprotocol/subscriptionId' => 4],
                'notifications' => ['resourceSubscriptions' => ['file:///a.txt']],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(SubscriptionsAcknowledgedNotification::class, $notification);
        $this->assertSame(['file:///a.txt'], $notification->notifications->resourceSubscriptions);
        $this->assertSame(['io.modelcontextprotocol/subscriptionId' => 4], $notification->getMeta());
    }
}
