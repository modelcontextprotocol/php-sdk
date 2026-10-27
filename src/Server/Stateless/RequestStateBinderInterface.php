<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Authorization\AccessToken;

/**
 * Binds the `requestState` of a multi round-trip request to the request it
 * was minted for.
 *
 * The SDK calls {@see self::bind()} once per request: the value it returns is
 * covered by the MAC of every state minted while answering the request, and
 * recomputed to verify the state the client echoes on the retry. A state
 * presented on a request that binds to another value fails verification like
 * a forged one. The value never goes on the wire.
 *
 * Bind only what stays the same across the rounds of one call: the request
 * object carries neither `requestState` nor `inputResponses`, but `_meta`
 * (progress token, trace context, client capabilities) may change between
 * rounds and does not belong in the binding.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr#server-requirements-basic-workflow
 * @see OriginatingRequestBinder for the binding the specification recommends
 *
 * @author Björn Meyer <b.meyer@shopware.com>
 */
interface RequestStateBinderInterface
{
    /**
     * @param Request          $request     the request as the client sent it, before any `RequestEvent` listener ran
     * @param AccessToken|null $accessToken the token the request was authorized with, null on an unauthorized transport
     */
    public function bind(Request $request, ?AccessToken $accessToken): string;
}
