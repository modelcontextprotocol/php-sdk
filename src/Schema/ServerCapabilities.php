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
 * Capabilities that a server may support. Known capabilities are defined here, in this schema, but this is not a closed
 * set: any server can define its own, additional capabilities.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ServerCapabilities implements \JsonSerializable
{
    /**
     * @param ?bool                 $tools                server exposes callable tools
     * @param ?bool                 $toolsListChanged     server supports list changed notifications for tools
     * @param ?bool                 $resources            server provides readable resources
     * @param ?bool                 $resourcesSubscribe   server supports subscribing to changes in the list of resources
     * @param ?bool                 $resourcesListChanged server supports list changed notifications for resources
     * @param ?bool                 $prompts              server provides prompts templates
     * @param ?bool                 $promptsListChanged   server supports list changed notifications for prompts
     * @param ?bool                 $logging              server emits structured log messages
     * @param ?bool                 $completions          Server supports argument autocompletion
     * @param ?array<string, mixed> $experimental         experimental, non-standard features that the server supports
     */
    public function __construct(
        public readonly ?bool $tools = true,
        public readonly ?bool $toolsListChanged = false,
        public readonly ?bool $resources = true,
        public readonly ?bool $resourcesSubscribe = false,
        public readonly ?bool $resourcesListChanged = false,
        public readonly ?bool $prompts = true,
        public readonly ?bool $promptsListChanged = false,
        public readonly ?bool $logging = false,
        public readonly ?bool $completions = false,
        public readonly ?array $experimental = null,
    ) {
    }

    public function withEvents(): self
    {
        return new self(
            tools: $this->tools,
            toolsListChanged: true,
            resources: $this->resources,
            resourcesSubscribe: $this->resourcesSubscribe,
            resourcesListChanged: true,
            prompts: $this->prompts,
            promptsListChanged: true,
            logging: $this->logging,
            completions: $this->completions,
        );
    }

    /**
     * @param array{
     *     logging?: mixed,
     *     completions?: mixed,
     *     prompts?: array{listChanged?: bool}|object,
     *     resources?: array{listChanged?: bool, subscribe?: bool}|object,
     *     tools?: object|array{listChanged?: bool},
     *     experimental?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $loggingEnabled = isset($data['logging']);
        $completionsEnabled = isset($data['completions']);
        $toolsEnabled = isset($data['tools']);
        $promptsEnabled = isset($data['prompts']);
        $resourcesEnabled = isset($data['resources']);

        $promptsListChanged = null;
        if (isset($data['prompts'])) {
            if (\is_array($data['prompts']) && \array_key_exists('listChanged', $data['prompts'])) {
                $promptsListChanged = (bool) $data['prompts']['listChanged'];
            } elseif (\is_object($data['prompts']) && property_exists($data['prompts'], 'listChanged')) {
                $promptsListChanged = (bool) $data['prompts']->listChanged;
            }
        }

        $resourcesSubscribe = null;
        $resourcesListChanged = null;
        if (isset($data['resources'])) {
            if (\is_array($data['resources']) && \array_key_exists('subscribe', $data['resources'])) {
                $resourcesSubscribe = (bool) $data['resources']['subscribe'];
            } elseif (\is_object($data['resources']) && property_exists($data['resources'], 'subscribe')) {
                $resourcesSubscribe = (bool) $data['resources']->subscribe;
            }
            if (\is_array($data['resources']) && \array_key_exists('listChanged', $data['resources'])) {
                $resourcesListChanged = (bool) $data['resources']['listChanged'];
            } elseif (\is_object($data['resources']) && property_exists($data['resources'], 'listChanged')) {
                $resourcesListChanged = (bool) $data['resources']->listChanged;
            }
        }

        $toolsListChanged = null;
        if (isset($data['tools'])) {
            if (\is_array($data['tools']) && \array_key_exists('listChanged', $data['tools'])) {
                $toolsListChanged = (bool) $data['tools']['listChanged'];
            } elseif (\is_object($data['tools']) && property_exists($data['tools'], 'listChanged')) {
                $toolsListChanged = (bool) $data['tools']->listChanged;
            }
        }

        return new self(
            tools: $toolsEnabled,
            toolsListChanged: $toolsListChanged,
            resources: $resourcesEnabled,
            resourcesSubscribe: $resourcesSubscribe,
            resourcesListChanged: $resourcesListChanged,
            prompts: $promptsEnabled,
            promptsListChanged: $promptsListChanged,
            logging: $loggingEnabled,
            completions: $completionsEnabled,
            experimental: $data['experimental'] ?? null,
        );
    }

    /**
     * @return array{
     *     logging?: object,
     *     completions?: object,
     *     prompts?: object,
     *     resources?: object,
     *     tools?: object,
     *     experimental?: object,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if ($this->logging) {
            $data['logging'] = new \stdClass();
        }
        if ($this->completions) {
            $data['completions'] = new \stdClass();
        }

        if ($this->prompts || $this->promptsListChanged) {
            $data['prompts'] = new \stdClass();
            if ($this->promptsListChanged) {
                $data['prompts']->listChanged = $this->promptsListChanged;
            }
        }

        if ($this->resources || $this->resourcesSubscribe || $this->resourcesListChanged) {
            $data['resources'] = new \stdClass();
            if ($this->resourcesSubscribe) {
                $data['resources']->subscribe = $this->resourcesSubscribe;
            }
            if ($this->resourcesListChanged) {
                $data['resources']->listChanged = $this->resourcesListChanged;
            }
        }

        if ($this->tools || $this->toolsListChanged) {
            $data['tools'] = new \stdClass();
            if ($this->toolsListChanged) {
                $data['tools']->listChanged = $this->toolsListChanged;
            }
        }

        if ($this->experimental) {
            $data['experimental'] = (object) $this->experimental;
        }

        return $data;
    }
}
