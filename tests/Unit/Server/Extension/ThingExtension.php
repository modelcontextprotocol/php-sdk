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

use Mcp\Schema\Extension\DependentExtensionInterface;
use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Extension\ServerExtensionInterface;

/**
 * A minimal extension: an identifier, a capability payload, and the methods it
 * defines and serves.
 */
final class ThingExtension implements ServerExtensionInterface, DependentExtensionInterface
{
    /**
     * @param list<class-string<Request>|class-string<Notification>> $messages
     * @param list<string>                                           $requires
     */
    public function __construct(
        private readonly string $id = 'com.example/things',
        private readonly array $messages = [ThingListRequest::class, ThingSeenNotification::class],
        private readonly array $requires = [],
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
        return $this->messages;
    }

    public function getRequestHandlers(): iterable
    {
        yield new ThingListHandler();
    }

    public function getNotificationHandlers(): iterable
    {
        yield new ThingSeenHandler();
    }

    public function getRequiredExtensions(): array
    {
        $required = [];
        foreach ($this->requires as $id) {
            $required[] = new ExtensionIdentifier($id);
        }

        return $required;
    }
}
