<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Mcp\Exception\InvalidArgumentException;

/**
 * Declares which scopes a request needs, enforced by
 * {@see \Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware}.
 *
 * A request needs the default scopes, plus those of its JSON-RPC method, plus —
 * for `tools/call` — those of the tool it calls. A batch needs the union of its
 * messages. A token lacking any of them is answered with `403 insufficient_scope`
 * naming every scope the request needs, so the client can step up in one go.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization#scope-challenge-handling
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ScopePolicy
{
    /** @var list<string> */
    private readonly array $default;

    /** @var array<string, list<string>> */
    private readonly array $methods;

    /** @var array<string, list<string>> */
    private readonly array $tools;

    /** @var array<string, list<string>> */
    private readonly array $implies;

    /**
     * @param list<string>                $default scopes every request needs
     * @param array<string, list<string>> $methods scopes per JSON-RPC method, e.g. `['tools/call' => ['mcp:tools']]`
     * @param array<string, list<string>> $tools   scopes per tool name, e.g. `['delete_file' => ['files:write']]`
     * @param array<string, list<string>> $implies scope hierarchy: a granted key also grants its listed scopes, e.g. `['files:admin' => ['files:write']]`
     */
    public function __construct(
        array $default = [],
        array $methods = [],
        array $tools = [],
        array $implies = [],
    ) {
        $this->default = self::normalize($default);
        $this->methods = self::normalizeMap($methods);
        $this->tools = self::normalizeMap($tools);
        $this->implies = self::normalizeMap($implies);
    }

    /**
     * Whether the policy depends on the request body, i.e. on methods or tools.
     */
    public function inspectsBody(): bool
    {
        return [] !== $this->methods || [] !== $this->tools;
    }

    /**
     * Scopes the decoded JSON-RPC payload needs; null payload means only the defaults.
     *
     * @return list<string>
     */
    public function requiredFor(mixed $payload = null): array
    {
        // Keyed by scope to dedupe on insert, read back by value: keys would turn "42" into an int.
        $required = array_combine($this->default, $this->default);

        $messages = \is_array($payload) && array_is_list($payload) ? $payload : [$payload];
        foreach ($messages as $message) {
            if (!\is_array($message) || !\is_string($method = $message['method'] ?? null)) {
                continue;
            }

            foreach ($this->methods[$method] ?? [] as $scope) {
                $required[$scope] = $scope;
            }

            $name = $message['params']['name'] ?? null;
            if ('tools/call' === $method && \is_string($name)) {
                foreach ($this->tools[$name] ?? [] as $scope) {
                    $required[$scope] = $scope;
                }
            }
        }

        return array_values($required);
    }

    /**
     * Whether the granted scopes cover the required ones, honoring the hierarchy.
     *
     * @param list<string> $required
     * @param list<string> $granted
     */
    public function isSatisfied(array $required, array $granted): bool
    {
        return [] === array_diff($required, $this->expand($granted));
    }

    /**
     * The granted scopes plus all scopes they imply through the hierarchy.
     *
     * @param list<string> $granted
     *
     * @return list<string>
     */
    public function expand(array $granted): array
    {
        $effective = [];
        $pending = $granted;

        while (null !== $scope = array_pop($pending)) {
            if (isset($effective[$scope])) {
                continue;
            }

            $effective[$scope] = $scope;
            array_push($pending, ...($this->implies[$scope] ?? []));
        }

        return array_values($effective);
    }

    /**
     * @param array<mixed> $scopes
     *
     * @return list<string>
     */
    private static function normalize(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope) || '' === trim($scope) || preg_match('/[\s"\\\\]/', $scope)) {
                throw new InvalidArgumentException('Scopes must be non-empty strings without whitespace, quotes or backslashes.');
            }

            $normalized[$scope] = $scope;
        }

        return array_values($normalized);
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, list<string>>
     */
    private static function normalizeMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $key => $scopes) {
            if (!\is_string($key) || !\is_array($scopes)) {
                throw new InvalidArgumentException('Scope maps must map a name to a list of scopes.');
            }

            $normalized[$key] = self::normalize($scopes);
        }

        return $normalized;
    }
}
