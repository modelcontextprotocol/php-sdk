<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Stateless;

use Mcp\Schema\Wire\McpHeader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What the client remembers from `tools/list` in order to send correct headers.
 *
 * Mirroring an argument into `Mcp-Param-*` needs the tool's `x-mcp-header`
 * annotations, which only the listing carries — by the time `tools/call` is
 * built the schema is gone unless something kept it.
 *
 * Keeping it here is also where SEP-2243's client-side obligation lands: a tool
 * whose annotations are malformed is dropped rather than called, because the
 * client cannot produce the headers such a tool demands. One bad definition
 * removes that tool and nothing else — a server with ten good tools and one
 * broken one stays usable.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ToolCatalog
{
    /** @var array<string, array<mixed>> tool name to input schema */
    private array $schemas = [];

    /** @var array<string, string> tool name to the reason it was refused */
    private array $rejected = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Records a listing page and returns the tools a caller may actually use.
     *
     * A tool whose annotations are malformed is dropped from the result, which
     * is how the client "rejects" it: it never reaches the caller, so it cannot
     * be called, and the tools listed beside it are untouched.
     *
     * @param array<mixed> $tools raw `tools/list` entries
     *
     * @return list<mixed>
     */
    public function record(array $tools): array
    {
        $usable = [];

        foreach ($tools as $tool) {
            $name = \is_array($tool) ? $tool['name'] ?? null : null;
            $schema = \is_array($tool) ? $tool['inputSchema'] ?? null : null;

            if (!\is_string($name) || !\is_array($schema)) {
                $usable[] = $tool;

                continue;
            }

            unset($this->rejected[$name], $this->schemas[$name]);

            if (null !== $reason = McpHeader::checkAnnotations($schema)) {
                $this->rejected[$name] = $reason;

                $this->logger->warning('Excluding tool with an invalid "x-mcp-header" annotation', [
                    'tool' => $name,
                    'reason' => $reason,
                ]);

                continue;
            }

            $this->schemas[$name] = $schema;
            $usable[] = $tool;
        }

        return $usable;
    }

    /**
     * Whether the client refuses to call this tool.
     *
     * Only a tool that was listed and failed validation is refused; an unknown
     * name is not, since the client may legitimately call a tool it never
     * listed and the server is the authority on whether it exists.
     */
    public function isRejected(string $name): bool
    {
        return isset($this->rejected[$name]);
    }

    public function reasonFor(string $name): ?string
    {
        return $this->rejected[$name] ?? null;
    }

    /**
     * The `Mcp-Param-*` headers a call to $name must carry, given its arguments.
     *
     * An argument that is absent or null contributes no header — the
     * specification reads a missing header as a missing value, so sending an
     * empty one would assert something different.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>
     */
    public function headersFor(string $name, array $arguments): array
    {
        $schema = $this->schemas[$name] ?? null;

        if (null === $schema) {
            return [];
        }

        $headers = [];

        foreach (self::annotations($schema) as $header => $path) {
            $value = self::valueAt($arguments, $path);

            if (null === $value) {
                continue;
            }

            $encoded = McpHeader::encode($value);

            if (null === $encoded) {
                continue;
            }

            $headers[$header] = $encoded;
        }

        return $headers;
    }

    /**
     * Every `x-mcp-header` annotation in $schema, as header name to the property
     * path it mirrors.
     *
     * Only statically reachable properties count, matching the server's reader:
     * a chain through `items`, a composition keyword or a `$ref` cannot be
     * resolved without the instance, so an annotation there is out of bounds.
     *
     * @param array<string, mixed> $schema
     * @param list<string>         $path
     *
     * @return array<string, list<string>>
     */
    private static function annotations(array $schema, array $path = []): array
    {
        $properties = $schema['properties'] ?? null;

        if (!\is_array($properties)) {
            return [];
        }

        $found = [];

        foreach ($properties as $property => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            $here = [...$path, (string) $property];

            if (\is_string($definition['x-mcp-header'] ?? null)) {
                $found[$definition['x-mcp-header']] = $here;
            }

            $found = [...$found, ...self::annotations($definition, $here)];
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $path
     */
    private static function valueAt(array $arguments, array $path): mixed
    {
        $node = $arguments;

        foreach ($path as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
