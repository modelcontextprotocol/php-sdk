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

/**
 * Definition for a tool the client can call.
 *
 * @phpstan-import-type ToolAnnotationsData from ToolAnnotations
 * @phpstan-import-type IconData from Icon
 *
 * @phpstan-type ToolInputSchema array{
 *     type: 'object',
 *     properties: array<string, mixed>|object,
 *     required: string[]
 * }
 * @phpstan-type ToolOutputSchema array{
 *     type: 'object',
 *     properties?: array<string, mixed>,
 *     required?: string[]|null,
 *     additionalProperties?: bool|array<string, mixed>,
 *     description?: string
 * }
 * @phpstan-type ToolData array{
 *     name: string,
 *     inputSchema: ToolInputSchema,
 *     description?: string|null,
 *     annotations?: ToolAnnotationsData,
 *     icons?: IconData[],
 *     _meta?: array<string, mixed>,
 *     outputSchema?: ToolOutputSchema
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Tool implements \JsonSerializable
{
    /**
     * @param string                $name         the name of the tool
     * @param ?string               $description  A human-readable description of the tool.
     *                                            This can be used by clients to improve the LLM's understanding of
     *                                            available tools. It can be thought of like a "hint" to the model.
     * @param ToolInputSchema       $inputSchema  a JSON Schema object (as a PHP array) defining the expected 'arguments' for the tool
     * @param ?ToolAnnotations      $annotations  optional additional tool information
     * @param ?Icon[]               $icons        optional icons representing the tool
     * @param ?array<string, mixed> $meta         Optional metadata
     * @param ToolOutputSchema|null $outputSchema optional JSON Schema object (as a PHP array) defining the expected output structure
     */
    public function __construct(
        public readonly string $name,
        public readonly array $inputSchema,
        public readonly ?string $description,
        public readonly ?ToolAnnotations $annotations,
        public readonly ?array $icons = null,
        public readonly ?array $meta = null,
        public readonly ?array $outputSchema = null,
    ) {
        if (!isset($inputSchema['type']) || 'object' !== $inputSchema['type']) {
            throw new InvalidArgumentException('Tool inputSchema must be a JSON Schema of type "object".');
        }
    }

    /**
     * @param ToolData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in Tool data.');
        }
        if (!isset($data['inputSchema']) || !\is_array($data['inputSchema'])) {
            throw new InvalidArgumentException('Invalid or missing "inputSchema" in Tool data.');
        }
        if (!isset($data['inputSchema']['type']) || 'object' !== $data['inputSchema']['type']) {
            throw new InvalidArgumentException('Tool inputSchema must be of type "object".');
        }
        $inputSchema = self::normalizeSchemaProperties($data['inputSchema']);

        $outputSchema = null;
        if (isset($data['outputSchema']) && \is_array($data['outputSchema'])) {
            if (!isset($data['outputSchema']['type']) || 'object' !== $data['outputSchema']['type']) {
                throw new InvalidArgumentException('Tool outputSchema must be of type "object".');
            }
            $outputSchema = self::normalizeSchemaProperties($data['outputSchema']);
        }

        return new self(
            $data['name'],
            $inputSchema,
            isset($data['description']) && \is_string($data['description']) ? $data['description'] : null,
            isset($data['annotations']) && \is_array($data['annotations']) ? ToolAnnotations::fromArray($data['annotations']) : null,
            isset($data['icons']) && \is_array($data['icons']) ? array_map(Icon::fromArray(...), $data['icons']) : null,
            isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
            $outputSchema,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     inputSchema: ToolInputSchema,
     *     description?: string,
     *     annotations?: ToolAnnotations,
     *     icons?: Icon[],
     *     _meta?: array<string, mixed>,
     *     outputSchema?: ToolOutputSchema
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'inputSchema' => $this->inputSchema,
        ];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }
        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }
        if (null !== $this->icons) {
            $data['icons'] = $this->icons;
        }
        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }
        if (null !== $this->outputSchema) {
            $data['outputSchema'] = $this->outputSchema;
        }

        return $data;
    }

    /**
     * Normalize schema properties: convert an empty properties array to stdClass.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function normalizeSchemaProperties(array $schema): array
    {
        if (isset($schema['properties']) && \is_array($schema['properties']) && empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }
}
