<?php

declare(strict_types=1);

namespace Mcp\Server\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Session implements SessionInterface
{
    /**
     * @param array<string, mixed> $data Stores all session data.
     * Keys are snake_case by convention for MCP-specific data.
     *
     * Official keys are:
     * - initialized: bool
     * - client_info: array|null
     * - protocol_version: string|null
     * - log_level: string|null
     */
    public function __construct(
        protected SessionStoreInterface $store,
        protected Uuid $id =  new UuidV4(),
        protected array $data = [],
    ) {
        if ($rawData = $this->store->read($this->id)) {
            $this->data = json_decode($rawData, true) ?? [];
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStore(): SessionStoreInterface
    {
        return $this->store;
    }

    public function save(): void
    {
        $this->store->write($this->id, json_encode($this->data, \JSON_THROW_ON_ERROR));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = explode('.', $key);
        $data = $this->data;

        foreach ($key as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
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
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
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
        $data = $this->data;

        foreach ($key as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_object($data) && isset($data->{$segment})) {
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
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
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
        return $this->data;
    }

    public function hydrate(array $attributes): void
    {
        $this->data = $attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->all();
    }
}
