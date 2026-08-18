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
 * A minimal extension: an identifier, a capability payload, and one method it
 * defines and serves.
 */
final class ThingExtension implements ExtensionInterface
{
    public function __construct(
        private readonly string $id = 'com.example/things',
    ) {
    }

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier($this->id);
    }

    public function getCapabilities(): array
    {
        return ['flavour' => 'vanilla'];
    }

    public function getMessages(): array
    {
        return [ThingListRequest::class];
    }

    public function getRequestHandlers(): iterable
    {
        yield new ThingListHandler();
    }
}
