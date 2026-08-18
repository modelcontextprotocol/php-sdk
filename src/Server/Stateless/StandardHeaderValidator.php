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
 * The headers let an intermediary route MCP traffic without parsing the body,
 * which only holds if the two cannot disagree — otherwise a caller could show
 * a gateway one request and the server another.
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
     * When the body carries a name the header must repeat it; when it does not,
     * the server must not demand one.
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

        // Tool and prompt names are only SHOULD-constrained to header-safe
        // characters and a resource URI is not constrained at all, so the
        // client wraps anything unsafe — decode before comparing or every
        // conformant client carrying a non-ASCII subject is refused.
        $decoded = self::decode($declared);

        if (null === $decoded) {
            return \sprintf('%s header is not a well-formed Base64 wrapper.', self::NAME_HEADER);
        }

        if ($decoded !== $expected) {
            return \sprintf('%s header "%s" does not match the body value "%s".', self::NAME_HEADER, $decoded, $expected);
        }

        return null;
    }

    /**
     * The subject of a request, per method. Anything unlisted is exempt.
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
     * Only headers the tool itself declares are checked: an unrecognized
     * `Mcp-Param-*` belongs to somebody else in the chain, and intermediaries
     * are meant to forward what they do not understand.
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
            // The handler reports this properly; a header complaint would not.
            return null;
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        foreach (self::mirroredProperties($tool->inputSchema) as $name => $path) {
            $error = $this->checkParam(
                self::PARAM_HEADER_PREFIX.$name,
                $headers,
                self::valueAt($arguments, $path),
            );

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Every `x-mcp-header` annotation in $schema, as header name to the property
     * path it mirrors.
     *
     * Only statically reachable properties count: the chain from the root must
     * be `properties` keys the whole way. A chain through `items`, a
     * composition keyword, `if`/`then`/`else` or a `$ref` is not extractable
     * without evaluating the instance, so the specification puts an annotation
     * there out of bounds — and this walk simply never reaches one.
     *
     * @param array<string, mixed> $schema
     * @param list<string>         $path
     *
     * @return array<string, list<string>>
     */
    public static function mirroredProperties(array $schema, array $path = []): array
    {
        $properties = $schema['properties'] ?? null;

        if (!\is_array($properties)) {
            return [];
        }

        $found = [];

        foreach ($properties as $property => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $here = [...$path, (string) $property];

            if (\is_string($definition['x-mcp-header'] ?? null)) {
                $found[$definition['x-mcp-header']] = $here;
            }

            $found = [...$found, ...self::mirroredProperties($definition, $here)];
        }

        return $found;
    }

    /**
     * Reads the instance value at an exact property path, or null when the path
     * is not present — which the specification reads as "no header expected".
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $path
     */
    private static function valueAt(array $arguments, array $path): mixed
    {
        $node = $arguments;

        foreach ($path as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkParam(string $headerName, array $headers, mixed $argument): ?string
    {
        $declared = $this->header($headers, $headerName);

        // An omitted argument means an omitted header.
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

        // Numbers travel as decimal strings, booleans as "true"/"false".
        $expected = match (true) {
            \is_bool($argument) => $argument ? 'true' : 'false',
            \is_scalar($argument) => (string) $argument,
            default => null,
        };

        // A non-scalar cannot be mirrored at all, so the annotation on it is
        // the tool definition's problem rather than this request's.
        if (null === $expected) {
            return null;
        }

        // Numerically for numbers, so "42.0" and "42" agree — the spec asks for
        // this, and a client's JSON writer is free to pick either.
        if (is_numeric($argument) && is_numeric($decoded)) {
            return $decoded == $argument
                ? null
                : \sprintf('%s header "%s" does not match the body argument "%s".', $headerName, $decoded, $expected);
        }

        if ($decoded !== $expected) {
            return \sprintf('%s header "%s" does not match the body argument "%s".', $headerName, $decoded, $expected);
        }

        return null;
    }

    /**
     * Unwraps a `=?base64?…?=` value, or returns a plain value unchanged.
     *
     * Strict: PHP's decoder accepts mispadded input and returns plausible
     * bytes, which would turn a corrupted header into a silent mismatch.
     * Null when the wrapper is present but its contents are not valid Base64.
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
     * Case-insensitive name, whitespace-trimmed value (RFC 9110 §5.5).
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
