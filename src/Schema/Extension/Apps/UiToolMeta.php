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

use Mcp\Exception\InvalidArgumentException;

/**
 * Metadata for the _meta.ui field on a Tool, linking it to a UI resource.
 *
 * @phpstan-type UiToolMetaData array{
 *     resourceUri?: string,
 *     visibility?: string[]
 * }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class UiToolMeta implements \JsonSerializable
{
    /**
     * @param ?string               $resourceUri the ui:// URI of the linked UI resource
     * @param ?list<ToolVisibility> $visibility  who can see/call this tool; when omitted the host
     *                                           defaults to both {@see ToolVisibility::Model} and {@see ToolVisibility::App}
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
        if (isset($data['resourceUri']) && !\is_string($data['resourceUri'])) {
            throw new InvalidArgumentException('Invalid "resourceUri" in UiToolMeta data.');
        }
        if (isset($data['visibility']) && !\is_array($data['visibility'])) {
            throw new InvalidArgumentException('Invalid "visibility" in UiToolMeta data; expected an array.');
        }

        return new self(
            resourceUri: $data['resourceUri'] ?? null,
            visibility: isset($data['visibility']) ? array_map(
                static function (mixed $entry): ToolVisibility {
                    if (!\is_string($entry) || null === $case = ToolVisibility::tryFrom($entry)) {
                        throw new InvalidArgumentException('Each entry in "visibility" of UiToolMeta data must be a valid tool visibility.');
                    }

                    return $case;
                },
                $data['visibility'],
            ) : null,
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
            $data['visibility'] = array_map(static fn (ToolVisibility $v): string => $v->value, $this->visibility);
        }

        return $data;
    }
}
