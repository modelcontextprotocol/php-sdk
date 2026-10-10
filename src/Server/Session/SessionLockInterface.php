<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Mcp\Exception\ExceptionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Serializes the requests of one session across workers.
 *
 * The protocol holds the lock from loading a session to saving it, so concurrent
 * requests of one session no longer lose each other's changes to the same key.
 * It releases the lock while a request is suspended, waiting on the client.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface SessionLockInterface
{
    /**
     * Blocks until the session is locked for the caller.
     *
     * @throws ExceptionInterface when the lock cannot be acquired, e.g. in time
     */
    public function acquire(Uuid $id): void;

    /**
     * Releases the lock acquired for the session, if any.
     */
    public function release(Uuid $id): void;
}
