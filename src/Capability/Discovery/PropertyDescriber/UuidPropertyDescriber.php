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
use Symfony\Component\Uid\Uuid;

/**
 * Describes Symfony UID {@see Uuid} (and subclasses like `UuidV4`, `UuidV7`)
 * as a uuid-format string.
 */
final class UuidPropertyDescriber implements PropertyDescriberInterface
{
    public function describe(string $className): ?array
    {
        if (!is_a($className, Uuid::class, true)) {
            return null;
        }

        return ['type' => 'string', 'format' => 'uuid'];
    }
}
