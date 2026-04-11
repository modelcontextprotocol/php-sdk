<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Apps;

/**
 * Metadata for the _meta.ui field on a Tool, linking it to a UI resource.
 *
 * @phpstan-type UiToolMetaData array{
 *     resourceUri?: string,
 *     visibility?: string[]
 * }
 */
final class UiToolMeta implements \JsonSerializable
{
    /**
     * @param ?string   $resourceUri the ui:// URI of the linked UI resource
     * @param ?string[] $visibility  who can see/call this tool: 'model', 'app', or both (default: ['model', 'app'])
     */
    public function __construct(
        public readonly ?string $resourceUri = null,
        public readonly ?array $visibility = null,
    ) {
    }

    /**
     * @param UiToolMetaData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            resourceUri: $data['resourceUri'] ?? null,
            visibility: $data['visibility'] ?? null,
        );
    }

    /**
     * @return UiToolMetaData
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if (null !== $this->resourceUri) {
            $data['resourceUri'] = $this->resourceUri;
        }
        if (null !== $this->visibility) {
            $data['visibility'] = $this->visibility;
        }

        return $data;
    }

    /**
     * Returns an array suitable for use as the _meta parameter on a Tool.
     *
     * Usage with explicit registration:
     *
     *     ->addTool($handler, 'my_tool', meta: $uiToolMeta->toMetaArray())
     *
     * Usage with attributes (raw array, since attributes require constant expressions):
     *
     *     #[McpTool(meta: ['ui' => ['resourceUri' => 'ui://my-app', 'visibility' => ['model', 'app']]])]
     *
     * @return array{ui: UiToolMetaData}
     */
    public function toMetaArray(): array
    {
        return ['ui' => $this->jsonSerialize()];
    }
}
