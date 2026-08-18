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
 * An extension that adds RPC methods of its own, not only a capability entry.
 *
 * The two halves are declared separately because they answer different
 * questions: the handlers say how a claimed method is served, and
 * {@see self::getMessages()} says which methods exist at all — which is what
 * lets a server distinguish "this extension is not enabled here" from "no such
 * method", instead of answering `-32601` to both.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface MethodProvidingExtensionInterface extends ExtensionInterface
{
    /**
     * Every message class this extension defines.
     *
     * These are registered with the {@see \Mcp\JsonRpc\MessageFactory}, without
     * which an extension's method cannot be decoded off the wire at all, and
     * their method names are what let a server distinguish an extension it does
     * not serve from a method that does not exist.
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
