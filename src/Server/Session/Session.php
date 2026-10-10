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
use Symfony\Component\Uid\UuidV4;

/**
 * Saving writes only the keys changed since the last save, onto what the store holds by then:
 * concurrent requests of one session keep each other's changes as long as they change different keys.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Session implements SessionInterface
{
    /**
     * Official keys are:
     * - initialized: bool
     * - client_info: array|null
     * - client_capabilities: array|null
     * - protocol_version: string|null
     * - log_level: string|null
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * Keys changed since the last save, or null once clear() or hydrate() replaced all of them.
     *
     * @var array<string, true>|null
     */
    private ?array $changes = [];

    public function __construct(
        private SessionStoreInterface $store,
        private Uuid $id = new UuidV4(),
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function save(): bool
    {
        $data = $this->readData();

        if (null !== $this->changes) {
            $data = $this->load();
            foreach (array_keys($this->changes) as $key) {
                $this->apply($data, (string) $key);
            }
        }

        if (!$this->store->write($this->id, json_encode($data, \JSON_THROW_ON_ERROR))) {
            return false;
        }

        $this->data = $data;
        $this->changes = [];

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = explode('.', $key);
        $data = $this->readData();

        foreach ($key as $segment) {
            if (\is_array($data) && \array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return $default;
            }
        }

        return $data;
    }

    public function set(string $key, mixed $value, bool $overwrite = true): void
    {
        $segments = explode('.', $key);
        $lastKey = array_pop($segments);
        $this->readData();
        $data = &$this->data;

        foreach ($segments as $segment) {
            if (!isset($data[$segment]) || !\is_array($data[$segment])) {
                $data[$segment] = [];
            }
            $data = &$data[$segment];
        }

        if ($overwrite || !isset($data[$lastKey])) {
            $data[$lastKey] = $value;
            $this->trackChange($key);
        }
    }

    public function has(string $key): bool
    {
        $key = explode('.', $key);
        $data = $this->readData();

        foreach ($key as $segment) {
            if (\is_array($data) && \array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (\is_object($data) && isset($data->{$segment})) {
                $data = $data->{$segment};
            } else {
                return false;
            }
        }

        return true;
    }

    public function forget(string $key): void
    {
        $segments = explode('.', $key);
        $lastKey = array_pop($segments);
        $this->readData();
        $data = &$this->data;

        foreach ($segments as $segment) {
            if (!isset($data[$segment]) || !\is_array($data[$segment])) {
                return;
            }
            $data = &$data[$segment];
        }

        if (\array_key_exists($lastKey, $data)) {
            unset($data[$lastKey]);
            $this->trackChange($key);
        }
    }

    public function clear(): void
    {
        $this->data = [];
        $this->changes = null;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function all(): array
    {
        return $this->readData();
    }

    public function hydrate(array $attributes): void
    {
        $this->data = $attributes;
        $this->changes = null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function readData(): array
    {
        return $this->data ??= $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $rawData = $this->store->read($this->id);

        if (false === $rawData) {
            return [];
        }

        // Empty session should not throw
        $decoded = json_decode($rawData, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function trackChange(string $key): void
    {
        if (null !== $this->changes) {
            $this->changes[$key] = true;
        }
    }

    /**
     * Carries the current value of a changed key over to $data, or removes it there if it was forgotten.
     *
     * @param array<string, mixed> $data
     */
    private function apply(array &$data, string $key): void
    {
        $segments = explode('.', $key);
        $lastKey = array_pop($segments);
        $source = $this->data;
        $target = &$data;

        foreach ($segments as $segment) {
            $source = \is_array($source) ? $source[$segment] ?? null : null;

            if (!isset($target[$segment]) || !\is_array($target[$segment])) {
                if (!\is_array($source)) {
                    return;
                }
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        if (\is_array($source) && \array_key_exists($lastKey, $source)) {
            $target[$lastKey] = $source[$lastKey];
        } else {
            unset($target[$lastKey]);
        }
    }
}
