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

use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;

/**
 * Checks that a request's HTTP headers agree with its JSON-RPC body (SEP-2243).
 *
 * The headers exist so an intermediary — a gateway, a proxy, a rate limiter —
 * can route and police MCP traffic without parsing the body. That only works
 * if the two cannot disagree, so a server that *does* read the body is
 * required to confirm they match and to reject the request when they do not.
 * Otherwise a caller could show a gateway one request and the server another.
 *
 * Failures are all one kind: HTTP 400 with JSON-RPC `-32020`.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StandardHeaderValidator
{
    public const METHOD_HEADER = 'Mcp-Method';
    public const NAME_HEADER = 'Mcp-Name';
    public const PARAM_HEADER_PREFIX = 'Mcp-Param-';

    /** Wrapper marking a header value as Base64 of its UTF-8 representation. */
    private const BASE64_PREFIX = '=?base64?';
    private const BASE64_SUFFIX = '?=';

    public function __construct(
        private readonly ?RegistryInterface $registry = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $params
     * @param array<string, string>     $headers
     *
     * @return string|null the reason to reject, or null when the request is consistent
     */
    public function validate(string $method, ?array $params, array $headers): ?string
    {
        return $this->checkMethod($method, $headers)
            ?? $this->checkName($method, $params, $headers)
            ?? $this->checkParams($method, $params, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkMethod(string $method, array $headers): ?string
    {
        $declared = $this->header($headers, self::METHOD_HEADER);

        if (null === $declared) {
            return \sprintf('Missing required %s header (body method is "%s").', self::METHOD_HEADER, $method);
        }

        if ($declared !== $method) {
            return \sprintf('%s header "%s" does not match the body method "%s".', self::METHOD_HEADER, $declared, $method);
        }

        return null;
    }

    /**
     * `Mcp-Name` names whatever the request acts on, which is a different
     * member per method. When the body carries one, the header must repeat it;
     * when it does not, the header has nothing to agree with and the server
     * must not demand one.
     *
     * @param array<string, mixed>|null $params
     * @param array<string, string>     $headers
     */
    private function checkName(string $method, ?array $params, array $headers): ?string
    {
        $expected = self::nameFor($method, $params);
        $declared = $this->header($headers, self::NAME_HEADER);

        if (null === $expected) {
            return null;
        }

        if (null === $declared) {
            return \sprintf('Missing required %s header (body carries "%s").', self::NAME_HEADER, $expected);
        }

        if ($declared !== $expected) {
            return \sprintf('%s header "%s" does not match the body value "%s".', self::NAME_HEADER, $declared, $expected);
        }

        return null;
    }

    /**
     * The subject of a request, per method. Anything not listed carries no
     * name, and so is exempt from the header entirely.
     *
     * @param array<string, mixed>|null $params
     */
    public static function nameFor(string $method, ?array $params): ?string
    {
        $value = match ($method) {
            'tools/call', 'prompts/get' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            'tasks/get', 'tasks/update', 'tasks/cancel' => $params['taskId'] ?? null,
            default => null,
        };

        return \is_string($value) ? $value : null;
    }

    /**
     * Tool arguments a tool marked with `x-mcp-header` are mirrored into
     * `Mcp-Param-*` headers, and must survive the round trip unchanged.
     *
     * Only headers the *tool* declares are checked. An unrecognized
     * `Mcp-Param-*` belongs to somebody else in the chain — the spec has
     * intermediaries forward what they do not understand — so rejecting it
     * would break traffic this server has no stake in.
     *
     * @param array<string, mixed>|null $params
     * @param array<string, string>     $headers
     */
    private function checkParams(string $method, ?array $params, array $headers): ?string
    {
        if ('tools/call' !== $method || null === $this->registry) {
            return null;
        }

        $toolName = $params['name'] ?? null;
        if (!\is_string($toolName)) {
            return null;
        }

        try {
            $tool = $this->registry->getTool($toolName)->tool;
        } catch (ToolNotFoundException) {
            // Not this validator's error to report: the handler will answer
            // with a proper tool-not-found rather than a header complaint.
            return null;
        }

        $properties = $tool->inputSchema['properties'] ?? [];
        if (!\is_array($properties)) {
            return null;
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach ($properties as $property => $definition) {
            if (!\is_array($definition) || !\is_string($definition['x-mcp-header'] ?? null)) {
                continue;
            }

            $error = $this->checkParam(
                self::PARAM_HEADER_PREFIX.$definition['x-mcp-header'],
                $headers,
                \array_key_exists($property, $arguments) ? $arguments[$property] : null,
            );

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkParam(string $headerName, array $headers, mixed $argument): ?string
    {
        $declared = $this->header($headers, $headerName);

        // A null or omitted argument means the client omits the header, and the
        // server must not expect one.
        if (null === $argument) {
            return null;
        }

        if (null === $declared) {
            return \sprintf('Missing required %s header (body carries the mirrored argument).', $headerName);
        }

        $decoded = self::decode($declared);

        if (null === $decoded) {
            return \sprintf('%s header is not a well-formed Base64 wrapper.', $headerName);
        }

        // Numbers travel as their decimal string, booleans as "true"/"false" —
        // so the comparison is between the decoded header and the argument
        // rendered the same way, not between PHP values.
        $expected = match (true) {
            \is_bool($argument) => $argument ? 'true' : 'false',
            \is_scalar($argument) => (string) $argument,
            default => null,
        };

        if (null === $expected) {
            return null;
        }

        if ($decoded !== $expected) {
            return \sprintf('%s header "%s" does not match the body argument "%s".', $headerName, $decoded, $expected);
        }

        return null;
    }

    /**
     * Unwraps a `=?base64?…?=` value, or returns a plain value unchanged.
     *
     * Decoding is strict. PHP's decoder is happy to accept mispadded or
     * out-of-alphabet input and hand back plausible-looking bytes, which would
     * turn a corrupted header into a silent mismatch — or worse, a silent
     * match. Returns null when the wrapper is present but its contents are not
     * valid Base64.
     */
    public static function decode(string $value): ?string
    {
        if (!str_starts_with($value, self::BASE64_PREFIX) || !str_ends_with($value, self::BASE64_SUFFIX)) {
            return $value;
        }

        $encoded = substr($value, \strlen(self::BASE64_PREFIX), -\strlen(self::BASE64_SUFFIX));

        $decoded = base64_decode($encoded, true);

        if (false === $decoded || base64_encode($decoded) !== $encoded) {
            return null;
        }

        return $decoded;
    }

    /**
     * Header names compare case-insensitively, and values carry no meaning in
     * the optional whitespace around them (RFC 9110 §5.5).
     *
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return trim($value);
            }
        }

        return null;
    }
}
