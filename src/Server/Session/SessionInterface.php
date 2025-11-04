<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Symfony\Component\Uid\Uuid;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface SessionInterface extends \JsonSerializable
{
    /**
     * Get the session ID.
     */
    public function getId(): Uuid;

    /**
     * Save the session.
     */
    public function save(): bool;

    /**
     * Get a specific attribute from the session.
     * Supports dot notation for nested access.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a specific attribute in the session.
     * Supports dot notation for nested access.
     */
    public function set(string $key, mixed $value, bool $overwrite = true): void;

    /**
     * Check if an attribute exists in the session.
     * Supports dot notation for nested access.
     */
    public function has(string $key): bool;

    /**
     * Remove an attribute from the session.
     * Supports dot notation for nested access.
     */
    public function forget(string $key): void;

    /**
     * Remove all attributes from the session.
     */
    public function clear(): void;

    /**
     * Get an attribute's value and then remove it from the session.
     * Supports dot notation for nested access.
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Get all attributes of the session.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Set all attributes of the session, typically for hydration.
     * This will overwrite existing attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function hydrate(array $attributes): void;

    /**
     * Get the session store instance.
     */
    public function getStore(): SessionStoreInterface;
}
