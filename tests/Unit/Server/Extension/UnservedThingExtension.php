<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Extension;

use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;

/**
 * An extension that declares a method it does not serve: {@see ThingListRequest}
 * is registered so the method decodes, but no handler answers it.
 */
final class UnservedThingExtension implements ExtensionInterface
{
    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier('com.example/unserved-things');
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [ThingListRequest::class];
    }

    public function getRequestHandlers(): iterable
    {
        return [];
    }
}
