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

use Mcp\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a fixed bearer token, obtained outside the SDK.
 *
 * Useful when the token comes from somewhere the SDK has no business knowing about --
 * a secrets manager, an already-authenticated session, a personal access token pasted
 * into a config file. There is nothing to negotiate, so a challenge is never actionable.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class BearerToken implements AuthenticatorInterface
{
    /**
     * @param string $token the raw access token, without the "Bearer " prefix
     */
    public function __construct(private readonly string $token)
    {
        if ('' === trim($token)) {
            throw new InvalidArgumentException('The bearer token must not be empty.');
        }
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer '.$this->token);
    }

    public function handleChallenge(RequestInterface $request, ResponseInterface $response): bool
    {
        return false;
    }
}
