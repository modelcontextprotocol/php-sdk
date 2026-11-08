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
     */
    private function __construct(
        public readonly ?MessageInterface $message,
        public readonly int $httpStatus,
        public readonly ?\Closure $frames = null,
        private readonly ?string $json = null,
        private readonly bool $bodyless = false,
    ) {
    }

    /**
     * A successful answer whose body the wire codec has already stamped — a
     * plain array, since no result class models the fields it added.
     *
     * @param array<string, mixed> $body
     *
     * @throws \JsonException when the body cannot be encoded, e.g. text that is not valid UTF-8
     */
    public static function ok(string|int $id, array $body): self
    {
        return new self(null, 200, json: json_encode([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $id,
            'result' => $body,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    public static function error(Error $error, int $httpStatus): self
    {
        return new self($error, $httpStatus);
    }

    /**
     * A status with no body — what a notification gets, since it has no id to
     * correlate a JSON-RPC message against.
     */
    public static function empty(int $httpStatus): self
    {
        return new self(null, $httpStatus, bodyless: true);
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

    /**
     * @param mixed $frame a frame to encode, or a string that already is its JSON
     *
     * @throws \JsonException when the frame cannot be encoded
     */
    public static function encodeFrame(mixed $frame): string
    {
        return \is_string($frame) ? $frame : json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function isStream(): bool
    {
        return null !== $this->frames;
    }

    public function isEmpty(): bool
    {
        return $this->bodyless;
    }

    public function isError(): bool
    {
        return $this->message instanceof Error;
    }

    public function toJson(): string
    {
        if (null !== $this->json) {
            return $this->json;
        }

        if ($this->bodyless) {
            throw new \LogicException('This result carries no body; send its status alone.');
        }

        if (null === $this->message) {
            throw new \LogicException('A streaming or empty result has no single JSON body; check isStream()/isEmpty() first.');
        }

        return json_encode($this->message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
