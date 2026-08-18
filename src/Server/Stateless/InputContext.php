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
