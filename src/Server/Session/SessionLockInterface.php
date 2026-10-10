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

use Mcp\Exception\SessionLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Serializes the requests of one session across the workers that serve it.
 *
 * A request loads its session, changes it and writes it back whole, so two requests
 * of one session running at the same time can overwrite each other's changes. The
 * {@see \Mcp\Server\Protocol} holds the lock from before it reads the session until
 * it saved it, and releases it while a handler waits for the client.
 *
 * @author Vitalii Cherepanov <vbcherepanov@gmail.com>
 */
interface SessionLockInterface
{
    /**
     * Blocks until the lock of the session is held, or gives up after the implementation's timeout.
     *
     * @throws SessionLockException when the lock could not be acquired in time, or the lock is already held by this instance
     */
    public function acquire(Uuid $sessionId): void;

    /**
     * @throws SessionLockException when the lock is not held by this instance, or releasing it failed
     */
    public function release(Uuid $sessionId): void;
}
