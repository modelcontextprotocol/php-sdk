<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

/**
 * Refuses a JSON Schema that would be expensive or unsafe to validate.
 *
 * Two hazards, both called out by the specification's JSON Schema rules:
 *
 * **External `$ref`.** A `$ref` may name an absolute URI, and dereferencing one
 * turns every schema into a request the sender chose — an SSRF primitive
 * pointed at whatever the validating host can reach. Implementations MUST NOT
 * dereference network references automatically. This SDK does not resolve
 * anything outside the document at all, so the guard's job is to say so up
 * front rather than let it surface as "unresolved reference", which reads like
 * an internal fault and hides why the schema was refused.
 *
 * **Composition blow-up.** `anyOf`/`oneOf`/`allOf` and `$defs` compose
 * multiplicatively: sixteen nested two-branch `anyOf`s are a few kilobytes on
 * the wire and 65 536 subschema evaluations to validate, and the same shape
 * written with `$defs` and `$ref` is a few hundred bytes. Opis's
 * `setMaxErrors()` does not help — measured, it caps what is reported, not what
 * is walked — so the bound has to be structural and applied before validation.
 *
 * The estimate resolves same-document `$ref`s and sums branch costs, which is
 * what makes the exponential visible while the schema is still small. Targets
 * are memoised, so the cheap-on-the-wire `$defs` form costs the same to judge
 * as the expanded one. A cycle counts as a single step: recursive schemas are
 * legitimate and terminate on real data.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SchemaComplexityGuard
{
    /**
     * Keywords whose value is a map of name to subschema, rather than a
     * subschema itself. Their keys are user-chosen and must not be read as
     * keywords.
     */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /**
     * @param int $maxDepth      how deeply subschemas may nest
     * @param int $maxSubschemas ceiling on estimated subschema evaluations
     * @param int $maxProperties ceiling on named subschemas in any one map
     */
    public function __construct(
        private readonly int $maxDepth = 32,
        private readonly int $maxSubschemas = 10_000,
        private readonly int $maxProperties = 1_000,
    ) {
    }

    /**
     * @param array<string, mixed>|object $schema
     *
     * @return string|null the reason to refuse, or null when the schema is within bounds
     */
    public function check(array|object $schema): ?string
    {
        try {
            $root = self::toArray($schema);
        } catch (\JsonException $e) {
            return \sprintf('Schema could not be decoded as JSON: %s', $e->getMessage());
        }

        if (null !== $reason = $this->findExternalRef($root, 0)) {
            return $reason;
        }

        try {
            $this->cost($root, $root, [], 0, new \stdClass());
        } catch (\OverflowException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function findExternalRef(array $node, int $depth): ?string
    {
        if ($depth > $this->maxDepth) {
            return \sprintf('Schema nests deeper than the %d levels this validator accepts.', $this->maxDepth);
        }

        foreach ($node as $key => $value) {
            if ('$ref' === $key && \is_string($value) && !str_starts_with($value, '#')) {
                return \sprintf('Schema contains the non-local reference "%s"; only same-document "#" references are resolved.', $value);
            }

            if (\is_array($value) && null !== $reason = $this->findExternalRef($value, $depth + 1)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Estimated subschema evaluations $node can trigger.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string>         $stack pointers currently being resolved, so a cycle is not followed twice
     * @param \stdClass            $memo  cost per already-resolved pointer
     *
     * @throws \OverflowException as soon as the running estimate passes the ceiling
     */
    private function cost(array $node, array $root, array $stack, int $depth, object $memo): int
    {
        if ($depth > $this->maxDepth) {
            throw new \OverflowException(\sprintf('Schema nests deeper than the %d levels this validator accepts.', $this->maxDepth));
        }

        if (isset($node['$ref']) && \is_string($node['$ref'])) {
            return $this->refCost($node['$ref'], $root, $stack, $depth, $memo);
        }

        $total = 1;

        foreach ($node as $key => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if (\in_array($key, self::SCHEMA_MAPS, true)) {
                if (\count($value) > $this->maxProperties) {
                    throw new \OverflowException(\sprintf('Schema declares more than %d entries under "%s".', $this->maxProperties, $key));
                }

                foreach ($value as $subschema) {
                    if (\is_array($subschema)) {
                        $total += $this->cost($subschema, $root, $stack, $depth + 1, $memo);
                    }
                }

                $this->assertWithinBudget($total);

                continue;
            }

            // Everything else holding an array is either a subschema or a list
            // of them; a keyword holding plain data contributes nothing but is
            // harmless to walk, since only its own nesting is counted.
            if (array_is_list($value)) {
                foreach ($value as $subschema) {
                    if (\is_array($subschema)) {
                        $total += $this->cost($subschema, $root, $stack, $depth + 1, $memo);
                    }
                }
            } else {
                $total += $this->cost($value, $root, $stack, $depth + 1, $memo);
            }

            $this->assertWithinBudget($total);
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string>         $stack
     */
    private function refCost(string $pointer, array $root, array $stack, int $depth, object $memo): int
    {
        // A back-edge: recursive schemas are legitimate, and how far one
        // unrolls is decided by the data, not the schema.
        if (\in_array($pointer, $stack, true)) {
            return 1;
        }

        if (isset($memo->{$pointer})) {
            return $memo->{$pointer};
        }

        $target = self::resolve($pointer, $root);

        if (null === $target) {
            // Unresolvable same-document pointers are the validator's business
            // to report; nothing here can be expensive.
            return 1;
        }

        // Depth is lexical nesting, which following a reference is not: a long
        // chain of `$defs` referring to one another is flat and cheap. What
        // bounds this is the subschema budget and the cycle check above, and
        // the pointer set is finite, so the recursion is too.
        $cost = $this->cost($target, $root, [...$stack, $pointer], $depth, $memo);
        $memo->{$pointer} = $cost;

        return $cost;
    }

    /**
     * Resolves a same-document JSON pointer (`#`, `#/$defs/name`).
     *
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>|null
     */
    private static function resolve(string $pointer, array $root): ?array
    {
        if ('#' === $pointer) {
            return $root;
        }

        if (!str_starts_with($pointer, '#/')) {
            return null;
        }

        $node = $root;

        foreach (explode('/', substr($pointer, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return \is_array($node) ? $node : null;
    }

    private function assertWithinBudget(int $total): void
    {
        if ($total > $this->maxSubschemas) {
            throw new \OverflowException(\sprintf('Schema composes more than %d subschemas, which this validator refuses to walk.', $this->maxSubschemas));
        }
    }

    /**
     * @param array<string, mixed>|object $schema
     *
     * @return array<string, mixed>
     */
    private static function toArray(array|object $schema): array
    {
        if (\is_array($schema)) {
            return $schema;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($schema, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
