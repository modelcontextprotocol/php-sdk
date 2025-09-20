<?php

namespace Mcp\Server\Session;

use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Factory interface for creating session instances.
 * This allows for different session implementations and custom initialization logic.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface SessionFactoryInterface
{
    /**
     * Creates a new session with an auto-generated UUID.
     * This is the standard factory method for creating sessions.
     */
    public function create(SessionStoreInterface $store): SessionInterface;

    /**
     * Creates a session with a specific UUID.
     * Use this when you need to reconstruct a session with a known ID.
     */
    public function createWithId(Uuid $id, SessionStoreInterface $store): SessionInterface;
}
