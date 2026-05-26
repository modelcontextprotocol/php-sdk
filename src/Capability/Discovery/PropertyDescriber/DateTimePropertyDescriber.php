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

/**
 * Handles any {@see \DateTimeInterface} implementation: describes it as an
 * ISO-8601 date-time string, parses an incoming string into a date-time
 * instance (honoring a concrete `\DateTime` vs `\DateTimeImmutable` target),
 * and renders a returned instance back to an ISO-8601 string.
 */
final class DateTimePropertyDescriber implements PropertyDescriberInterface, PropertyDenormalizerInterface, PropertyNormalizerInterface
{
    public static function supportedClass(): string
    {
        return \DateTimeInterface::class;
    }

    public function describe(): array
    {
        return ['type' => 'string', 'format' => 'date-time'];
    }

    public function denormalize(mixed $value, string $class): \DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        return \DateTime::class === $class
            ? new \DateTime((string) $value)
            : new \DateTimeImmutable((string) $value);
    }

    public function normalize(object $value): string
    {
        \assert($value instanceof \DateTimeInterface);

        return $value->format(\DateTimeInterface::ATOM);
    }
}
