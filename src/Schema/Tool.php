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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Concern\ToolSchemaNormalization;

/**
 * Definition for a tool the client can call.
 *
 * @phpstan-import-type IconData from Icon
 * @phpstan-import-type ToolAnnotationsData from ToolAnnotations
 * @phpstan-import-type ToolExecutionData from ToolExecution
 *
 * @phpstan-type ToolInputSchema array{type: 'object', properties?: array<mixed>|\stdClass, required?: array<mixed>|null}
 * @phpstan-type ToolData array{
 *     name: string,
 *     title?: string,
 *     inputSchema: array<string, mixed>,
 *     description?: string,
 *     annotations?: ToolAnnotationsData,
 *     icons?: list<IconData>,
 *     _meta?: array<string, mixed>,
 *     outputSchema?: array<string, mixed>,
 *     execution?: ToolExecutionData,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class Tool implements \JsonSerializable
{
    use ToolSchemaNormalization;

    /** @var array<string, mixed> */
    public readonly array $inputSchema;

    /** @var array<string, mixed>|null */
    public readonly ?array $outputSchema;

    /**
     * @param string|null               $title        since protocol revision 2025-06-18
     * @param array<string, mixed>      $inputSchema
     * @param ToolAnnotations|null      $annotations  since protocol revision 2025-03-26
     * @param array<Icon>|null          $icons        since protocol revision 2025-11-25
     * @param array<string, mixed>|null $meta         since protocol revision 2025-06-18
     * @param array<string, mixed>|null $outputSchema since protocol revision 2025-06-18
     * @param ToolExecution|null        $execution    only on protocol revision 2025-11-25
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $title,
        array $inputSchema,
        public readonly ?string $description = null,
        public readonly ?ToolAnnotations $annotations = null,
        public readonly ?array $icons = null,
        public readonly ?array $meta = null,
        ?array $outputSchema = null,
        public readonly ?ToolExecution $execution = null,
    ) {
        $this->inputSchema = self::normalizeInputSchema($inputSchema);
        if (null !== $this->icons) {
            foreach ($this->icons as $item) {
                if (!$item instanceof Icon) {
                    throw new InvalidArgumentException('Invalid "icons" item in Tool.');
                }
            }
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('Tool "_meta" must be a map, not a list.');
        }
        $this->outputSchema = null === $outputSchema ? null : self::normalizeSchema($outputSchema);
        $this->validateMcpHeaderAnnotations();
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Missing "name" in Tool data.');
        }
        if (!\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid "name" in Tool data.');
        }
        $name = $data['name'];
        $title = null;
        if (isset($data['title'])) {
            if (!\is_string($data['title'])) {
                throw new InvalidArgumentException('Invalid "title" in Tool data.');
            }
            $title = $data['title'];
        }
        if (!isset($data['inputSchema'])) {
            throw new InvalidArgumentException('Missing "inputSchema" in Tool data.');
        }
        if (!\is_array($data['inputSchema'])) {
            throw new InvalidArgumentException('Invalid "inputSchema" in Tool data.');
        }
        $inputSchema = $data['inputSchema'];
        $description = null;
        if (isset($data['description'])) {
            if (!\is_string($data['description'])) {
                throw new InvalidArgumentException('Invalid "description" in Tool data.');
            }
            $description = $data['description'];
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in Tool data.');
            }
            $annotations = ToolAnnotations::fromArray($data['annotations']);
        }
        $icons = null;
        if (isset($data['icons'])) {
            if (!\is_array($data['icons']) || !array_is_list($data['icons'])) {
                throw new InvalidArgumentException('Invalid "icons" in Tool data.');
            }
            $icons = [];
            foreach ($data['icons'] as $item) {
                if (!\is_array($item)) {
                    throw new InvalidArgumentException('Invalid "icons" in Tool data.');
                }
                $value = Icon::fromArray($item);
                $icons[] = $value;
            }
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in Tool data.');
            }
            $meta = $data['_meta'];
        }
        $outputSchema = null;
        if (isset($data['outputSchema'])) {
            if (!\is_array($data['outputSchema'])) {
                throw new InvalidArgumentException('Invalid "outputSchema" in Tool data.');
            }
            $outputSchema = $data['outputSchema'];
        }
        $execution = null;
        if (isset($data['execution'])) {
            if (!\is_array($data['execution'])) {
                throw new InvalidArgumentException('Invalid "execution" in Tool data.');
            }
            $execution = ToolExecution::fromArray($data['execution']);
        }

        return new self(
            name: $name,
            title: $title,
            inputSchema: $inputSchema,
            description: $description,
            annotations: $annotations,
            icons: $icons,
            meta: $meta,
            outputSchema: $outputSchema,
            execution: $execution,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        $data['inputSchema'] = [] === $this->inputSchema ? new \stdClass() : $this->inputSchema;

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }

        if (null !== $this->icons) {
            $data['icons'] = array_values($this->icons);
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        if (null !== $this->outputSchema) {
            $data['outputSchema'] = [] === $this->outputSchema ? new \stdClass() : $this->outputSchema;
        }

        if (null !== $this->execution) {
            $data['execution'] = $this->execution;
        }

        return $data;
    }
}
