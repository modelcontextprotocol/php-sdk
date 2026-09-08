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
 * Where the OAuth credentials a client accumulates are kept between requests, and
 * between runs.
 *
 * Everything is keyed by the authorization server's issuer identifier. Credentials are
 * issued by one authorization server and mean nothing at another, so a resource whose
 * metadata starts pointing somewhere new finds an empty store and registers afresh
 * rather than leaking the previous server's client id to the new one.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface CredentialStorageInterface
{
    /**
     * @param string $issuer the authorization server's issuer identifier
     */
    public function getClientRegistration(string $issuer): ?ClientRegistration;

    public function saveClientRegistration(string $issuer, ClientRegistration $registration): void;

    /**
     * @param string $issuer   the authorization server's issuer identifier
     * @param string $resource the canonical URI of the protected resource the token is for
     */
    public function getToken(string $issuer, string $resource): ?AccessToken;

    public function saveToken(string $issuer, string $resource, AccessToken $token): void;

    /**
     * Drop everything held for one authorization server, or the whole store when null.
     */
    public function forget(?string $issuer = null): void;
}
