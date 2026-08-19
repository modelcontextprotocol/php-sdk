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

use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\ListRootsResult;

/**
 * What a retry brought back: the client's answers to a previous
 * {@see \Mcp\Schema\Result\InputRequiredResult}, keyed as its `inputRequests`
 * were, plus the verified state that result carried.
 *
 * Absent on a first call, which is how a handler tells the rounds apart.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InputContext
{
    /**
     * @param array<string, mixed> $responses    client answers, keyed as the inputRequests were
     * @param array<string, mixed> $requestState the verified payload the server sealed last round
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $requestState = [],
    ) {
    }

    /**
     * The client's answer for `$key`, or null when it did not provide one.
     */
    public function response(string $key): mixed
    {
        return $this->responses[$key] ?? null;
    }

    /**
     * The client's answer to an `elicitation/create` ask, typed.
     *
     * Null when the key is absent or the answer does not parse — which the
     * caller should read as "not answered" and ask again, rather than as an
     * error: the specification says a server SHOULD re-ask for information it
     * still needs instead of failing the request.
     *
     * @param ElicitationMode $mode the mode of the ask this answers; url-mode
     *                              answers carry no content, so an accepted one
     *                              is complete without it
     */
    public function elicitResult(string $key, ElicitationMode $mode = ElicitationMode::Form): ?ElicitResult
    {
        return $this->parse($key, static fn (array $data): ElicitResult => ElicitResult::fromArray($data, $mode));
    }

    /**
     * The client's answer to a `sampling/createMessage` ask, typed.
     */
    public function samplingResult(string $key): ?CreateSamplingMessageResult
    {
        return $this->parse($key, CreateSamplingMessageResult::fromArray(...));
    }

    /**
     * The client's answer to a `roots/list` ask, typed.
     */
    public function rootsResult(string $key): ?ListRootsResult
    {
        return $this->parse($key, ListRootsResult::fromArray(...));
    }

    /**
     * @template T of ResultInterface
     *
     * @param callable(array<string, mixed>): T $factory
     *
     * @return T|null
     */
    private function parse(string $key, callable $factory): ?ResultInterface
    {
        $response = $this->responses[$key] ?? null;

        if (!\is_array($response)) {
            return null;
        }

        try {
            return $factory($response);
        } catch (\Throwable) {
            // A malformed answer is one the client did not really give.
            return null;
        }
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->responses);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->responses;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestState(): array
    {
        return $this->requestState;
    }
}
