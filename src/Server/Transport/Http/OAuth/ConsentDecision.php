<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Psr\Http\Message\ResponseInterface;

/**
 * The outcome of a {@see ConsentInterface} decision: approve (optionally with a
 * narrowed scope set), deny, or short-circuit with a response (e.g. to render a
 * consent screen the user has not yet submitted).
 */
final class ConsentDecision
{
    /**
     * @param list<string>|null $approvedScopes
     */
    private function __construct(
        public readonly bool $approved,
        public readonly ?array $approvedScopes,
        public readonly ?ResponseInterface $response,
    ) {
    }

    /**
     * @param list<string>|null $approvedScopes Null keeps the requested scopes
     */
    public static function approve(?array $approvedScopes = null): self
    {
        return new self(true, $approvedScopes, null);
    }

    public static function deny(): self
    {
        return new self(false, null, null);
    }

    /**
     * Short-circuit the authorization request with a custom response (e.g. the
     * consent form to display before a decision can be made).
     */
    public static function respondWith(ResponseInterface $response): self
    {
        return new self(false, null, $response);
    }
}
