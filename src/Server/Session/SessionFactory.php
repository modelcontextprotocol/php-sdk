<?php

namespace Mcp\Server\Session;

use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default implementation of SessionFactoryInterface.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SessionFactory implements SessionFactoryInterface
{
    public function create(SessionStoreInterface $store): SessionInterface
    {
        return new Session($store, Uuid::v4());
    }

    public function createWithId(Uuid $id, SessionStoreInterface $store): SessionInterface
    {
        return new Session($store, $id);
    }
}
