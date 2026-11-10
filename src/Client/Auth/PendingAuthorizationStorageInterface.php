<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

/**
 * Storage must be scoped to the user session that initiated authorization.
 *
 * @author bbdu3 <ergouyang854@gamil.com>
 */
interface PendingAuthorizationStorageInterface extends CredentialStorageInterface
{
    public function savePendingAuthorization(PendingAuthorization $authorization): void;

    /**
     * Atomically retrieve and remove a pending authorization, including expired entries.
     */
    public function consumePendingAuthorization(string $state): ?PendingAuthorization;
}
