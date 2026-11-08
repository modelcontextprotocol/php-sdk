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
 * own, so the states it mints never verify. Pass a `$subject` resolver when
 * the authorization server identifies users by another claim; the issuer
 * and client stay part of the principal either way.
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
     * @param (\Closure(AccessToken): ?string)|null $subject identifies the user a token was issued for, stable
     *                                                       across a token refresh; null when the token identifies
     *                                                       nobody. Defaults to the `sub` claim.
     */
    public function __construct(
        private readonly ?\Closure $subject = null,
    ) {
    }

    public function bind(Request $request, ?AccessToken $accessToken): string
    {
        [$target, $arguments] = match (true) {
            $request instanceof CallToolRequest => [$request->name, self::canonical($request->arguments, true)],
            $request instanceof GetPromptRequest => [$request->name, self::canonical($request->arguments ?? [])],
            $request instanceof ReadResourceRequest => [$request->uri, []],
            default => [null, []],
        };

        return self::encode([
            'method' => $request::getMethod(),
            'target' => $target,
            'arguments' => hash('sha256', self::encode($arguments)),
            'principal' => null === $accessToken ? null : $this->principal($accessToken),
        ]);
    }

    /**
     * @return array{identified: array{iss: ?string, client: ?string, sub: string}}|array{unidentified: string}
     */
    private function principal(AccessToken $accessToken): array
    {
        $subject = null === $this->subject ? $accessToken->getSubject() : ($this->subject)($accessToken);

        if (null === $subject) {
            return ['unidentified' => bin2hex(random_bytes(16))];
        }

        $issuer = $accessToken->getClaim('iss');

        // Not up to the resolver: a state stays with the issuer and client it was minted for.
        return ['identified' => [
            'iss' => \is_string($issuer) ? $issuer : null,
            'client' => $accessToken->getClientId(),
            'sub' => $subject,
        ]];
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
     * starts with NUL. A `\stdClass` is a map whatever its keys, as tool
     * arguments keep their JSON types. Numbers bind by value: `1` and `1.0`
     * encode alike, so a client re-encoding a number does not break its retry.
     */
    private static function canonical(mixed $value, bool $isMap = false): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            $isMap = true;
        }

        if (!\is_array($value)) {
            return $value;
        }

        if (!$isMap && array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
        }

        ksort($value, \SORT_STRING);

        $members = [];

        foreach ($value as $key => $member) {
            $members[] = [(string) $key, self::canonical($member)];
        }

        return ['map' => $members];
    }
}
