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
use Mcp\Schema\Wire\McpHeader;

/**
 * Definition for a tool the client can call.
 *
 * @phpstan-import-type ToolAnnotationsData from ToolAnnotations
 * @phpstan-import-type IconData from Icon
 *
 * @phpstan-type ToolInputSchema array{
 *     type: 'object',
 *     properties: array<string, mixed>|\stdClass,
 *     required: string[]|null
 * }
 * @phpstan-type ToolOutputSchema array{
 *     type?: string,
 *     properties?: array<string, mixed>|\stdClass,
 *     required?: string[]|null,
 *     additionalProperties?: bool|array<string, mixed>|\stdClass,
 *     description?: string
 * }
 * @phpstan-type ToolData array{
 *     name: string,
 *     title?: string,
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
     * JSON Schema keywords whose value is a single sub-schema.
     */
    private const SUB_SCHEMA_KEYWORDS = [
        'additionalItems',
        'additionalProperties',
        'contains',
        'else',
        'if',
        'not',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /**
     * JSON Schema keywords whose value maps names to sub-schemas.
     */
    private const SUB_SCHEMA_MAP_KEYWORDS = [
        '$defs',
        'definitions',
        'dependentSchemas',
        'patternProperties',
        'properties',
    ];

    /**
     * JSON Schema keywords whose value is a list of sub-schemas.
     */
    private const SUB_SCHEMA_LIST_KEYWORDS = [
        'allOf',
        'anyOf',
        'oneOf',
        'prefixItems',
    ];

    /**
     * @var ToolInputSchema
     */
    public readonly array $inputSchema;

    /**
     * @var ToolOutputSchema|null
     */
    public readonly ?array $outputSchema;

    /**
     * @param string                $name         the name of the tool
     * @param ?string               $title        Optional human-readable title for display in UI
     * @param ToolInputSchema       $inputSchema  a JSON Schema object (as a PHP array) defining the expected 'arguments' for the tool
     * @param ?string               $description  A human-readable description of the tool.
     *                                            This can be used by clients to improve the LLM's understanding of
     *                                            available tools. It can be thought of like a "hint" to the model.
     * @param ?ToolAnnotations      $annotations  optional additional tool information
     * @param ?Icon[]               $icons        optional icons representing the tool
     * @param ?array<string, mixed> $meta         Optional metadata
     * @param ToolOutputSchema|null $outputSchema Optional JSON Schema (as a PHP array) describing the tool's
     *                                            structuredContent. Unlike $inputSchema its root is unconstrained —
     *                                            it may describe an array, a primitive, or a composition.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $title,
        array $inputSchema,
        public readonly ?string $description,
        public readonly ?ToolAnnotations $annotations,
        public readonly ?array $icons = null,
        public readonly ?array $meta = null,
        ?array $outputSchema = null,
    ) {
        if (!isset($inputSchema['type']) || 'object' !== $inputSchema['type']) {
            throw new InvalidArgumentException('Tool inputSchema must be a JSON Schema of type "object".');
        }

        // Always normalize here so every construction path emits `{}` for empty
        // sub-schemas — not only SchemaGenerator / fromArray.
        $this->inputSchema = self::normalizeSchema($inputSchema);
        $this->outputSchema = null !== $outputSchema ? self::normalizeSchema($outputSchema) : null;

        // An out-of-bounds `x-mcp-header` reachable through `properties` makes
        // the whole tool definition invalid, so it is refused where the tool
        // is defined rather than discovered when a header comparison
        // mysteriously fails.
        if (null !== $reason = McpHeader::checkAnnotations($this->inputSchema)) {
            throw new InvalidArgumentException(\sprintf('Tool "%s" has an invalid "x-mcp-header" annotation: %s', $this->name, $reason));
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

        $outputSchema = null;
        if (isset($data['outputSchema']) && \is_array($data['outputSchema'])) {
            $outputSchema = $data['outputSchema'];
        }

        return new self(
            name: $data['name'],
            title: isset($data['title']) && \is_string($data['title']) ? $data['title'] : null,
            inputSchema: $data['inputSchema'],
            description: isset($data['description']) && \is_string($data['description']) ? $data['description'] : null,
            annotations: isset($data['annotations']) && \is_array($data['annotations']) ? ToolAnnotations::fromArray($data['annotations']) : null,
            icons: isset($data['icons']) && \is_array($data['icons']) ? Icon::listFromArray($data['icons'], 'Tool') : null,
            meta: isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
            outputSchema: $outputSchema,
        );
    }

    /**
     * @return array{
     *     name: string,
     *     title?: string,
     *     inputSchema: ToolInputSchema,
     *     description?: string,
     *     annotations?: ToolAnnotations,
     *     icons?: Icon[],
     *     _meta?: array<string, mixed>,
     *     outputSchema?: ToolOutputSchema|\stdClass
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['name' => $this->name];
        if (null !== $this->title) {
            $data['title'] = $this->title;
        }
        $data['inputSchema'] = $this->inputSchema;
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
            $data['outputSchema'] = [] === $this->outputSchema ? new \stdClass() : $this->outputSchema;
        }

        return $data;
    }

    /**
     * Normalize a JSON Schema so that empty sub-schemas JSON-encode as `{}` rather than `[]`.
     *
     * Once JSON is decoded into associative arrays, PHP cannot tell the empty object `{}`
     * from the empty array `[]` — both are `[]`. Re-encoding then produces `[]`, which is
     * invalid wherever a schema is expected (`properties`, `items`, `additionalProperties`,
     * …), and strict clients reject it. Every empty sub-schema is therefore replaced with a
     * `\stdClass` before serialization.
     *
     * The walk is recursive and covers the schema keywords of draft-07 through 2020-12, so
     * nested object parameters, `$defs`, combinators, and `outputSchema` are all covered —
     * not only the top-level `properties` map.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function normalizeSchema(array $schema): array
    {
        foreach (self::SUB_SCHEMA_KEYWORDS as $keyword) {
            if (isset($schema[$keyword]) && \is_array($schema[$keyword])) {
                $schema[$keyword] = self::normalizeSubSchema($schema[$keyword]);
            }
        }

        foreach (self::SUB_SCHEMA_MAP_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }

            if ([] === $schema[$keyword]) {
                $schema[$keyword] = new \stdClass();
                continue;
            }

            foreach ($schema[$keyword] as $name => $subSchema) {
                if (\is_array($subSchema)) {
                    $schema[$keyword][$name] = self::normalizeSubSchema($subSchema);
                }
            }
        }

        foreach (self::SUB_SCHEMA_LIST_KEYWORDS as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }

            // An empty list stays a list — `allOf: []` is already valid JSON.
            foreach ($schema[$keyword] as $index => $subSchema) {
                if (\is_array($subSchema)) {
                    $schema[$keyword][$index] = self::normalizeSubSchema($subSchema);
                }
            }
        }

        if (isset($schema['items']) && \is_array($schema['items'])) {
            // `items` is a single sub-schema, or a list of them in draft-07 tuple form.
            // An empty array is read as the empty schema `{}` — what an `items: {}` from
            // SchemaGenerator decodes to — rather than as an empty tuple.
            if ([] !== $schema['items'] && array_is_list($schema['items'])) {
                foreach ($schema['items'] as $index => $itemSchema) {
                    if (\is_array($itemSchema)) {
                        $schema['items'][$index] = self::normalizeSubSchema($itemSchema);
                    }
                }
            } else {
                $schema['items'] = self::normalizeSubSchema($schema['items']);
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function normalizeSubSchema(array $schema): array|\stdClass
    {
        return [] === $schema ? new \stdClass() : self::normalizeSchema($schema);
    }
}
