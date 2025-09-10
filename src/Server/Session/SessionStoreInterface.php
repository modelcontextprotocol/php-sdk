<?php

namespace Mcp\Server\Session;

use Symfony\Component\Uid\Uuid;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface SessionStoreInterface
{
    /**
     * Read session data
     *
     * Returns an encoded string of the read data.
     * If nothing was read, it must return false.
     * @param Uuid $id The session id to read data for.
     */
    public function read(Uuid $id): string|false;

    /**
     * Write session data
     * @param Uuid $id The session id.
     * @param string $data The encoded session data.
     */
    public function write(Uuid $id, string $data): bool;

    /**
     * Destroy a session
     * @param Uuid $id The session ID being destroyed.
     * The return value (usually TRUE on success, FALSE on failure).
     */
    public function destroy(Uuid $id): bool;

    /**
     * Cleanup old sessions
     * Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     */
    public function gc(int $maxLifetime): array;
}
