<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Wire\McpHeader;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait ToolSchemaNormalization
{
    /**
     * An out-of-bounds `x-mcp-header` reachable through `properties` makes the whole tool definition invalid.
     */
    private function validateMcpHeaderAnnotations(): void
    {
        if (null !== $reason = McpHeader::checkAnnotations($this->inputSchema)) {
            throw new InvalidArgumentException(\sprintf('Tool "%s" has an invalid "x-mcp-header" annotation: %s', $this->name, $reason));
        }
    }

    /**
     * JSON Schema keywords holding one sub-schema, a map of them, or a list of them.
     *
     * @return array{single: list<string>, map: list<string>, list: list<string>}
     */
    private static function subSchemaKeywords(): array
    {
        return [
            'single' => [
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
            ],
            'map' => [
                '$defs',
                'definitions',
                'dependentSchemas',
                'patternProperties',
                'properties',
            ],
            'list' => [
                'allOf',
                'anyOf',
                'oneOf',
                'prefixItems',
            ],
        ];
    }

    /**
     * Normalize an input schema and refuse members that are not what they claim.
     *
     * `fromArray()` feeds this untrusted wire data, so a member of the wrong
     * type is rejected rather than replaced: quietly turning `properties: "x"`
     * into `{}` would publish a tool contract nobody declared.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function normalizeInputSchema(array $schema): array
    {
        $normalized = self::normalizeSchema($schema);

        if (!isset($normalized['type']) || 'object' !== $normalized['type']) {
            throw new InvalidArgumentException('Tool inputSchema must be a JSON Schema of type "object".');
        }

        if (\array_key_exists('properties', $normalized)
            && !\is_array($normalized['properties'])
            && !$normalized['properties'] instanceof \stdClass
        ) {
            throw new InvalidArgumentException('Tool inputSchema "properties" must be an object.');
        }

        if (\array_key_exists('required', $normalized)
            && null !== $normalized['required']
            && !\is_array($normalized['required'])
        ) {
            throw new InvalidArgumentException('Tool inputSchema "required" must be a list of property names.');
        }

        return $normalized;
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
        foreach (self::subSchemaKeywords()['single'] as $keyword) {
            if (isset($schema[$keyword]) && \is_array($schema[$keyword])) {
                $schema[$keyword] = self::normalizeSubSchema($schema[$keyword]);
            }
        }

        foreach (self::subSchemaKeywords()['map'] as $keyword) {
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

        foreach (self::subSchemaKeywords()['list'] as $keyword) {
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
