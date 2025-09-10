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
     * Create a session with a specific UUID.
     */
    public function create(Uuid $id, SessionStoreInterface $store): SessionInterface;

    /**
     * Create a new session with a generated UUID.
     */
    public function createNew(SessionStoreInterface $store): SessionInterface;
}
