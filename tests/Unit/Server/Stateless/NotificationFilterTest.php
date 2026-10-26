<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Stateless;

use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Stateless\NotificationFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class NotificationFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{NotificationFilter, bool}>
     */
    public static function filters(): iterable
    {
        yield 'nothing' => [new NotificationFilter(), true];
        yield 'tools' => [new NotificationFilter(toolsListChanged: true), false];
        yield 'prompts' => [new NotificationFilter(promptsListChanged: true), false];
        yield 'resources' => [new NotificationFilter(resourcesListChanged: true), false];
        yield 'resource subscriptions' => [new NotificationFilter(resourceSubscriptions: ['file:///watched']), false];
    }

    #[DataProvider('filters')]
    #[TestDox('a filter is empty only when it admits no notification type and no resource')]
    public function testIsEmpty(NotificationFilter $filter, bool $empty): void
    {
        $this->assertSame($empty, $filter->isEmpty());
    }

    #[TestDox('a request the server cannot honour at all narrows to an empty filter')]
    public function testIntersectionCanBeEmpty(): void
    {
        $filter = NotificationFilter::fromParams(['toolsListChanged' => true, 'resourceSubscriptions' => ['file:///watched']])
            ->intersect(new ServerCapabilities(toolsListChanged: false, resourcesSubscribe: false));

        $this->assertTrue($filter->isEmpty());
    }
}
