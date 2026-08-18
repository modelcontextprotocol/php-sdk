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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;

/**
 * Tells the client the server needs more input before it can finish (MRTR).
 *
 * This is how a modern-era server asks the client for anything — elicitation,
 * sampling, roots. The handshake era let a server open its own request to the
 * client mid-call; 2026-07-28 removed that outright, so the ask travels back as
 * part of the *result* and the client re-sends the original call with the
 * answers attached. The exchange is two independent requests, which is what
 * lets any server instance serve the retry.
 *
 * `requestState` is the server's own context, opaque to the client and echoed
 * back verbatim. Because it round-trips through the client it is
 * attacker-controlled on return: anything that influences authorization or
 * business logic MUST be integrity-protected and verified, and rejected when
 * verification fails.
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
        // "Servers MUST include at least one of inputRequests or requestState
        // in every InputRequiredResult response." A result carrying neither
        // tells the client to retry with nothing new, which loops forever.
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
                // The map values are bare method/params pairs rather than whole
                // JSON-RPC requests: they are not messages in their own right,
                // and giving them ids would imply a correlation the retry does
                // not use — the client keys its answers by the map key instead.
                //
                // Params are reachable only through the JSON-RPC envelope
                // (getParams() is protected), and rendering that envelope needs
                // an id these requests never have. Hence the throwaway id: it
                // exists for the length of this expression and is discarded
                // with the envelope it was needed to build.
                $envelope = $request->withId(0)->jsonSerialize();

                $requests[$key] = [
                    'method' => $request::getMethod(),
                    'params' => $envelope['params'] ?? new \stdClass(),
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
