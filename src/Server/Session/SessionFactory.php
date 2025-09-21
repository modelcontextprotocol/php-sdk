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
