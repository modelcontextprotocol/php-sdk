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
    public function create(Uuid $id, SessionStoreInterface $store): SessionInterface
    {
        return new Session($store, $id);
    }

    public function createNew(SessionStoreInterface $store): SessionInterface
    {
        return $this->create(Uuid::v4(), $store);
    }
}
