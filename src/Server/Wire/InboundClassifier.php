<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Wire;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\RequestMeta;
use Mcp\Schema\Wire\McpHeader;

/**
 * Decides which protocol era one inbound HTTP request belongs to.
 *
 * Evaluated exactly once, at the entry boundary, so a single endpoint can serve
 * both eras. The decision is body-primary:
 *
 *  - A request whose `params._meta` carries the reserved protocol-version key
 *    claims the per-request envelope mechanism and belongs to the era that
 *    revision starts. A malformed claim is a validation error, never a silent
 *    fall back to the handshake era.
 *  - A request without that claim is handshake-era traffic — `initialize`
 *    included, since the modern era has no handshake to route.
 *  - The `MCP-Protocol-Version` header is a cross-check only. It never upgrades
 *    or downgrades a claim, and disagreeing with one is an error in its own
 *    right; the check has to live here because a body claiming a handshake
 *    revision routes to a leg that has no cross-check of its own.
 *  - Notifications carry no claim of their own, so for those the header decides.
 *  - `GET` and `DELETE` are handshake-era session operations: the modern era is
 *    POST-only.
 *  - A batch is classified element-wise. The modern era removed batching, so an
 *    array holding a modern claim is refused rather than split.
 *
 * The classifier returns plain values and never throws: an era to route to, or
 * a rejection carrying the error to answer with.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InboundClassifier
{
    public const PROTOCOL_VERSION_HEADER = 'MCP-Protocol-Version';

    /**
     * @param string                $httpMethod the request's HTTP method
     * @param string|null           $body       the request body, already read
     * @param array<string, string> $headers    request headers, case-insensitively matched
     */
    public function classify(string $httpMethod, ?string $body, array $headers = []): EraClassification
    {
        if ('POST' !== strtoupper($httpMethod)) {
            return EraClassification::legacy();
        }

        if (null === $body || '' === trim($body)) {
            return EraClassification::legacy();
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Unreadable to both eras. Routed to the handshake leg so the one
            // parse error the client sees is the one that leg already writes.
            return EraClassification::legacy();
        }

        if (!\is_array($decoded)) {
            return EraClassification::legacy();
        }

        $headerVersion = McpHeader::lookup($headers, self::PROTOCOL_VERSION_HEADER);

        if (array_is_list($decoded)) {
            return $this->classifyBatch($decoded, $headerVersion);
        }

        /* @var array<string, mixed> $decoded */
        return $this->classifyMessage($decoded, $headerVersion);
    }

    /**
     * The header-against-body check both eras' entries share.
     *
     * Kept here rather than in the dispatcher so the edge and the leg it routes
     * to cannot disagree about what a request claims.
     *
     * @return string|null the disagreement, or null when the two agree
     */
    public static function crossCheckVersion(?string $headerVersion, string $claimedVersion): ?string
    {
        if (null === $headerVersion || $headerVersion === $claimedVersion) {
            return null;
        }

        return \sprintf('MCP-Protocol-Version header "%s" contradicts the "%s" declared in _meta.', $headerVersion, $claimedVersion);
    }

    /**
     * @param list<mixed> $messages
     */
    private function classifyBatch(array $messages, ?string $headerVersion): EraClassification
    {
        foreach ($messages as $message) {
            if (!\is_array($message) || array_is_list($message)) {
                continue;
            }

            /** @var array<string, mixed> $message */
            $classification = $this->classifyMessage($message, $headerVersion);

            if ($classification->isRejected()) {
                return $classification;
            }

            if ($classification->modern) {
                return EraClassification::reject(
                    Error::forInvalidRequest(\sprintf('Protocol revision %s removed JSON-RPC batching; send one message per request.', $classification->claimedVersion)),
                    400,
                );
            }
        }

        return EraClassification::legacy();
    }

    /**
     * @param array<string, mixed> $message
     */
    private function classifyMessage(array $message, ?string $headerVersion): EraClassification
    {
        $id = $message['id'] ?? null;
        $isNotification = !\array_key_exists('id', $message) || null === $id;

        if (!\is_string($id) && !\is_int($id)) {
            $id = null;
        }

        $params = \is_array($message['params'] ?? null) ? $message['params'] : null;
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : null;
        $claim = $meta[RequestMeta::PROTOCOL_VERSION] ?? null;

        if (null !== $claim) {
            if (!\is_string($claim) || '' === $claim) {
                return EraClassification::reject(
                    Error::forInvalidParams(\sprintf('Request "_meta" member "%s" must be a non-empty string.', RequestMeta::PROTOCOL_VERSION), $id),
                    400,
                );
            }

            if (null !== $mismatch = self::crossCheckVersion($headerVersion, $claim)) {
                return EraClassification::reject(Error::forHeaderMismatch($mismatch, $id), 400);
            }

            return self::eraOf($claim);
        }

        if (!self::namesModern($headerVersion)) {
            return EraClassification::legacy();
        }

        // The header names a revision that has no handshake, so the body has to
        // carry the envelope. A notification is the exception: it has no claim
        // to carry under this revision, so there the header is all there is.
        if ($isNotification) {
            return EraClassification::modern($headerVersion);
        }

        return EraClassification::reject(
            Error::forInvalidParams(\sprintf('Protocol revision %s requires the "%s" member in "params._meta".', $headerVersion, RequestMeta::PROTOCOL_VERSION), $id),
            400,
        );
    }

    /**
     * The era a claimed revision belongs to.
     *
     * An unknown revision counts as modern: it cannot be negotiated through a
     * handshake, and the modern leg is the one that can name what it does serve.
     */
    private static function eraOf(string $version): EraClassification
    {
        $known = ProtocolVersion::tryFrom($version);

        if (null !== $known && !$known->isModern()) {
            return EraClassification::legacy($version);
        }

        return EraClassification::modern($version);
    }

    /**
     * Only a *known* modern revision counts here. An unrecognised header with
     * nothing in the body to back it up is not evidence of an era — it is a
     * version this endpoint does not serve, and the handshake leg's version
     * middleware is what says so, naming everything the endpoint does serve.
     */
    private static function namesModern(?string $version): bool
    {
        return null !== $version && true === ProtocolVersion::tryFrom($version)?->isModern();
    }
}
