<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

/**
 * Lightweight value object to access request metadata in handlers.
 *
 * Example usage in a tool handler:
 *   function exampleAction(string $input, Metadata $meta): array {
 *       $schema = $meta->get('securitySchema');
 *       return ['result' => 'ok', 'securitySchema' => $schema];
 *   }
 *
 * The SDK will inject an instance automatically when the parameter is type-hinted
 * with this class. If no metadata is present on the request and the parameter
 * allows null, null will be passed; otherwise, an internal error will be thrown.
 */
final class Metadata
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
