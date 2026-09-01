<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * An MCP protocol extension advertised during capability negotiation.
 *
 * Implementations are typically zero-config — they expose a stable identifier and the
 * capability payload announced under `capabilities.extensions[<id>]`. The same
 * extension object can be enabled on a server (initialize response) and on a client
 * (initialize request); the side that enables it decides which.
 *
 * An extension that only announces a capability, without adding RPC methods of its
 * own, can extend {@see AbstractExtension} and skip {@see self::getMessages()} and
 * {@see self::getRequestHandlers()} entirely.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ExtensionInterface
{
    /**
     * The reverse-DNS identifier used as the key under `capabilities.extensions`.
     */
    public function getId(): ExtensionIdentifier;

    /**
     * The capability payload announced for this extension.
     *
     * The returned array is cast to an object and embedded under
     * `capabilities.extensions[<id>]`, so every value must be JSON-serializable
     * (scalars, arrays, or `JsonSerializable` objects).
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * Every message class this extension defines.
     *
     * These are registered with the {@see \Mcp\Schema\MessageFactory}, without
     * which an extension's method cannot be decoded off the wire at all, and
     * their method names are what let a server distinguish an extension it does
     * not serve from a method that does not exist. An extension with no methods
     * of its own returns an empty array.
     *
     * @return list<class-string<Request>|class-string<Notification>>
     */
    public function getMessages(): array;

    /**
     * The handlers serving those methods.
     *
     * @return iterable<RequestHandlerInterface<ResultInterface>>
     */
    public function getRequestHandlers(): iterable;
}
