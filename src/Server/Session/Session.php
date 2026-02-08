<?php

declare(strict_types=1);

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
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Session implements SessionInterface
{
    /**
     * Official keys are:
     * - initialized: bool
     * - client_info: array|null
     * - protocol_version: string|null
     * - log_level: string|null
     *
     * @var array<string, mixed>
     */
    private array $data;

    public function __construct(
        private SessionStoreInterface $store,
        private Uuid $id = new UuidV4(),
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStore(): SessionStoreInterface
    {
        return $this->store;
    }

    public function save(): bool
    {
        return $this->store->write($this->id, json_encode($this->data, \JSON_THROW_ON_ERROR));
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
        $this->readData();
        $data = &$this->data;

        while (\count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !\is_array($data[$segment])) {
                $data[$segment] = [];
            }
            $data = &$data[$segment];
        }

        $lastKey = array_shift($segments);
        if ($overwrite || !isset($data[$lastKey])) {
            $data[$lastKey] = $value;
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
        $this->readData();
        $data = &$this->data;

        while (\count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !\is_array($data[$segment])) {
                $data[$segment] = [];
            }
            $data = &$data[$segment];
        }

        $lastKey = array_shift($segments);
        if (isset($data[$lastKey])) {
            unset($data[$lastKey]);
        }
    }

    public function clear(): void
    {
        $this->data = [];
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
        if (isset($this->data)) {
            return $this->data;
        }

        $rawData = $this->store->read($this->id);

        if (false === $rawData) {
            return $this->data = [];
        }

        return $this->data = json_decode($rawData, true, flags: \JSON_THROW_ON_ERROR);
    }
}
