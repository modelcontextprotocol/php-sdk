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
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Server\Authorization\AccessToken;

/**
 * Binds a `requestState` to what the specification recommends: the
 * authenticated principal and the originating request, as its method name
 * plus a digest of its salient parameters.
 *
 * The salient parameters are an allowlist per method, so a parameter a later
 * revision adds cannot silently change the binding: the tool or prompt name
 * and a digest of its arguments, or the resource URI. Any other method binds
 * by its name alone. The principal is the token's issuer, client and subject,
 * which outlive a token refresh between two rounds; an unauthorized request
 * binds to no principal, so its state does not verify on an authorized one,
 * nor the other way round.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr#server-requirements-basic-workflow
 *
 * @author Björn Meyer <b.meyer@shopware.com>
 */
final class OriginatingRequestBinder implements RequestStateBinderInterface
{
    public function bind(Request $request, ?AccessToken $accessToken): string
    {
        [$target, $arguments] = match (true) {
            $request instanceof CallToolRequest => [$request->name, $request->arguments],
            $request instanceof GetPromptRequest => [$request->name, $request->arguments ?? []],
            $request instanceof ReadResourceRequest => [$request->uri, []],
            default => [null, []],
        };

        return json_encode([
            'method' => $request::getMethod(),
            'target' => $target,
            'arguments' => hash('sha256', json_encode(self::canonical($arguments), \JSON_THROW_ON_ERROR)),
            'principal' => null === $accessToken ? null : [
                'iss' => \is_string($issuer = $accessToken->getClaim('iss')) ? $issuer : null,
                'client' => $accessToken->getClientId(),
                'sub' => $accessToken->getSubject(),
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Sorts object members recursively, so a client re-encoding the same
     * arguments in another member order still binds to the same digest.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
