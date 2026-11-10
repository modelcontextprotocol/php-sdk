<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Stateless;

use Mcp\Client\Exception\RuntimeException;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Answers a server's multi round-trip asks (SEP-2322).
 *
 * The modern era removed server-initiated requests, so a server that needs
 * something from the client says so in the result and the client re-sends the
 * original call carrying the answers. What used to be an inbound request the
 * client responded to is now an entry in `inputRequests` it resolves locally —
 * so the same handlers answer it, and a caller that registered an elicitation
 * callback keeps working across the revision boundary.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InputRequestResolver
{
    /**
     * The only requests a server may park in `inputRequests`. Anything else is
     * a server fault, and answering it would be inventing protocol.
     *
     * @var array<string, class-string<Request>>
     */
    private const RESOLVABLE = [
        'elicitation/create' => ElicitRequest::class,
        'sampling/createMessage' => CreateSamplingMessageRequest::class,
        'roots/list' => ListRootsRequest::class,
    ];

    /**
     * @param RequestHandlerInterface<mixed>[] $handlers
     */
    public function __construct(
        private readonly array $handlers,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Reads the `inputRequests` map off a result, or null when the result is
     * not an ask.
     *
     * A result with no `resultType` MUST be read as complete, so an absent
     * member is not an ask — only the explicit `input_required` is.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>|null
     */
    public static function asked(array $result): ?array
    {
        if (($result['resultType'] ?? null) !== 'input_required') {
            return null;
        }

        return \is_array($result['inputRequests'] ?? null) ? $result['inputRequests'] : [];
    }

    /**
     * Resolves every ask into the `inputResponses` map the retry carries.
     *
     * Keys are the server's, and each answer goes back under the key it was
     * asked under — the client never reorders or renames them, since that map
     * is how the server correlates answers to questions.
     *
     * @param array<string, mixed> $inputRequests
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when an ask cannot be answered at all
     */
    public function resolve(array $inputRequests): array
    {
        $responses = [];

        foreach ($inputRequests as $key => $ask) {
            $responses[(string) $key] = $this->answer((string) $key, $ask);
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $key, mixed $ask): array
    {
        if (!\is_array($ask) || !\is_string($ask['method'] ?? null)) {
            throw new RuntimeException(\sprintf('Server asked for input under "%s" without a method to answer.', $key));
        }

        $method = $ask['method'];
        $class = self::RESOLVABLE[$method] ?? null;

        if (null === $class) {
            throw new RuntimeException(\sprintf('Server asked for input under "%s" using "%s", which is not a request a client can answer.', $key, $method));
        }

        $params = $ask['params'] ?? null;
        if ($params instanceof \stdClass) {
            $params = (array) $params;
        }

        // The ask is a bare method/params pair, but the handlers speak in
        // messages. The id never reaches the wire — the answer is keyed by
        // $key — so any id will do.
        $request = $class::fromArray([
            'jsonrpc' => '2.0',
            'id' => $key,
            'method' => $method,
            'params' => \is_array($params) ? $params : null,
        ]);

        $this->logger->debug('Resolving multi round-trip input request', [
            'key' => $key,
            'method' => $method,
        ]);

        $result = $this->dispatch($request);

        if ($result instanceof Error) {
            throw new RuntimeException(\sprintf('Cannot answer the server\'s "%s" input request under "%s": %s', $method, $key, $result->message));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|Error
     */
    private function dispatch(Request $request): array|Error
    {
        foreach ($this->handlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            try {
                $response = $handler->handle($request);
            } catch (\Throwable $e) {
                $this->logger->error('Input request handler failed', [
                    'method' => $request::getMethod(),
                    'exception' => $e,
                ]);

                return Error::forInternalError($e->getMessage(), $request->getId());
            }

            if ($response instanceof Error) {
                return $response;
            }

            return self::resultOf($response);
        }

        return Error::forMethodNotFound(
            \sprintf('Client does not handle "%s" requests.', $request::getMethod()),
            $request->getId(),
        );
    }

    /**
     * @param Response<mixed> $response
     *
     * @return array<string, mixed>
     */
    private static function resultOf(Response $response): array
    {
        $result = $response->result;

        if ($result instanceof \JsonSerializable) {
            $result = $result->jsonSerialize();
        }

        if ($result instanceof \stdClass) {
            $result = (array) $result;
        }

        return \is_array($result) ? $result : [];
    }
}
