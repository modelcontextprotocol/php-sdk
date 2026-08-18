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

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;

/**
 * One modern-era answer, paired with the HTTP status that carries it.
 *
 * SEP-2575 fixes specific statuses to specific JSON-RPC error codes, so the
 * status is part of the answer rather than something the transport re-derives.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessResult
{
    /**
     * @param (\Closure(): \Generator<mixed>)|null $frames set instead of $message when the answer is a stream
     * @param array<string, mixed>|null            $body   a result body already through the wire codec
     */
    private function __construct(
        public readonly ?MessageInterface $message,
        public readonly int $httpStatus,
        public readonly ?\Closure $frames = null,
        private readonly ?array $body = null,
        private readonly string|int|null $id = null,
    ) {
    }

    /**
     * A successful answer whose body the wire codec has already stamped — a
     * plain array, since no result class models the fields it added.
     *
     * @param array<string, mixed> $body
     */
    public static function ok(string|int $id, array $body): self
    {
        return new self(null, 200, null, $body, $id);
    }

    public static function error(Error $error, int $httpStatus): self
    {
        return new self($error, $httpStatus);
    }

    /**
     * A notification never gets an answer, successful or not — per JSON-RPC, a
     * message with no id must never receive a response. This is the "I read
     * it, there is nothing to say back" answer: HTTP 202 with no body.
     */
    public static function accepted(): self
    {
        return new self(null, 202);
    }

    /**
     * A long-lived answer delivered as frames — today only
     * `subscriptions/listen`. Deferred, since the frames are produced over the
     * life of the connection.
     *
     * @param \Closure(): \Generator<mixed> $frames
     */
    public static function stream(\Closure $frames): self
    {
        return new self(null, 200, $frames);
    }

    public function isStream(): bool
    {
        return null !== $this->frames;
    }

    /**
     * An accepted notification: no message, no body, no frames — just the
     * status code.
     */
    public function isEmpty(): bool
    {
        return null === $this->message && null === $this->body && null === $this->frames;
    }

    public function isError(): bool
    {
        return $this->message instanceof Error;
    }

    public function toJson(): string
    {
        if (null !== $this->body) {
            return json_encode([
                'jsonrpc' => MessageInterface::JSONRPC_VERSION,
                'id' => $this->id,
                'result' => $this->body,
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        if (null === $this->message) {
            throw new \LogicException('A streaming or empty result has no single JSON body; check isStream()/isEmpty() first.');
        }

        return json_encode($this->message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
