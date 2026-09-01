<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Exception\RequestStateException;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\InputRequestCapabilities;
use Mcp\Server\Stateless\RequestStateCodec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves a modern-era handler on a handshake-era connection.
 *
 * Revision 2026-07-28 removed server-initiated requests: a handler that needs
 * something from the user returns an {@see InputRequiredResult} naming it, and
 * the client retries the whole call with the answers. The handshake era has no
 * such retry — it has a channel back to the client instead.
 *
 * This closes that gap from the server side. When a handler returns an
 * input-required result on a handshake-era connection, each embedded request
 * goes out as the real server-to-client request it describes
 * (`elicitation/create`, `sampling/createMessage`, `roots/list`), and the
 * handler is re-entered with the answers under the keys it asked for. A handler
 * written once for the modern era therefore serves both, and cannot tell which
 * fulfilled it.
 *
 * The cost is that re-entry is re-execution: the handler runs again from the
 * top each round, so it must re-derive its position from what came back rather
 * than from anything it kept. That is already true of the modern era — the
 * client retries the whole call there — so a portable handler is written that
 * way regardless; it is only new for handlers that until now could rely on
 * {@see ClientGateway::elicit()} suspending mid-body and keeping their locals.
 * Those keep working untouched: nothing here runs unless a handler *returns* an
 * ask.
 *
 * Runs inside the handler's own fiber, so the wait for each answer is the same
 * suspension {@see ClientGateway} already uses, driven by the same transport
 * loop.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InputRequiredShim
{
    /**
     * Re-entries per originating request. Deliberately below the modern
     * client driver's allowance: this loop holds a live request open.
     */
    public const DEFAULT_MAX_ROUNDS = 8;

    /** Seconds to wait for one answer. Legs are human-paced, so the protocol's 120s default is wrong here. */
    public const DEFAULT_ROUND_TIMEOUT = 600;

    public function __construct(
        private readonly int $maxRounds = self::DEFAULT_MAX_ROUNDS,
        private readonly int $roundTimeout = self::DEFAULT_ROUND_TIMEOUT,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Runs a handler to a result the client can be given.
     *
     * Returns whatever the handler returned when it asks for nothing, which is
     * every call that is not multi round-trip.
     *
     * @param Response<mixed>|Error                                         $result  what the handler returned on its first entry
     * @param RequestHandlerInterface<ResultInterface|array<string, mixed>> $handler the handler that produced it
     *
     * @return Response<mixed>|Error
     */
    public function fulfill(
        Response|Error $result,
        RequestHandlerInterface $handler,
        Request $request,
        SessionInterface $session,
        ?RequestStateCodec $codec,
    ): Response|Error {
        $round = 0;

        while (($ask = self::askOf($result)) instanceof InputRequiredResult) {
            if (++$round > $this->maxRounds) {
                $this->logger->warning('A handler kept asking for input past the round limit; the call was failed instead.', [
                    'method' => $request::getMethod(),
                    'rounds' => $this->maxRounds,
                ]);

                return Error::forInternalError(
                    \sprintf('The server asked for input more than %d times without reaching a result.', $this->maxRounds),
                    $request->getId(),
                );
            }

            if (null !== $refusal = $this->refuseUndeclared($ask, $request, $session)) {
                return $refusal;
            }

            try {
                $session->set(InputContext::class, new InputContext(
                    $this->collect($ask, $session),
                    self::payloadOf($ask, $codec),
                ));
            } catch (RequestStateException $e) {
                $this->logger->error('A handler minted a requestState this server cannot verify.', ['exception' => $e]);

                return Error::forInternalError('The server could not carry its own state across a round of input.', $request->getId());
            }

            $result = $handler->handle($request, $session);
        }

        return $result;
    }

    /**
     * Sends each embedded request and keeps the answer under the key it was
     * asked under.
     *
     * Answers are stored as the raw result arrays {@see InputContext} parses,
     * so this needs to know nothing about the kinds it is carrying — which is
     * also why an extension's future kind rides through unchanged.
     *
     * @return array<string, mixed>
     */
    private function collect(InputRequiredResult $ask, SessionInterface $session): array
    {
        $gateway = new ClientGateway($session);
        $responses = [];

        foreach ($ask->inputRequests as $key => $embedded) {
            $answer = $gateway->request($embedded, $this->roundTimeout);

            if ($answer instanceof Error) {
                // Not fatal to the call: a client that refuses one ask has
                // answered it, and the handler decides what that means.
                $this->logger->info('The client failed an input request; the handler is re-entered without it.', [
                    'key' => $key,
                    'error' => $answer->message,
                ]);

                continue;
            }

            $responses[$key] = $answer->result;
        }

        return $responses;
    }

    /**
     * Refuses an ask the client never said it could answer, the way the modern
     * era does — rather than sending a request that can only come back as an
     * error.
     */
    private function refuseUndeclared(InputRequiredResult $ask, Request $request, SessionInterface $session): ?Error
    {
        $declared = ClientCapabilities::fromArray((array) $session->get('client_capabilities', []));
        $missing = InputRequestCapabilities::missing($ask, $declared);

        if (null === $missing) {
            return null;
        }

        $this->logger->warning('A handler asked for input the client did not declare it could provide; the ask was replaced with -32021.', [
            'method' => $request::getMethod(),
            'required' => $missing->jsonSerialize(),
        ]);

        return Error::forMissingRequiredClientCapability(
            'The server needs input this client did not declare it can provide.',
            $missing,
            $request->getId(),
        );
    }

    /**
     * The ask a handler returned, if it returned one.
     *
     * @param Response<mixed>|Error $result
     */
    private static function askOf(Response|Error $result): ?InputRequiredResult
    {
        return $result instanceof Response && $result->result instanceof InputRequiredResult
            ? $result->result
            : null;
    }

    /**
     * The state the handler sealed last round, verified.
     *
     * Verified rather than trusted even though it never left this process: the
     * handler reads it back through the same accessor either era, so it has to
     * have been through the same check.
     *
     * @return array<string, mixed>
     *
     * @throws RequestStateException when a state is present but does not verify
     */
    private static function payloadOf(InputRequiredResult $ask, ?RequestStateCodec $codec): array
    {
        if (null === $ask->requestState) {
            return [];
        }

        if (null === $codec) {
            throw new RequestStateException('mac');
        }

        return $codec->verify($ask->requestState);
    }
}
