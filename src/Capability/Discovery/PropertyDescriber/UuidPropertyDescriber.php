<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery\PropertyDescriber;

use Mcp\Capability\Discovery\PropertyDenormalizerInterface;
use Mcp\Capability\Discovery\PropertyDescriberInterface;
use Mcp\Capability\Discovery\PropertyNormalizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles Symfony UID {@see Uuid} (and subclasses like `UuidV4`, `UuidV7`):
 * describes it as a uuid-format string, upcasts an incoming string into a
 * {@see Uuid} instance, and renders a returned instance back to its RFC 4122
 * string form.
 */
final class UuidPropertyDescriber implements PropertyDescriberInterface, PropertyDenormalizerInterface, PropertyNormalizerInterface
{
    public static function supportedClass(): string
    {
        return Uuid::class;
    }

    public function describe(): array
    {
        return ['type' => 'string', 'format' => 'uuid'];
    }

    public function denormalize(mixed $value, string $class): Uuid
    {
        if ($value instanceof Uuid) {
            return $value;
        }

        // Uuid::fromString detects the version and returns the matching subtype.
        return Uuid::fromString((string) $value);
    }

    public function normalize(object $value): string
    {
        \assert($value instanceof Uuid);

        return (string) $value;
    }
}
