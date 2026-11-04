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

use Mcp\Exception\RuntimeException;
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
 * A token without a subject identifies no user: tokens of different users of
 * one client would share a principal. Such a request binds to a value of its
 * own, so the states it mints never verify. Pass a `$principal` resolver when
 * the authorization server identifies users by another claim.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr#server-requirements-basic-workflow
 *
 * @author Björn Meyer <b.meyer@shopware.com>
 */
final class OriginatingRequestBinder implements RequestStateBinderInterface
{
    // A canonical map nests three levels deep, and requests decode up to json_decode()'s default of 512.
    private const ENCODE_DEPTH = 3 * 512;

    /**
     * @param (\Closure(AccessToken): ?string)|null $principal identifies the user and client a token was issued
     *                                                         for, stable across a token refresh; null when the token
     *                                                         identifies nobody. Defaults to issuer, client and subject.
     */
    public function __construct(
        private readonly ?\Closure $principal = null,
    ) {
    }

    public function bind(Request $request, ?AccessToken $accessToken): string
    {
        [$target, $arguments] = match (true) {
            $request instanceof CallToolRequest => [$request->name, $request->arguments],
            $request instanceof GetPromptRequest => [$request->name, $request->arguments ?? []],
            $request instanceof ReadResourceRequest => [$request->uri, []],
            default => [null, []],
        };

        return self::encode([
            'method' => $request::getMethod(),
            'target' => $target,
            'arguments' => hash('sha256', self::encode(self::canonical($arguments))),
            'principal' => null === $accessToken ? null : $this->principal($accessToken),
        ]);
    }

    /**
     * @return array{identified: string}|array{unidentified: string}
     */
    private function principal(AccessToken $accessToken): array
    {
        $principal = null === $this->principal ? self::defaultPrincipal($accessToken) : ($this->principal)($accessToken);

        if (null === $principal) {
            return ['unidentified' => bin2hex(random_bytes(16))];
        }

        return ['identified' => $principal];
    }

    private static function defaultPrincipal(AccessToken $accessToken): ?string
    {
        $subject = $accessToken->getSubject();

        if (null === $subject) {
            return null;
        }

        $issuer = $accessToken->getClaim('iss');

        return self::encode([
            'iss' => \is_string($issuer) ? $issuer : null,
            'client' => $accessToken->getClientId(),
            'sub' => $subject,
        ]);
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode($value, \JSON_THROW_ON_ERROR, self::ENCODE_DEPTH);
        } catch (\JsonException $e) {
            throw new RuntimeException(\sprintf('Failed to encode the requestState binding as JSON: %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * Sorts object members recursively, so a client re-encoding the same
     * arguments in another member order still binds to the same digest.
     *
     * A list stays a JSON array and any other array becomes sorted pairs
     * under `{"map": [[key, value], ...]}`, the same split schema validation
     * and the handler see: a map with numeric keys out of order, e.g.
     * `{"1": "B", "0": "A"}`, must not sort into the list `["A", "B"]`. Not
     * cast to an object, as `json_encode()` drops object members whose name
     * starts with NUL. The arguments are decoded into PHP arrays,
     * so a numeric-key object whose members arrive in list order is a list
     * to the whole SDK and binds as one. Numbers bind by value: `1` and `1.0`
     * encode alike, so a client re-encoding a number does not break its retry.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }

        ksort($value, \SORT_STRING);

        $members = [];

        foreach ($value as $key => $member) {
            $members[] = [(string) $key, self::canonical($member)];
        }

        return ['map' => $members];
    }
}
