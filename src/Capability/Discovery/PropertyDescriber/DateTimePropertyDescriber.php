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

use Mcp\Capability\Discovery\PropertyDescriberInterface;

/**
 * Describes any {@see \DateTimeInterface} implementation as an ISO-8601
 * date-time string.
 */
final class DateTimePropertyDescriber implements PropertyDescriberInterface
{
    public function describe(string $className): ?array
    {
        if (!is_a($className, \DateTimeInterface::class, true)) {
            return null;
        }

        return ['type' => 'string', 'format' => 'date-time'];
    }
}
