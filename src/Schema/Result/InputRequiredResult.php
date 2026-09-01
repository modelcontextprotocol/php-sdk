<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;

/**
 * Tells the client the server needs more input before it can finish (MRTR).
 *
 * The modern era removed server-initiated requests, so the ask travels back in
 * the result and the client re-sends the original call with the answers. The
 * `requestState` round-trips through the client and is therefore
 * attacker-controlled on return — see {@see \Mcp\Server\Stateless\RequestStateCodec}.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class InputRequiredResult implements ResultInterface
{
    public const RESULT_TYPE = 'input_required';

    /**
     * @param array<string, ElicitRequest|CreateSamplingMessageRequest|ListRootsRequest> $inputRequests server-assigned keys, unique within this request
     * @param string|null                                                                $requestState  opaque server context the client echoes back
     */
    public function __construct(
        public readonly array $inputRequests = [],
        public readonly ?string $requestState = null,
    ) {
        // Neither member would tell the client to retry with nothing new.
        if ([] === $this->inputRequests && null === $this->requestState) {
            throw new InvalidArgumentException('An InputRequiredResult must carry at least one of "inputRequests" or "requestState".');
        }
    }

    /**
     * @return array{
     *     resultType: string,
     *     inputRequests?: array<string, mixed>,
     *     requestState?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['resultType' => self::RESULT_TYPE];

        if ([] !== $this->inputRequests) {
            $requests = [];
            foreach ($this->inputRequests as $key => $request) {
                // Values are bare method/params pairs, not messages: the client
                // keys answers by the map key. getParams() is protected, so the
                // envelope is built with a throwaway id and then discarded.
                $envelope = $request->withId(0)->jsonSerialize();

                $params = $envelope['params'] ?? null;

                $requests[$key] = [
                    'method' => $request::getMethod(),
                    // An empty PHP array would encode as `[]`, not `{}`.
                    'params' => [] === $params || null === $params ? new \stdClass() : $params,
                ];
            }
            $data['inputRequests'] = $requests;
        }

        if (null !== $this->requestState) {
            $data['requestState'] = $this->requestState;
        }

        return $data;
    }
}
