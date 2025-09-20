<?php

namespace Mcp\Server\Session;

use Symfony\Component\Uid\Uuid;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface SessionStoreInterface
{
    /**
     * Check if a session exists
     * @param Uuid $id The session id.
     * @return bool True if the session exists, false otherwise.
     */
    public function exists(Uuid $id): bool;

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
     * the configured TTL will be removed.
     */
    public function gc(): array;
}
